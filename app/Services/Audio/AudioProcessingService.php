<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use App\Models\AudioProcessingJob;
use Illuminate\Support\Facades\Log;
use Throwable;

class AudioProcessingService
{
    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly AudioMetadataService $metadata,
        private readonly AudioEffectsService $effects,
        private readonly ReverbImpulseService $impulse,
        private readonly AudioStorageService $storage,
    ) {}

    public function process(AudioProcessingJob $job): string
    {
        if ($job->isCompleted() && $job->outputExists()) {
            return (string) $job->output_path;
        }

        if (in_array($job->status, [AudioProcessingJob::STATUS_CANCELLED, AudioProcessingJob::STATUS_FAILED], true)) {
            throw AudioProcessingException::exportFailed('This job is no longer active.');
        }

        $job->markProcessing();

        $audioFile = $job->audioFile;

        if (! $audioFile) {
            $job->markFailed('The source audio file no longer exists.');
            throw AudioProcessingException::exportFailed('The source audio file no longer exists.');
        }

        $outputRelativePath = (string) $job->output_path;
        $format = strtolower((string) ($job->format ?: 'wav'));

        if (! in_array($format, config('audio.export_formats', ['wav', 'mp3']), true)) {
            $job->markFailed('Unsupported export format.');
            throw AudioProcessingException::exportFailed('Unsupported export format.');
        }

        $environment = $this->ffmpeg->environment();

        if (! $environment['ok']) {
            $job->markFailed('The audio processing environment is unavailable.');
            throw AudioProcessingException::ffmpegUnavailable();
        }

        if ($format === 'mp3' && ! $environment['mp3_encoder']) {
            $job->markFailed('The MP3 encoder is unavailable.');
            throw AudioProcessingException::exportFailed('The MP3 encoder is unavailable on this server.');
        }

        $settings = $this->effects->normalize($job->settings ?? []);

        if ($settings['reverb'] > 0 && ! in_array('afir', $environment['filters'], true)) {
            $job->markFailed('The reverb filter is unavailable.');
            throw AudioProcessingException::exportFailed('The reverb filter is unavailable on this server.');
        }

        $supportsIrnorm = (bool) ($environment['afir_irnorm'] ?? false);

        try {
            return $this->storage->withLocalCopy(
                $audioFile->storage_path,
                function (string $sourcePath) use ($job, $audioFile, $outputRelativePath, $format, $settings, $supportsIrnorm): string {
                    $outputPath = $this->storage->temporaryPath($format);

                    try {
                        $sourceMetadata = [
                            'sample_rate' => $audioFile->sample_rate,
                            'channels' => $audioFile->channels,
                        ];

                        if (! $sourceMetadata['sample_rate'] || ! $sourceMetadata['channels']) {
                            $probed = $this->metadata->probe($sourcePath);
                            $sourceMetadata = [
                                'sample_rate' => $probed['sample_rate'],
                                'channels' => $probed['channels'],
                            ];
                        }

                        $withReverb = $settings['reverb'] > 0;
                        $graph = $this->effects->buildFilterGraph($settings, $sourceMetadata, $withReverb, $supportsIrnorm);
                        $expectedDuration = max(0.1, ((float) ($audioFile->duration_seconds ?? 0)) / $settings['speed']);

                        $arguments = [
                            '-hide_banner',
                            '-nostdin',
                            '-v', 'error',
                            '-y',
                            '-i', $sourcePath,
                        ];

                        if ($withReverb) {
                            $arguments[] = '-i';
                            $arguments[] = $this->impulse->absolutePath();
                        }

                        $arguments = array_merge($arguments, [
                            '-filter_complex', $graph['filter_complex'],
                            '-map', '[out]',
                            '-ar', (string) $graph['output_rate'],
                            '-ac', (string) $graph['output_channels'],
                        ]);

                        if ($format === 'wav') {
                            $arguments = array_merge($arguments, ['-c:a', 'pcm_s16le']);
                        } else {
                            $arguments = array_merge($arguments, [
                                '-c:a', 'libmp3lame',
                                '-b:a', (string) config('audio.mp3_bitrate', 192000),
                                '-id3v2_version', '3',
                            ]);
                        }

                        $arguments = array_merge($arguments, [
                            '-progress', 'pipe:1',
                            '-nostats',
                            $outputPath,
                        ]);

                        $lastReportedProgress = -1;

                        $this->ffmpeg->ffmpegWithProgress(
                            $arguments,
                            (int) config('audio.job_timeout', 900),
                            function (array $progress) use ($job, $expectedDuration, &$lastReportedProgress): void {
                                if ($progress['out_time_us'] === null) {
                                    return;
                                }

                                $seconds = $progress['out_time_us'] / 1_000_000;
                                $percent = (int) min(95, max(5, round(($seconds / $expectedDuration) * 90)));

                                if ($percent >= $lastReportedProgress + 2) {
                                    $lastReportedProgress = $percent;
                                    $job->forceFill(['progress' => $percent])->save();
                                }
                            },
                        );

                        $this->verifyOutput($outputPath, $format);
                        $this->storage->putLocalFile($outputPath, $outputRelativePath);

                        $job->markCompleted($outputRelativePath);

                        return $outputRelativePath;
                    } finally {
                        if (is_file($outputPath)) {
                            @unlink($outputPath);
                        }
                    }
                },
            );
        } catch (Throwable $exception) {
            $safeMessage = $exception instanceof AudioProcessingException
                ? $exception->safeMessage()
                : 'The audio export failed.';

            $job->markFailed($safeMessage);

            Log::error('Audio processing job failed', [
                'job_id' => $job->id,
                'format' => $format,
                'message' => $exception->getMessage(),
            ]);

            if ($exception instanceof AudioProcessingException) {
                throw $exception;
            }

            throw AudioProcessingException::exportFailed($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyOutput(string $absolutePath, string $format): array
    {
        if (! is_file($absolutePath) || filesize($absolutePath) < 1024) {
            throw AudioProcessingException::exportFailed('The encoded file is empty.');
        }

        $metadata = $this->metadata->probe($absolutePath);

        $expectedCodec = $format === 'mp3' ? 'mp3' : 'pcm_s16le';

        if ($metadata['codec'] !== $expectedCodec) {
            throw AudioProcessingException::exportFailed('The encoded file has an unexpected codec.');
        }

        if ($metadata['duration'] <= 0) {
            throw AudioProcessingException::exportFailed('The encoded file has no duration.');
        }

        return $metadata;
    }
}
