<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class AudioPlaybackService
{
    public function __construct(private readonly FfmpegRunner $ffmpeg) {}

    /**
     * Every upload gets a browser-safe MP3 preview. This makes playback and
     * waveform synchronisation consistent across Safari, Chrome and Firefox
     * even when the source is FLAC, OGG or another less portable format.
     */
    public function generatePreview(string $sourceAbsolutePath, string $destinationRelativePath, ?int $bitrate = null): void
    {
        $environment = $this->ffmpeg->environment();

        if (! $environment['mp3_encoder']) {
            throw new AudioProcessingException(
                'libmp3lame is unavailable.',
                'This server cannot create the browser preview because the MP3 encoder is missing.',
            );
        }

        $absoluteDestination = Storage::disk('local')->path($destinationRelativePath);
        File::ensureDirectoryExists(dirname($absoluteDestination));

        $result = $this->ffmpeg->ffmpeg([
            '-hide_banner',
            '-nostdin',
            '-v', 'error',
            '-i', $sourceAbsolutePath,
            '-map', '0:a:0',
            '-vn',
            '-c:a', 'libmp3lame',
            '-b:a', (string) ($bitrate ?? config('audio.preview_bitrate', 128000)),
            '-ar', '44100',
            '-ac', '2',
            '-y', $absoluteDestination,
        ], 600);

        if ($result['exit_code'] !== 0 || ! is_file($absoluteDestination) || filesize($absoluteDestination) < 512) {
            if (is_file($absoluteDestination)) {
                @unlink($absoluteDestination);
            }

            throw AudioProcessingException::processingFailed('Unable to create the browser preview.');
        }
    }

    public function deletePreview(?string $relativePath): void
    {
        if ($relativePath && Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }
}
