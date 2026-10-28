<?php

namespace Tests\Unit;

use App\Services\Audio\AudioEffectsService;
use Tests\TestCase;

class AudioEffectsServiceTest extends TestCase
{
    private AudioEffectsService $effects;

    protected function setUp(): void
    {
        parent::setUp();

        $this->effects = new AudioEffectsService;
    }

    public function test_defaults_are_canonical(): void
    {
        $this->assertSame([
            'speed' => 1.0,
            'pitch_follows_speed' => true,
            'pitch_enabled' => false,
            'pitch_multiplier' => 1.0,
            'reverb' => 0,
            'bass_boost' => 0,
            'preset_name' => null,
        ], $this->effects->defaults());
    }

    public function test_values_are_clamped_and_rounded(): void
    {
        $settings = $this->effects->normalize([
            'speed' => 99,
            'pitch_follows_speed' => '0',
            'pitch_enabled' => '1',
            'pitch_multiplier' => 0.01,
            'reverb' => -20,
            'bass_boost' => 120,
            'preset_name' => 'nightcore',
        ]);

        $this->assertSame(2.0, $settings['speed']);
        $this->assertFalse($settings['pitch_follows_speed']);
        $this->assertTrue($settings['pitch_enabled']);
        $this->assertSame(0.5, $settings['pitch_multiplier']);
        $this->assertSame(0, $settings['reverb']);
        $this->assertSame(100, $settings['bass_boost']);
        $this->assertSame('nightcore', $settings['preset_name']);
    }

    public function test_non_finite_values_fall_back_to_the_minimum(): void
    {
        $settings = $this->effects->normalize([
            'speed' => INF,
            'pitch_multiplier' => NAN,
            'reverb' => NAN,
            'bass_boost' => INF,
        ]);

        $this->assertSame(0.25, $settings['speed']);
        $this->assertSame(0.5, $settings['pitch_multiplier']);
        $this->assertSame(0, $settings['reverb']);
        $this->assertSame(0, $settings['bass_boost']);
    }

    public function test_unknown_presets_are_removed(): void
    {
        $this->assertNull($this->effects->normalize(['preset_name' => 'nope'])['preset_name']);
        $this->assertNull($this->effects->normalize(['preset_name' => ['x']])['preset_name']);
    }

    public function test_atempo_chain_splits_out_of_range_tempos(): void
    {
        $this->assertSame([], $this->effects->atempoChain(1.0));
        $this->assertSame(['atempo=0.500000', 'atempo=0.500000'], $this->effects->atempoChain(0.25));
        $this->assertSame(['atempo=1.250000'], $this->effects->atempoChain(1.25));
        $this->assertCount(2, $this->effects->atempoChain(150.0));
    }

