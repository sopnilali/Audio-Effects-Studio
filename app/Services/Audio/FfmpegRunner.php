<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Thin, shell-free wrapper around FFmpeg and FFprobe.
 *
 * Every argument is passed to Symfony Process as an array. User supplied
 * values are validated and converted to numbers before they reach this class.
 */
class FfmpegRunner
{
    public function __construct(
        private readonly string $ffmpegPath,
        private readonly string $ffprobePath,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('audio.ffmpeg_path', 'ffmpeg'),
            (string) config('audio.ffprobe_path', 'ffprobe'),
        );
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function ffmpeg(array $arguments, int $timeout = 300): array
    {
        return $this->run(array_merge([$this->ffmpegPath], $arguments), $timeout);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function ffprobe(array $arguments, int $timeout = 60): array
    {
        return $this->run(array_merge([$this->ffprobePath], $arguments), $timeout);
    }

    /**
     * Run FFmpeg while streaming progress lines from `-progress pipe:1`.
     *
     * @param  list<string>  $arguments
     */
    public function ffmpegWithProgress(array $arguments, int $timeout, callable $onProgress): array
    {
        $process = new Process(array_merge([$this->ffmpegPath], $arguments));
        $process->setTimeout($timeout);
        $process->setIdleTimeout($timeout);

        $stdout = '';
        $stderr = '';
        $lineBuffer = '';

        try {
            $process->start();
        } catch (ProcessStartFailedException $exception) {
            throw AudioProcessingException::ffmpegUnavailable($exception->getMessage());
        }

        while ($process->isRunning()) {
            $stdout .= $process->getIncrementalOutput();
            $stderr .= $process->getIncrementalErrorOutput();

            if ($stdout !== '') {
                $lineBuffer .= $stdout;
                $stdout = '';

                while (($position = strpos($lineBuffer, "\n")) !== false) {
                    $line = trim(substr($lineBuffer, 0, $position));
                    $lineBuffer = substr($lineBuffer, $position + 1);

                    if ($line !== '') {
                        $onProgress($this->parseProgressLine($line));
                    }
                }
            }

            usleep(100_000);
        }

        try {
            $process->wait();
        } catch (Throwable $exception) {
            $stderr .= "\n".$process->getErrorOutput();

            Log::error('FFmpeg process error', [
                'message' => $exception->getMessage(),
                'stderr' => mb_substr($stderr, -4000),
            ]);

            throw AudioProcessingException::processingFailed($exception->getMessage());
        }

        $stderr .= $process->getErrorOutput();

        if (! $process->isSuccessful()) {
            Log::error('FFmpeg exited with an error', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($stderr, -4000),
            ]);

            throw AudioProcessingException::processingFailed(
                'FFmpeg exited with code '.$process->getExitCode().'.'
            );
        }

        return [
            'exit_code' => (int) $process->getExitCode(),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(array $arguments, int $timeout = 300): array
    {
        $process = new Process($arguments);
        $process->setTimeout($timeout);
        $process->setIdleTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessStartFailedException $exception) {
            throw AudioProcessingException::ffmpegUnavailable($exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Process execution failed', [
                'message' => $exception->getMessage(),
                'arguments' => $arguments[0] ?? null,
            ]);

            throw AudioProcessingException::processingFailed($exception->getMessage());
        }

        return [
            'exit_code' => (int) $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * @return array{ok: bool, ffmpeg: bool, ffprobe: bool, mp3_encoder: bool, filters: list<string>, version: ?string, errors: list<string>}
     */
    public function environment(): array
    {
        return Cache::remember('audio:ffmpeg-environment', now()->addMinutes(10), function (): array {
            $result = [
                'ok' => false,
                'ffmpeg' => false,
                'ffprobe' => false,
                'mp3_encoder' => false,
                'filters' => [],
                'version' => null,
                'errors' => [],
            ];

            try {
                $version = $this->run([$this->ffmpegPath, '-hide_banner', '-version'], 15);
                if ($version['exit_code'] === 0) {
                    $result['ffmpeg'] = true;
                    $result['version'] = strtok($version['stdout'], "\n") ?: null;
                } else {
                    $result['errors'][] = 'FFmpeg could not be executed.';
                }
            } catch (Throwable $exception) {
                $result['errors'][] = 'FFmpeg could not be executed.';
            }

            try {
                $probe = $this->run([$this->ffprobePath, '-hide_banner', '-version'], 15);
                $result['ffprobe'] = $probe['exit_code'] === 0;
            } catch (Throwable) {
                $result['errors'][] = 'FFprobe could not be executed.';
            }

            if ($result['ffmpeg']) {
                try {
                    $filters = $this->run([$this->ffmpegPath, '-hide_banner', '-filters'], 30);
                    foreach (['atempo', 'asetrate', 'aresample', 'lowshelf', 'afir', 'alimiter', 'anull'] as $filter) {
                        if (preg_match('/\s'.preg_quote($filter, '/').'\s/', $filters['stdout'])) {
                            $result['filters'][] = $filter;
                        }
                    }

                    $encoders = $this->run([$this->ffmpegPath, '-hide_banner', '-encoders'], 30);
                    $result['mp3_encoder'] = (bool) preg_match('/libmp3lame/', $encoders['stdout']);

                    if (! in_array('afir', $result['filters'], true)) {
                        $result['errors'][] = 'The FFmpeg afir filter is missing; reverb export is unavailable.';
                    }

                    if (! $result['mp3_encoder']) {
                        $result['errors'][] = 'The libmp3lame MP3 encoder is missing; MP3 export is unavailable.';
                    }
                } catch (Throwable) {
                    $result['errors'][] = 'Unable to inspect FFmpeg filters and encoders.';
                }
            }

            $result['ok'] = $result['ffmpeg']
                && $result['ffprobe']
                && in_array('atempo', $result['filters'], true)
                && in_array('lowshelf', $result['filters'], true)
                && in_array('alimiter', $result['filters'], true);

            return $result;
        });
    }

    public function forgetEnvironmentCache(): void
    {
        Cache::forget('audio:ffmpeg-environment');
    }

    /**
     * @return array{out_time_us: ?int, progress: ?string, speed: ?string}
     */
    private function parseProgressLine(string $line): array
    {
        $data = ['out_time_us' => null, 'progress' => null, 'speed' => null];

        if (! str_contains($line, '=')) {
            return $data;
        }

        [$key, $value] = explode('=', $line, 2);

        if ($key === 'out_time_ms' && is_numeric($value)) {
            $data['out_time_us'] = (int) $value;
        } elseif ($key === 'out_time_us' && is_numeric($value)) {
            $data['out_time_us'] = (int) $value;
        } elseif ($key === 'progress') {
            $data['progress'] = trim($value);
        } elseif ($key === 'speed') {
            $data['speed'] = trim($value);
        }

        return $data;
    }
}
