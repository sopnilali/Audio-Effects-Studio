<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;

class AudioPlaybackService
{
    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly AudioStorageService $storage,
    ) {}

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

        $temporary = $this->storage->temporaryPath('mp3');

        try {
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
                '-y', $temporary,
            ], 600);

            if ($result['exit_code'] !== 0 || ! is_file($temporary) || filesize($temporary) < 512) {
                throw AudioProcessingException::processingFailed('Unable to create the browser preview.');
            }

            $this->storage->putLocalFile($temporary, $destinationRelativePath);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function deletePreview(?string $relativePath): void
    {
        if ($relativePath) {
            $this->storage->delete($relativePath);
        }
    }
}
