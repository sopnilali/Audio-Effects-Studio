<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use App\Jobs\ProcessAudioJob;
use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AudioFileService
{
    public function __construct(
        private readonly AudioMetadataService $metadata,
        private readonly AudioPlaybackService $playback,
        private readonly AudioWaveformService $waveform,
        private readonly AudioEffectsService $effects,
    ) {}

    public function store(UploadedFile $uploadedFile, User $user): AudioFile
    {
        $extension = $this->resolveExtension($uploadedFile);

        if (! $this->metadata->isSupportedExtension($extension)) {
            throw AudioProcessingException::unsupportedFormat($extension);
        }

        $this->assertQuota($user, (int) $uploadedFile->getSize());

        $originalName = $this->sanitizeOriginalName($uploadedFile->getClientOriginalName(), $extension);
        $uuid = (string) Str::uuid();
        $directory = 'audio/'.$user->id;
        $filename = $uuid.'.'.$extension;
        $relativePath = $uploadedFile->storeAs($directory, $filename, 'local');

        if (! $relativePath) {
            throw new AudioProcessingException('Unable to store the uploaded file.', 'The file could not be saved. Please try again.');
        }

        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $probed = $this->metadata->probe($absolutePath);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($relativePath);

            if ($exception instanceof AudioProcessingException) {
                throw $exception;
            }

            throw AudioProcessingException::metadataFailed($exception->getMessage());
        }

        $audioFile = AudioFile::create([
            'user_id' => $user->id,
            'original_name' => $originalName,
            'display_name' => $originalName,
            'storage_path' => $relativePath,
            'mime_type' => $probed['mime_type'],
            'extension' => $extension,
            'file_size' => (int) $uploadedFile->getSize(),
            'duration_seconds' => $probed['duration'],
            'sample_rate' => $probed['sample_rate'],
            'channels' => $probed['channels'],
            'bit_rate' => $probed['bit_rate'],
            'codec' => $probed['codec'],
            'container' => $probed['container'],
            'status' => AudioFile::STATUS_PROCESSING,
            'effects' => $this->effects->defaults(),
            'last_accessed_at' => now(),
        ]);

        $threshold = (int) config('audio.large_upload_threshold_bytes', 25 * 1024 * 1024);

        if ($audioFile->file_size > $threshold || $audioFile->duration_seconds > 900) {
            $job = AudioProcessingJob::create([
                'audio_file_id' => $audioFile->id,
                'user_id' => $user->id,
                'job_type' => AudioProcessingJob::TYPE_PREVIEW,
                'input_path' => $audioFile->storage_path,
                'settings' => [],
                'status' => AudioProcessingJob::STATUS_PENDING,
            ]);

            ProcessAudioJob::dispatch($job->id);

            return $audioFile->fresh();
        }

        try {
            $this->prepare($audioFile);
        } catch (Throwable $exception) {
            $this->deleteStoredFiles($audioFile);
            $audioFile->forceDelete();

            if ($exception instanceof AudioProcessingException) {
                throw $exception;
            }

            throw AudioProcessingException::processingFailed($exception->getMessage());
        }

        return $audioFile->fresh();
    }

    public function prepare(AudioFile $audioFile): AudioFile
    {
        $audioFile->forceFill(['status' => AudioFile::STATUS_PROCESSING, 'error_message' => null])->save();

        $previewRelative = 'previews/'.$audioFile->user_id.'/'.pathinfo($audioFile->storage_path, PATHINFO_FILENAME).'.mp3';
        $sourceAbsolute = Storage::disk('local')->path($audioFile->storage_path);

        try {
            if (! is_file($sourceAbsolute)) {
                throw AudioProcessingException::processingFailed('The uploaded file is missing.');
            }

            $this->playback->generatePreview($sourceAbsolute, $previewRelative);

            $waveform = $this->waveform->generate(
                $sourceAbsolute,
                (float) $audioFile->duration_seconds,
                (int) config('audio.waveform_peaks', 720),
            );

            $audioFile->forceFill([
                'preview_path' => $previewRelative,
                'waveform' => $waveform,
                'status' => AudioFile::STATUS_READY,
                'error_message' => null,
                'last_accessed_at' => now(),
            ])->save();

            return $audioFile->fresh();
        } catch (Throwable $exception) {
            $this->playback->deletePreview($previewRelative);

            $safeMessage = $exception instanceof AudioProcessingException
                ? $exception->safeMessage()
                : 'The audio preview could not be prepared.';

            $audioFile->forceFill([
                'status' => AudioFile::STATUS_FAILED,
                'error_message' => $safeMessage,
            ])->save();

            Log::error('Audio preparation failed', [
                'audio_file_id' => $audioFile->id,
                'message' => $exception->getMessage(),
            ]);

            if ($exception instanceof AudioProcessingException) {
                throw $exception;
            }

            throw AudioProcessingException::processingFailed($exception->getMessage());
        }
    }

    public function rename(AudioFile $audioFile, string $newName): AudioFile
    {
        $extension = $audioFile->extension;
        $clean = $this->sanitizeDisplayName($newName, $extension);

        $audioFile->forceFill(['display_name' => $clean])->save();

        return $audioFile->fresh();
    }

    public function delete(AudioFile $audioFile): void
    {
        $audioFile->forceFill(['status' => AudioFile::STATUS_DELETING])->save();

        DB::transaction(function () use ($audioFile): void {
            foreach ($audioFile->processingJobs as $job) {
                if ($job->output_path && Storage::disk('local')->exists($job->output_path)) {
                    Storage::disk('local')->delete($job->output_path);
                }

                $job->delete();
            }

            $this->deleteStoredFiles($audioFile);
            $audioFile->forceDelete();
        });
    }

    public function clear(User $user): int
    {
        $count = 0;

        AudioFile::query()->ownedBy($user)->orderBy('id')->each(function (AudioFile $audioFile) use (&$count): void {
            $this->delete($audioFile);
            $count++;
        });

        return $count;
    }

    public function sanitizeOriginalName(string $name, ?string $extension = null): string
    {
        $name = str_replace(['\\', '/'], ' ', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name);
        $name = ltrim($name, '.');

        if ($name === '') {
            $name = 'audio'.($extension ? '.'.$extension : '');
        }

        return mb_substr($name, 0, 255);
    }

    public function sanitizeDisplayName(string $name, string $extension): string
    {
        $name = str_replace(['\\', '/'], ' ', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name);
        $name = ltrim($name, '.');

        if ($name === '') {
            return 'audio.'.$extension;
        }

        $givenExtension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ($givenExtension !== '') {
            $name = preg_replace('/\.'.preg_quote($givenExtension, '/').'$/i', '', $name) ?? $name;
        }

        $name = rtrim(trim($name), '.');
        $name = mb_substr($name !== '' ? $name : 'audio', 0, 200);

        return $name.'.'.$extension;
    }

    public function deleteStoredFiles(AudioFile $audioFile): void
    {
        foreach (array_filter([$audioFile->storage_path, $audioFile->preview_path]) as $path) {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function resolveExtension(UploadedFile $uploadedFile): string
    {
        $extension = strtolower($uploadedFile->getClientOriginalExtension());

        if ($extension === '') {
            $extension = strtolower((string) $uploadedFile->guessExtension());
        }

        return match ($extension) {
            'mpga', 'mpeg' => 'mp3',
            'wave' => 'wav',
            'mp4' => 'm4a',
            default => $extension,
        };
    }

    private function assertQuota(User $user, int $incomingBytes): void
    {
        $maxFiles = (int) config('audio.max_files_per_user', 100);
        $maxBytes = (int) config('audio.storage_quota_mb', 2048) * 1024 * 1024;

        $count = AudioFile::query()->ownedBy($user)->count();
        $usedBytes = (int) AudioFile::query()->ownedBy($user)->sum('file_size');

        if ($maxFiles > 0 && $count >= $maxFiles) {
            throw new AudioProcessingException(
                'The user audio quota is full.',
                "Your library is limited to {$maxFiles} audio files. Delete a file before uploading another.",
            );
        }

        if ($maxBytes > 0 && ($usedBytes + $incomingBytes) > $maxBytes) {
            throw new AudioProcessingException(
                'The user storage quota would be exceeded.',
                'Your storage quota would be exceeded by this upload.',
            );
        }
    }
}
