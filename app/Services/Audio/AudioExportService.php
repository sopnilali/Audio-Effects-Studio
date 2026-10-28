<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use App\Jobs\ExportAudioJob;
use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioExportService
{
    public function __construct(
        private readonly AudioProcessingService $processing,
        private readonly AudioEffectsService $effects,
        private readonly FfmpegRunner $ffmpeg,
        private readonly AudioStorageService $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{job: AudioProcessingJob, reused: bool}
     */
    public function create(AudioFile $audioFile, User $user, string $format, array $settings): array
    {
        $format = strtolower($format);

        if (! in_array($format, config('audio.export_formats', ['wav', 'mp3']), true)) {
            throw AudioProcessingException::exportFailed('Unsupported export format.');
        }

        if (! $audioFile->isReady()) {
            throw new AudioProcessingException(
                'The audio file is not ready for export.',
                'This audio file is still being prepared. Please wait a moment and try again.',
            );
        }

        $environment = $this->ffmpeg->environment();

        if (! $environment['ok']) {
            throw AudioProcessingException::ffmpegUnavailable();
        }

        if ($format === 'mp3' && ! $environment['mp3_encoder']) {
            throw AudioProcessingException::exportFailed('The MP3 encoder is unavailable on this server.');
        }

        $normalized = $this->effects->normalize($settings);

        if ($normalized['reverb'] > 0 && ! in_array('afir', $environment['filters'], true)) {
            throw AudioProcessingException::exportFailed('The reverb filter is unavailable on this server.');
        }

        $active = AudioProcessingJob::query()
            ->where('audio_file_id', $audioFile->id)
            ->where('job_type', AudioProcessingJob::TYPE_EXPORT)
            ->where('format', $format)
            ->whereIn('status', [AudioProcessingJob::STATUS_PENDING, AudioProcessingJob::STATUS_PROCESSING])
            ->latest()
            ->first();

        // Reuse an in-flight export only when it is rendering the exact same
        // effects. Otherwise a user could change a slider, request a download,
        // and receive the previous job's settings.
        if ($active && $this->sameEffects($active->settings ?? [], $normalized)) {
            return ['job' => $active, 'reused' => true];
        }

        $running = AudioProcessingJob::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [AudioProcessingJob::STATUS_PENDING, AudioProcessingJob::STATUS_PROCESSING])
            ->count();

        $limit = (int) config('audio.max_concurrent_jobs_per_user', 2);

        if ($limit > 0 && $running >= $limit) {
            throw new AudioProcessingException(
                'Too many audio jobs are running.',
                'You already have the maximum number of audio jobs running. Please wait for them to finish.',
            );
        }

        $outputRelativePath = 'exports/'.$user->id.'/'.Str::uuid().'.'.$format;

        $job = AudioProcessingJob::create([
            'audio_file_id' => $audioFile->id,
            'user_id' => $user->id,
            'job_type' => AudioProcessingJob::TYPE_EXPORT,
            'input_path' => $audioFile->storage_path,
            'output_path' => $outputRelativePath,
            'format' => $format,
            'settings' => $normalized,
            'status' => AudioProcessingJob::STATUS_PENDING,
            'progress' => 0,
        ]);

        if ((bool) config('audio.sync_exports', false)) {
            $this->process($job);

            return ['job' => $job->fresh(), 'reused' => false];
        }

        ExportAudioJob::dispatch($job->id);

        return ['job' => $job->fresh(), 'reused' => false];
    }

    /**
     * Compare the effect values that actually change the rendered audio.
     * The preset label is cosmetic and must not prevent job reuse.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sameEffects(array $left, array $right): bool
    {
        $left = $this->effects->normalize($left);
        $right = $this->effects->normalize($right);

        foreach (['speed', 'pitch_follows_speed', 'pitch_enabled', 'pitch_multiplier', 'reverb', 'bass_boost'] as $key) {
            if ($left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    public function process(AudioProcessingJob $job): void
    {
        $this->processing->process($job);
    }

    public function cancel(AudioProcessingJob $job): AudioProcessingJob
    {
        if (! $job->isPending()) {
            throw new AudioProcessingException(
                'This job cannot be cancelled safely.',
                'This job has already started and can no longer be cancelled.',
            );
        }

        $job->forceFill([
            'status' => AudioProcessingJob::STATUS_CANCELLED,
            'completed_at' => now(),
            'error_message' => 'Cancelled by the user.',
        ])->save();

        return $job->fresh();
    }

    public function downloadResponse(AudioProcessingJob $job): BinaryFileResponse|StreamedResponse
    {
        if (! $job->isCompleted() || ! $job->outputExists()) {
            throw new AudioProcessingException(
                'The processed file is not available.',
                'This export is not ready or has expired.',
            );
        }

        $mime = $job->format === 'mp3' ? 'audio/mpeg' : 'audio/wav';

        return $this->storage->downloadResponse((string) $job->output_path, $job->downloadName(), $mime);
    }

    public function cleanupExpired(): array
    {
        $retention = (int) config('audio.output_retention_hours', 24);
        $cutoff = now()->subHours(max(1, $retention));
        $deletedJobs = 0;
        $deletedFiles = 0;

        AudioProcessingJob::query()
            ->whereIn('status', [
                AudioProcessingJob::STATUS_COMPLETED,
                AudioProcessingJob::STATUS_FAILED,
                AudioProcessingJob::STATUS_CANCELLED,
            ])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$deletedJobs, &$deletedFiles): void {
                foreach ($jobs as $job) {
                    if ($job->output_path && $this->storage->exists($job->output_path)) {
                        $this->storage->delete($job->output_path);
                        $deletedFiles++;
                    }

                    $job->delete();
                    $deletedJobs++;
                }
            });

        $temporaryDirectory = (string) config('audio.temp_directory', storage_path('app/private/temporary'));
        $tempCutoff = now()->subHours(max(1, (int) config('audio.temp_retention_hours', 24)))->getTimestamp();

        if (is_dir($temporaryDirectory)) {
            foreach (glob($temporaryDirectory.'/*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $tempCutoff) {
                    @unlink($file);
                }
            }
        }

        return ['jobs' => $deletedJobs, 'files' => $deletedFiles];
    }
}
