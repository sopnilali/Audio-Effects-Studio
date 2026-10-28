<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Illuminate\Support\Facades\Log;

class AudioMetadataService
{
    public function __construct(private readonly FfmpegRunner $ffmpeg) {}

    /**
     * Probe a real file on disk. The path is never derived from user input;
     * callers pass a path resolved from private storage.
     *
     * @return array{
     *     duration: float,
     *     sample_rate: int,
     *     channels: int,
     *     bit_rate: ?int,
     *     codec: ?string,
     *     container: ?string,
     *     format_name: ?string,
     *     mime_type: string
     * }
     */
    public function probe(string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw AudioProcessingException::metadataFailed('The stored file is not readable.');
        }

        $result = $this->ffmpeg->ffprobe([
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            '-i', $absolutePath,
        ]);

        if ($result['exit_code'] !== 0) {
            Log::warning('FFprobe failed to inspect audio', [
                'stderr' => mb_substr($result['stderr'], -2000),
            ]);

            throw AudioProcessingException::metadataFailed();
        }

        $payload = json_decode($result['stdout'], true);

        if (! is_array($payload)) {
            throw AudioProcessingException::metadataFailed('Invalid probe output.');
        }

        $stream = collect($payload['streams'] ?? [])
            ->first(fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'audio');

        if (! $stream) {
            throw AudioProcessingException::metadataFailed('No audio stream found.');
        }

        $format = $payload['format'] ?? [];
        $duration = (float) ($stream['duration'] ?? $format['duration'] ?? 0);

        if ($duration <= 0 || ! is_finite($duration)) {
            throw AudioProcessingException::metadataFailed('The audio duration could not be determined.');
        }

        $maxDuration = (int) config('audio.max_duration_seconds', 1800);

        if ($maxDuration > 0 && $duration > $maxDuration) {
            throw AudioProcessingException::tooLong($duration, $maxDuration);
        }

        $sampleRate = (int) ($stream['sample_rate'] ?? 0);
        $channels = (int) ($stream['channels'] ?? 0);

        if ($sampleRate < 8000 || $sampleRate > 384000) {
            throw AudioProcessingException::metadataFailed('Unsupported sample rate.');
        }

        if ($channels < 1 || $channels > 8) {
            throw AudioProcessingException::metadataFailed('Unsupported channel layout.');
        }

        $formatName = (string) ($format['format_name'] ?? '');
        $codec = (string) ($stream['codec_name'] ?? '');

        return [
            'duration' => round($duration, 3),
            'sample_rate' => $sampleRate,
            'channels' => $channels,
            'bit_rate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            'codec' => $codec !== '' ? $codec : null,
            'container' => $formatName !== '' ? $formatName : null,
            'format_name' => $formatName !== '' ? $formatName : null,
            'mime_type' => $this->mimeTypeFor($formatName, $codec),
        ];
    }

    public function mimeTypeFor(string $formatName, string $codec): string
    {
        $formatName = strtolower($formatName);
        $codec = strtolower($codec);

        return match (true) {
            str_contains($formatName, 'mp3') => 'audio/mpeg',
            str_contains($formatName, 'wav') => 'audio/wav',
            str_contains($formatName, 'ogg') => 'audio/ogg',
            str_contains($formatName, 'flac') => 'audio/flac',
            str_contains($formatName, 'mp4'), str_contains($formatName, 'm4a') => 'audio/mp4',
            str_contains($formatName, 'aac') => 'audio/aac',
            $codec === 'mp3' => 'audio/mpeg',
            $codec === 'flac' => 'audio/flac',
            $codec === 'aac' => 'audio/aac',
            default => 'audio/mpeg',
        };
    }

    public function isSupportedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), config('audio.supported_extensions', []), true);
    }
}
