<?php

namespace App\Console\Commands;

use App\Services\Audio\FfmpegRunner;
use App\Services\Audio\ReverbImpulseService;
use Illuminate\Console\Command;

class AudioCheckCommand extends Command
{
    protected $signature = 'audio:check {--fresh : Ignore the cached environment check}';

    protected $description = 'Verify that FFmpeg, FFprobe, filters and encoders required for audio processing are available';

    public function handle(FfmpegRunner $ffmpeg, ReverbImpulseService $impulse): int
    {
        if ($this->option('fresh')) {
            $ffmpeg->forgetEnvironmentCache();
        }

        $environment = $ffmpeg->environment();

        $this->components->info('Audio processing environment');
        $this->line('  FFmpeg binary:  '.config('audio.ffmpeg_path'));
        $this->line('  FFprobe binary: '.config('audio.ffprobe_path'));
        $this->line('  FFmpeg:         '.($environment['ffmpeg'] ? 'available' : 'missing'));
        $this->line('  FFprobe:        '.($environment['ffprobe'] ? 'available' : 'missing'));
        $this->line('  MP3 encoder:    '.($environment['mp3_encoder'] ? 'libmp3lame available' : 'missing'));
        $this->line('  Filters:        '.(implode(', ', $environment['filters']) ?: 'none detected'));

        if ($environment['version']) {
            $this->line('  Version:        '.$environment['version']);
        }

        try {
            $impulsePath = $impulse->absolutePath();
            $this->line('  Reverb impulse: '.$impulsePath);
        } catch (\Throwable $exception) {
            $this->components->error('Unable to create the reverb impulse response: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($environment['errors'] as $error) {
            $this->components->warn($error);
        }

        if (! $environment['ok']) {
            $this->components->error('Audio processing is not fully configured. Install FFmpeg with the required filters and set FFMPEG_PATH / FFPROBE_PATH.');

            return self::FAILURE;
        }

        $this->components->info('Audio processing is ready.');

        return self::SUCCESS;
    }
}
