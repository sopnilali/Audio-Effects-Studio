<?php

namespace App\Services\Audio;

use InvalidArgumentException;

class AudioEffectsService
{
    /**
     * @return array{speed: float, pitch_follows_speed: bool, pitch_enabled: bool, pitch_multiplier: float, reverb: int, bass_boost: int, preset_name: ?string}
     */
    public function defaults(): array
    {
        return $this->normalize((array) config('audio.default_effects', []));
    }

    /**
     * @return array<string, array{label: string, description: string, settings: array<string, mixed>}>
     */
    public function presets(): array
    {
        return (array) config('audio.presets', []);
    }

    /**
     * Clamp and canonicalise values. This is the last line of defence before
     * numbers are embedded into an FFmpeg filter graph.
     *
     * @param  array<string, mixed>  $input
     * @return array{speed: float, pitch_follows_speed: bool, pitch_enabled: bool, pitch_multiplier: float, reverb: int, bass_boost: int, preset_name: ?string}
     */
    public function normalize(array $input): array
    {
        $speed = $this->clamp((float) ($input['speed'] ?? 1.0), 0.25, 2.0);
        $pitchFollowsSpeed = filter_var($input['pitch_follows_speed'] ?? true, FILTER_VALIDATE_BOOL);
        $pitchEnabled = filter_var($input['pitch_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $pitch = $this->clamp((float) ($input['pitch_multiplier'] ?? 1.0), 0.5, 2.0);
        $reverb = (int) round($this->clamp((float) ($input['reverb'] ?? 0), 0, 100));
        $bass = (int) round($this->clamp((float) ($input['bass_boost'] ?? 0), 0, 100));
        $preset = $input['preset_name'] ?? null;

        if (! is_string($preset) || ! array_key_exists($preset, $this->presets())) {
            $preset = null;
        }

        return [
            'speed' => round($speed, 2),
            'pitch_follows_speed' => $pitchFollowsSpeed,
            'pitch_enabled' => $pitchEnabled,
            'pitch_multiplier' => round($pitch, 2),
            'reverb' => $reverb,
            'bass_boost' => $bass,
            'preset_name' => $preset,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $metadata
     * @return array{filter_complex: string, output_rate: int, output_channels: int, tempo: float, pitch: float, pitch_applied: bool}
     */
    public function buildFilterGraph(array $settings, array $metadata, bool $withReverb): array
    {
        $settings = $this->normalize($settings);

        $sampleRate = max(8000, min(384000, (int) ($metadata['sample_rate'] ?? 44100)));
        $channels = max(1, min(8, (int) ($metadata['channels'] ?? 2)));
        $speed = $settings['speed'];
        $independentPitch = $settings['pitch_enabled'] ? $settings['pitch_multiplier'] : 1.0;

        // Tape mode: speed and pitch move together (slower = deeper voice,
        // faster = thinner voice). The optional independent pitch control is
        // layered on top. atempo then compensates so the final duration still
        // matches the requested speed.
        $pitch = $settings['pitch_follows_speed']
            ? $speed * $independentPitch
            : $independentPitch;
        $tempo = $speed / max(0.001, $pitch);

        $chain = [];

        if (abs($pitch - 1.0) > 0.001) {
            // Resampling changes pitch and speed together. A compensating
            // atempo pass restores the requested duration.
            $targetRate = (int) max(1000, min(192000, round($sampleRate * $pitch)));
            $effectivePitch = $targetRate / $sampleRate;
            $tempo = $speed / $effectivePitch;

            $chain[] = 'asetrate='.$targetRate;
            $chain = array_merge($chain, $this->atempoChain($tempo));
            $chain[] = 'aresample='.$sampleRate;
        } else {
            $pitch = 1.0;
            $chain = array_merge($chain, $this->atempoChain($speed));
        }

        if ($settings['bass_boost'] > 0) {
            $gain = round(((float) config('audio.bass_shelf_max_gain_db', 12)) * ($settings['bass_boost'] / 100), 2);
            $frequency = (int) config('audio.bass_shelf_frequency', 150);
            $chain[] = sprintf('lowshelf=frequency=%d:gain=%.2f:width_type=q:width=0.7', $frequency, $gain);
        }

        if ($chain === []) {
            $chain[] = 'anull';
        }

        $limiter = 'alimiter=limit='.number_format((float) config('audio.limiter_level', 0.95), 2, '.', '');

        if ($withReverb) {
            $wetAmount = $settings['reverb'] / 100;
            $dryGain = round(1.0 - (0.35 * $wetAmount), 3);
            $wetGain = round(0.9 * $wetAmount, 3);

            $layout = $channels === 1 ? 'mono' : 'stereo';

            $filterComplex = '[0:a]'.implode(',', $chain).'[main];'
                .'[1:a]aresample='.$sampleRate.',aformat=channel_layouts='.$layout.'[ir];'
                .'[main][ir]afir=dry='.$dryGain.':wet='.$wetGain.':gtype=none:irnorm=0:maxir=5,'
                .$limiter.'[out]';
        } else {
            $filterComplex = '[0:a]'.implode(',', $chain).'[main];'
                .'[main]'.$limiter.'[out]';
        }

        return [
            'filter_complex' => $filterComplex,
            'output_rate' => $sampleRate,
            'output_channels' => $channels,
            'tempo' => round($tempo, 6),
            'pitch' => $pitch,
            'pitch_applied' => abs($pitch - 1.0) > 0.001,
        ];
    }

    /**
     * FFmpeg's atempo filter only accepts 0.5–100. Larger changes are split
     * into a chain of valid factors.
     *
     * @return list<string>
     */
    public function atempoChain(float $tempo): array
    {
        if (! is_finite($tempo) || $tempo <= 0) {
            throw new InvalidArgumentException('Tempo must be a positive number.');
        }

        $tempo = max(0.001, min(10000.0, $tempo));
        $filters = [];

        if (abs($tempo - 1.0) < 0.00001) {
            return [];
        }

        while ($tempo < 0.5 - 0.000001) {
            $filters[] = 'atempo=0.500000';
            $tempo /= 0.5;
        }

        while ($tempo > 100.0 + 0.000001) {
            $filters[] = 'atempo=100.000000';
            $tempo /= 100.0;
        }

        $filters[] = 'atempo='.number_format($tempo, 6, '.', '');

        return $filters;
    }

    public function semitoneShift(float $pitchMultiplier): float
    {
        if ($pitchMultiplier <= 0) {
            return 0.0;
        }

        return round(12 * log($pitchMultiplier, 2), 1);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function activePreset(array $settings): ?string
    {
        $normalized = $this->normalize($settings);

        foreach ($this->presets() as $key => $preset) {
            $presetSettings = $this->normalize($preset['settings']);

            if ($this->settingsMatch($normalized, $presetSettings)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function settingsMatch(array $a, array $b): bool
    {
        return abs((float) $a['speed'] - (float) $b['speed']) < 0.001
            && (bool) $a['pitch_follows_speed'] === (bool) $b['pitch_follows_speed']
            && (bool) $a['pitch_enabled'] === (bool) $b['pitch_enabled']
            && abs((float) $a['pitch_multiplier'] - (float) $b['pitch_multiplier']) < 0.001
            && (int) $a['reverb'] === (int) $b['reverb']
            && (int) $a['bass_boost'] === (int) $b['bass_boost'];
    }

    /**
     * Human readable summary used in the export modal and tests.
     *
     * @param  array<string, mixed>  $settings
     */
    public function describe(array $settings): string
    {
        $settings = $this->normalize($settings);
        $parts = [number_format($settings['speed'], 2).'x speed'];

        if ($settings['pitch_follows_speed']) {
            $parts[] = 'pitch follows speed';
        }

        if ($settings['pitch_enabled']) {
            $parts[] = number_format($settings['pitch_multiplier'], 2).'x extra pitch';
        }

        $parts[] = $settings['reverb'].'% reverb';
        $parts[] = $settings['bass_boost'].'% bass';

        return implode(', ', $parts);
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        if (! is_finite($value)) {
            return $minimum;
        }

        return max($minimum, min($maximum, $value));
    }
}
