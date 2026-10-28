<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAudio;
use Tests\TestCase;

class AudioEffectsTest extends TestCase
{
    use InteractsWithAudio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_effects_are_saved_clamped_and_typed(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);

        $response = $this->actingAs($user)->patchJson(route('audio.effects.update', $file), [
            'speed' => 0.8,
            'pitch_enabled' => true,
            'pitch_multiplier' => 1.25,
            'reverb' => 40,
            'bass_boost' => 30,
            'preset_name' => 'slowed_reverb',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.effects.speed', 0.8)
            ->assertJsonPath('data.effects.pitch_enabled', true)
            ->assertJsonPath('data.effects.pitch_multiplier', 1.25)
            ->assertJsonPath('data.effects.reverb', 40)
            ->assertJsonPath('data.effects.bass_boost', 30)
            ->assertJsonPath('data.active_preset', null);

        $file->refresh();

        $this->assertSame(0.8, $file->effectSettings()['speed']);
        $this->assertSame(2.5, $file->effectiveDuration());
        $this->assertSame(0.0, $file->semitoneShift());
    }

    public function test_an_unknown_preset_is_cleared(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);

        $this->actingAs($user)->patchJson(route('audio.effects.update', $file), [
            'speed' => 1,
            'pitch_enabled' => false,
            'pitch_multiplier' => 1,
            'reverb' => 0,
            'bass_boost' => 0,
            'preset_name' => 'does-not-exist',
        ])->assertUnprocessable()->assertJsonValidationErrors('preset_name');
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);

        $this->actingAs($user)->patchJson(route('audio.effects.update', $file), [
            'speed' => 9,
            'pitch_enabled' => true,
            'pitch_multiplier' => 4,
            'reverb' => 101,
            'bass_boost' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'speed', 'pitch_multiplier', 'reverb', 'bass_boost',
        ]);
    }

    public function test_another_user_cannot_change_effects(): void
    {
        $file = $this->makeAudioFile(User::factory()->create());

        $this->actingAs(User::factory()->create())->patchJson(route('audio.effects.update', $file), [
            'speed' => 1.2,
            'pitch_enabled' => false,
            'pitch_multiplier' => 1,
            'reverb' => 10,
            'bass_boost' => 10,
        ])->assertForbidden();
    }
}
