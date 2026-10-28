<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Creates a deterministic, stereo convolution impulse response (a synthetic
 * hall). The WAV is generated once and reused for every reverb export.
 */
class ReverbImpulseService
{
    public const SAMPLE_RATE = 48000;

    public function relativePath(): string
    {
        return (string) config('audio.reverb_impulse_path', 'audio/impulse/studio-hall.wav');
    }

    public function absolutePath(): string
    {
        $relative = $this->relativePath();

        if (! Storage::disk('local')->exists($relative)) {
            $this->generate();
        }

        return Storage::disk('local')->path($relative);
    }

    public function exists(): bool
    {
        return Storage::disk('local')->exists($this->relativePath());
    }

    public function generate(): void
    {
        $relative = $this->relativePath();
        $absolute = Storage::disk('local')->path($relative);
        File::ensureDirectoryExists(dirname($absolute));

        $seconds = max(0.4, min(8.0, (float) config('audio.reverb_impulse_seconds', 2.4)));
        $decay = max(0.5, (float) config('audio.reverb_decay', 3.2));
        $sampleCount = (int) round(self::SAMPLE_RATE * $seconds);
        $channels = 2;

        // A fixed seed makes the impulse response stable across deployments.
        mt_srand(20261028);

        $pcm = '';
        $left = 0.0;
        $right = 0.0;

        for ($i = 0; $i < $sampleCount; $i++) {
            $time = $i / self::SAMPLE_RATE;
            $envelope = exp(-$decay * $time) * (1 - ($i / $sampleCount));

            // Slight smoothing prevents harsh white-noise reverb.
            $left = ($left * 0.35) + ((mt_rand() / mt_getrandmax() * 2 - 1) * 0.65);
            $right = ($right * 0.35) + ((mt_rand() / mt_getrandmax() * 2 - 1) * 0.65);

            // Early reflections add a sense of room size.
            if ($i === (int) (0.031 * self::SAMPLE_RATE)) {
                $left += 0.45;
            }
            if ($i === (int) (0.047 * self::SAMPLE_RATE)) {
                $right += 0.38;
            }
            if ($i === (int) (0.071 * self::SAMPLE_RATE)) {
                $left -= 0.25;
            }

            $leftSample = (int) round(max(-1.0, min(1.0, $left * $envelope)) * 32767 * 0.55);
            $rightSample = (int) round(max(-1.0, min(1.0, $right * $envelope)) * 32767 * 0.55);

            $pcm .= pack('v2', $leftSample & 0xFFFF, $rightSample & 0xFFFF);
        }

        $byteRate = self::SAMPLE_RATE * $channels * 2;
        $blockAlign = $channels * 2;
        $dataLength = strlen($pcm);

        $header = 'RIFF'
            .pack('V', 36 + $dataLength)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', $channels)
            .pack('V', self::SAMPLE_RATE)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', 16)
            .'data'
            .pack('V', $dataLength);

        if (! Storage::disk('local')->put($relative, $header.$pcm)) {
            throw new RuntimeException('Unable to write the reverb impulse response.');
        }
    }
}