    public function test_atempo_rejects_invalid_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->effects->atempoChain(0.0);
    }

    public function test_a_plain_export_graph_contains_a_limiter(): void
    {
        $graph = $this->effects->buildFilterGraph(
            ['speed' => 1, 'pitch_enabled' => false, 'reverb' => 0, 'bass_boost' => 0],
            ['sample_rate' => 44100, 'channels' => 2],
            false,
        );

        $this->assertStringContainsString('anull', $graph['filter_complex']);
        $this->assertStringContainsString('alimiter=limit=0.95', $graph['filter_complex']);
        $this->assertStringNotContainsString('afir', $graph['filter_complex']);
        $this->assertSame(44100, $graph['output_rate']);
        $this->assertSame(2, $graph['output_channels']);
        $this->assertFalse($graph['pitch_applied']);
    }

    public function test_pitch_shifting_preserves_duration_and_is_independent_of_speed(): void
    {
        $graph = $this->effects->buildFilterGraph(
            ['speed' => 1.25, 'pitch_follows_speed' => false, 'pitch_enabled' => true, 'pitch_multiplier' => 1.5, 'reverb' => 0, 'bass_boost' => 0],
            ['sample_rate' => 44100, 'channels' => 2],
            false,
        );

        $this->assertStringContainsString('asetrate=66150', $graph['filter_complex']);
        $this->assertStringContainsString('atempo=', $graph['filter_complex']);
        $this->assertStringContainsString('aresample=44100', $graph['filter_complex']);
        $this->assertTrue($graph['pitch_applied']);
        $this->assertSame(1.5, $graph['pitch']);
        $this->assertEqualsWithDelta(1.25 / 1.5, $graph['tempo'], 0.000001);
    }

    public function test_tape_mode_couples_speed_and_pitch_without_atempo(): void
    {
        $graph = $this->effects->buildFilterGraph(
            ['speed' => 0.8, 'pitch_follows_speed' => true, 'pitch_enabled' => false, 'reverb' => 0, 'bass_boost' => 0],
            ['sample_rate' => 44100, 'channels' => 2],
            false,
        );

        $this->assertStringContainsString('asetrate=35280', $graph['filter_complex']);
        $this->assertStringNotContainsString('atempo=', $graph['filter_complex']);
        $this->assertSame(0.8, $graph['pitch']);
        $this->assertSame(1.0, $graph['tempo']);
        $this->assertTrue($graph['pitch_applied']);

        $nightcore = $this->effects->buildFilterGraph(
            ['speed' => 1.25, 'pitch_follows_speed' => true, 'pitch_enabled' => false, 'reverb' => 0, 'bass_boost' => 0],
            ['sample_rate' => 44100, 'channels' => 2],
            false,
        );

        $this->assertStringContainsString('asetrate=55125', $nightcore['filter_complex']);
        $this->assertSame(1.25, $nightcore['pitch']);
        $this->assertSame(1.0, $nightcore['tempo']);
    }

    public function test_independent_pitch_can_cancel_tape_mode_pitch(): void
    {
        $graph = $this->effects->buildFilterGraph(
            ['speed' => 0.8, 'pitch_follows_speed' => true, 'pitch_enabled' => true, 'pitch_multiplier' => 1.25, 'reverb' => 0, 'bass_boost' => 0],
            ['sample_rate' => 44100, 'channels' => 2],
            false,
        );

        $this->assertStringNotContainsString('asetrate=', $graph['filter_complex']);
        $this->assertStringContainsString('atempo=0.800000', $graph['filter_complex']);
        $this->assertSame(1.0, $graph['pitch']);
        $this->assertFalse($graph['pitch_applied']);
    }

    public function test_reverb_graph_uses_a_second_input_and_afir(): void
    {
        $graph = $this->effects->buildFilterGraph(
            ['speed' => 1, 'pitch_enabled' => false, 'reverb' => 40, 'bass_boost' => 0],
            ['sample_rate' => 44100, 'channels' => 1],
            true,
        );

        $this->assertStringContainsString('[1:a]aresample=44100', $graph['filter_complex']);
        $this->assertStringContainsString('channel_layouts=mono', $graph['filter_complex']);
        $this->assertStringContainsString('afir=', $graph['filter_complex']);
        $this->assertStringContainsString('alimiter=', $graph['filter_complex']);
        $this->assertStringContainsString('dry=0.86', $graph['filter_complex']);
        $this->assertStringContainsString('wet=0.36', $graph['filter_complex']);
        $this->assertStringContainsString('gtype=none:irnorm=0', $graph['filter_complex']);
    }

    public function test_bass_boost_maps_percentage_to_db(): void
    {
        $graph = $this->effects->buildFilterGraph(
            ['speed' => 1, 'pitch_enabled' => false, 'reverb' => 0, 'bass_boost' => 50],
            ['sample_rate' => 44100, 'channels' => 2],
            false,
        );

        $this->assertStringContainsString('lowshelf=frequency=150:gain=6.00', $graph['filter_complex']);
    }

    public function test_presets_are_detected_and_described(): void
    {
        $this->assertSame('slowed_reverb', $this->effects->activePreset([
            'speed' => 0.8,
            'pitch_enabled' => false,
            'pitch_multiplier' => 1,
            'reverb' => 40,
            'bass_boost' => 0,
        ]));

        $this->assertSame('pure_reverb', $this->effects->activePreset([
            'speed' => 1,
            'pitch_follows_speed' => true,
            'pitch_enabled' => false,
            'pitch_multiplier' => 1,
            'reverb' => 40,
            'bass_boost' => 0,
        ]));

        $this->assertSame('nightcore', $this->effects->activePreset([
            'speed' => 1.25,
            'pitch_follows_speed' => true,
            'pitch_enabled' => false,
            'pitch_multiplier' => 1,
            'reverb' => 0,
            'bass_boost' => 0,
        ]));

        $this->assertSame(
            '0.80x speed, pitch follows speed, 0% reverb, 25% bass',
            $this->effects->describe(['speed' => 0.8, 'reverb' => 0, 'bass_boost' => 25]),
        );
    }

    public function test_semitone_shift_is_calculated_from_the_pitch_multiplier(): void
    {
        $this->assertEqualsWithDelta(4.0, $this->effects->semitoneShift(1.2599), 0.1);
        $this->assertEqualsWithDelta(-12.0, $this->effects->semitoneShift(0.5), 0.1);
        $this->assertSame(0.0, $this->effects->semitoneShift(0));
    }
}
