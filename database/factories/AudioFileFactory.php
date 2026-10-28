<?php

namespace Database\Factories;

use App\Models\AudioFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AudioFile>
 */
class AudioFileFactory extends Factory
{
    protected $model = AudioFile::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true).'.mp3';

        return [
            'user_id' => User::factory(),
            'original_name' => $name,
            'display_name' => $name,
            'storage_path' => 'audio/1/'.Str::uuid().'.mp3',
            'preview_path' => 'previews/1/'.Str::uuid().'.mp3',
            'mime_type' => 'audio/mpeg',
            'extension' => 'mp3',
            'file_size' => $this->faker->numberBetween(500_000, 5_000_000),
            'duration_seconds' => $this->faker->randomFloat(3, 10, 300),
            'sample_rate' => 44100,
            'channels' => 2,
            'bit_rate' => 192000,
            'codec' => 'mp3',
            'container' => 'mp3',
            'status' => AudioFile::STATUS_READY,
            'effects' => config('audio.default_effects'),
            'waveform' => array_map(fn (): float => $this->faker->randomFloat(3, 0, 1), range(1, 64)),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => AudioFile::STATUS_FAILED,
            'error_message' => 'The audio could not be prepared.',
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => AudioFile::STATUS_PROCESSING,
            'preview_path' => null,
            'waveform' => null,
        ]);
    }
}
