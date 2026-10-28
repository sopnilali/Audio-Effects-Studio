<?php

namespace Database\Factories;

use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AudioProcessingJob>
 */
class AudioProcessingJobFactory extends Factory
{
    protected $model = AudioProcessingJob::class;

    public function definition(): array
    {
        return [
            'audio_file_id' => AudioFile::factory(),
            'user_id' => User::factory(),
            'job_type' => AudioProcessingJob::TYPE_EXPORT,
            'input_path' => 'audio/1/source.mp3',
            'output_path' => 'exports/1/'.Str::uuid().'.wav',
            'format' => 'wav',
            'settings' => config('audio.default_effects'),
            'status' => AudioProcessingJob::STATUS_PENDING,
            'progress' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => AudioProcessingJob::STATUS_COMPLETED,
            'progress' => 100,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => AudioProcessingJob::STATUS_FAILED,
            'error_message' => 'The export failed.',
            'completed_at' => now(),
        ]);
    }
}
