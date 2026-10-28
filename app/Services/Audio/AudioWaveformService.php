<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AudioWaveformService
{
    public function __construct(private readonly FfmpegRunner $ffmpeg) {}

    /**
     * Decode the real audio stream to mono PCM and compute peak buckets.
     *
     * @return list<float>
     */
    public function generate(string $absolutePath, ?float $duration = null, ?int $peakCount = null): array
    {
        $peakCount = max(32, min(4000, $peakCount ?? (int) config('audio.waveform_peaks', 720)));
        $sampleRate = max(1000, (int) config('audio.waveform_sample_rate', 4000));

        $temporaryDirectory = (string) config('audio.temp_directory', storage_path('app/private/temporary'));
        File::ensureDirectoryExists($temporaryDirectory);

        $rawPath = $temporaryDirectory.'/waveform-'.Str::uuid().'.raw';

        try {
            $result = $this->ffmpeg->ffmpeg([
                '-hide_banner',
                '-nostdin',
                '-v', 'error',
                '-i', $absolutePath,
                '-map', '0:a:0',
                '-ac', '1',
                '-ar', (string) $sampleRate,
                '-f', 's16le',
                '-y', $rawPath,
            ], 300);

            if ($result['exit_code'] !== 0 || ! is_file($rawPath)) {
                throw AudioProcessingException::processingFailed('Unable to decode audio for the waveform.');
            }

            return $this->peaksFromRawFile($rawPath, $peakCount);
        } finally {
            if (is_file($rawPath)) {
                @unlink($rawPath);
            }
        }
    }

    /**
     * @return list<float>
     */
    private function peaksFromRawFile(string $rawPath, int $peakCount): array
    {
        $totalSamples = intdiv((int) filesize($rawPath), 2);

        if ($totalSamples < 1) {
            return [0.0, 0.0];
        }

        $bucketSize = max(1, (int) ceil($totalSamples / $peakCount));
        $peaks = [];
        $handle = fopen($rawPath, 'rb');

        if ($handle === false) {
            throw AudioProcessingException::processingFailed('Unable to read the decoded waveform.');
        }

        try {
            $absoluteMaximum = 0;
            $carry = '';
            $bucketMax = 0;
            $samplesInBucket = 0;

            while (! feof($handle)) {
                $chunk = fread($handle, 64 * 1024);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $chunk = $carry.$chunk;
                $usableLength = strlen($chunk) - (strlen($chunk) % 2);
                $carry = substr($chunk, $usableLength);

                if ($usableLength === 0) {
                    continue;
                }

                /** @var array<int, int> $samples */
                $samples = unpack('v*', substr($chunk, 0, $usableLength));

                foreach ($samples as $unsigned) {
                    $value = $unsigned >= 32768 ? $unsigned - 65536 : $unsigned;
                    $magnitude = abs($value);
                    $bucketMax = max($bucketMax, $magnitude);
                    $absoluteMaximum = max($absoluteMaximum, $magnitude);
                    $samplesInBucket++;

                    if ($samplesInBucket >= $bucketSize) {
                        $peaks[] = $bucketMax;
                        $bucketMax = 0;
                        $samplesInBucket = 0;
                    }
                }
            }

            if ($samplesInBucket > 0) {
                $peaks[] = $bucketMax;
            }

            if ($absoluteMaximum === 0) {
                return array_fill(0, max(2, count($peaks)), 0.0);
            }

            return array_map(
                fn (int|float $peak): float => round(((float) $peak) / $absoluteMaximum, 3),
                $peaks,
            );
        } finally {
            fclose($handle);
        }
    }
}
