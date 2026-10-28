<?php

namespace Tests\Unit;

use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_effect_settings_merge_defaults_and_canonical_types(): void
    {
        $file = new AudioFile([
            'effects' => [
                'speed' => '1.25',
                'pitch_enabled' => 1,
                'pitch_multiplier' => '1.2599',
                'reverb' => '40.4',
                'bass_boost' => '12.6',
                'preset_name' => 'nightcore',
            ],
        ]);

        $this->assertSame([
            'speed' => 1.25,
            'pitch_follows_speed' => true,
            'pitch_enabled' => true,
            'pitch_multiplier' => 1.26,
            'reverb' => 40,
            'bass_boost' => 13,
            'preset_name' => 'nightcore',
        ], $file->effectSettings());
    }

    public function test_effective_duration_and_formatting(): void
    {
        $file = new AudioFile([
            'duration_seconds' => 180.0,
            'effects' => ['speed' => 0.8],
        ]);

        $this->assertSame(225.0, $file->effectiveDuration());
        $this->assertSame('3:00', $file->formattedDuration());
        $this->assertSame('3:45', $file->formattedEffectiveDuration());
    }

    public function test_semitone_shift_and_human_size(): void
    {
        $file = new AudioFile([
            'effects' => ['pitch_enabled' => true, 'pitch_multiplier' => 1.2599],
            'file_size' => 2 * 1024 * 1024,
        ]);

        $this->assertSame(4.0, $file->semitoneShift());
        $this->assertSame('2.0 MB', $file->humanFileSize());
    }

    public function test_tape_mode_makes_slower_audio_deeper_and_faster_audio_thinner(): void
    {
        $slow = new AudioFile([
            'effects' => ['speed' => 0.8, 'pitch_follows_speed' => true, 'pitch_enabled' => false],
        ]);
        $fast = new AudioFile([
            'effects' => ['speed' => 1.25, 'pitch_follows_speed' => true, 'pitch_enabled' => false],
        ]);

        $this->assertSame(0.8, $slow->effectivePitchMultiplier());
        $this->assertSame(-3.9, $slow->semitoneShift());
        $this->assertSame(1.25, $fast->effectivePitchMultiplier());
        $this->assertSame(3.9, $fast->semitoneShift());
    }

    public function test_bengali_names_are_preserved_for_downloads(): void
    {
        $file = new AudioFile(['display_name' => 'গানের ফাইল.wav']);

        $this->assertSame('গানের ফাইল', $file->downloadBasename());
    }

    public function test_download_basenames_are_safe_and_bounded(): void
    {
        $file = new AudioFile(['display_name' => '../../etc/passwd.wav']);
        $base = $file->downloadBasename();

        $this->assertStringNotContainsString('/', $base);
        $this->assertStringNotContainsString('\\', $base);
        $this->assertStringNotContainsString('..', $base);

        $long = new AudioFile(['display_name' => str_repeat('a', 300).'.wav']);
        $this->assertLessThanOrEqual(120, mb_strlen($long->downloadBasename()));

        $empty = new AudioFile(['display_name' => '...wav']);
        $this->assertSame('audio', $empty->downloadBasename());
    }

    public function test_waveform_peaks_are_returned_as_a_list(): void
    {
        $file = new AudioFile(['waveform' => [0.1, 0.2, 0.3]]);

        $this->assertSame([0.1, 0.2, 0.3], $file->waveformPeaks());

        $file->waveform = null;
        $this->assertSame([], $file->waveformPeaks());
    }

    public function test_processing_job_state_transitions(): void
    {
        $job = AudioProcessingJob::factory()->for(User::factory())->create([
            'status' => AudioProcessingJob::STATUS_PENDING,
            'progress' => 0,
        ]);

        $this->assertTrue($job->isPending());
        $this->assertTrue($job->isCancellable());
        $this->assertFalse($job->isCompleted());

        $job->markProcessing();
        $this->assertSame(AudioProcessingJob::STATUS_PROCESSING, $job->status);
        $this->assertFalse($job->isCancellable());

        $job->markCompleted('exports/1/file.wav');
        $this->assertSame(AudioProcessingJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(100, $job->progress);
        $this->assertSame('exports/1/file.wav', $job->output_path);
    }

    public function test_processing_job_failure_message_is_bounded(): void
    {
        $job = AudioProcessingJob::factory()->for(User::factory())->create([
            'status' => AudioProcessingJob::STATUS_PENDING,
        ]);

        $job->markFailed(str_repeat('x', 1200));

        $this->assertSame(AudioProcessingJob::STATUS_FAILED, $job->status);
        $this->assertSame(1000, mb_strlen($job->error_message));
    }

    public function test_job_status_payload_includes_a_download_url_only_when_the_output_exists(): void
    {
        $job = AudioProcessingJob::factory()->for(User::factory())->create([
            'status' => AudioProcessingJob::STATUS_COMPLETED,
            'progress' => 100,
            'format' => 'wav',
            'output_path' => 'exports/1/file.wav',
        ]);

        $this->assertNull($job->toStatusArray()['download_url']);

        Storage::disk('local')->put('exports/1/file.wav', 'audio');

        $this->assertSame(
            route('audio.processing.download', $job),
            $job->toStatusArray()['download_url'],
        );
    }
}
