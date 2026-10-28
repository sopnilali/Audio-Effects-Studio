<?php

namespace Tests\Concerns;

use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Models\User;
use App\Services\Audio\FfmpegRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait InteractsWithAudio
{
    protected function requireFfmpeg(): void
    {
        if (! app(FfmpegRunner::class)->environment()['ok']) {
            $this->markTestSkipped('FFmpeg and FFprobe are required for this test.');
        }
    }

    /**
     * A tiny, valid 16-bit mono PCM WAV generated without external tools.
     */
    protected function wavBytes(float $seconds = 1.0, int $sampleRate = 8000): string
    {
        $sampleCount = max(1, (int) round($seconds * $sampleRate));
        $pcm = '';

        for ($i = 0; $i < $sampleCount; $i++) {
            $sample = (int) round(sin(2 * M_PI * 440 * ($i / $sampleRate)) * 0.5 * 32767);
            $pcm .= pack('v', $sample & 0xFFFF);
        }

        $dataLength = strlen($pcm);
        $byteRate = $sampleRate * 2;

        return 'RIFF'
            .pack('V', 36 + $dataLength)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', 1)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', 2)
            .pack('v', 16)
            .'data'
            .pack('V', $dataLength)
            .$pcm;
    }

    protected function audioUpload(string $name = 'tone.wav', float $seconds = 1.0): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'audio-test-');

        if ($path === false) {
            $this->fail('Unable to create a temporary audio fixture.');
        }

        file_put_contents($path, $this->wavBytes($seconds));

        return new UploadedFile($path, $name, 'audio/wav', null, true);
    }

    /**
     * Create a database record plus the private files it references.
     */
    protected function makeAudioFile(User $user, array $overrides = []): AudioFile
    {
        $extension = $overrides['extension'] ?? 'wav';
        $storagePath = $overrides['storage_path'] ?? 'audio/'.$user->id.'/'.Str::uuid().'.'.$extension;
        $previewPath = array_key_exists('preview_path', $overrides)
            ? $overrides['preview_path']
            : 'previews/'.$user->id.'/'.Str::uuid().'.mp3';

        if (! Storage::disk('local')->exists($storagePath)) {
            Storage::disk('local')->put($storagePath, 'source-audio');
        }

        if ($previewPath && ! Storage::disk('local')->exists($previewPath)) {
            Storage::disk('local')->put($previewPath, 'preview-audio');
        }

        return AudioFile::factory()->for($user)->create(array_merge([
            'original_name' => 'tone.'.$extension,
            'display_name' => 'tone.'.$extension,
            'storage_path' => $storagePath,
            'preview_path' => $previewPath,
            'mime_type' => 'audio/wav',
            'extension' => $extension,
            'file_size' => 4096,
            'duration_seconds' => 2.0,
            'sample_rate' => 44100,
            'channels' => 2,
            'status' => AudioFile::STATUS_READY,
            'effects' => config('audio.default_effects'),
            'waveform' => [0.1, 0.5, 0.9, 0.4],
        ], $overrides));
    }

    protected function makeProcessingJob(AudioFile $audioFile, array $overrides = []): AudioProcessingJob
    {
        $format = $overrides['format'] ?? 'wav';
        $outputPath = $overrides['output_path'] ?? 'exports/'.$audioFile->user_id.'/'.Str::uuid().'.'.$format;

        $completed = ($overrides['status'] ?? null) === AudioProcessingJob::STATUS_COMPLETED;

        if ($completed && ! Storage::disk('local')->exists($outputPath)) {
            Storage::disk('local')->put($outputPath, 'processed-audio');
        }

        return AudioProcessingJob::factory()->create(array_merge([
            'audio_file_id' => $audioFile->id,
            'user_id' => $audioFile->user_id,
            'input_path' => $audioFile->storage_path,
            'output_path' => $outputPath,
            'format' => $format,
            'settings' => $audioFile->effectSettings(),
            'status' => AudioProcessingJob::STATUS_PENDING,
            'progress' => $completed ? 100 : 0,
            'started_at' => $completed ? now()->subMinute() : null,
            'completed_at' => $completed ? now() : null,
        ], $overrides));
    }
}
