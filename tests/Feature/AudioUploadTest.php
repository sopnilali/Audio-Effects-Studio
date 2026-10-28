<?php

namespace Tests\Feature;

use App\Jobs\ProcessAudioJob;
use App\Models\AudioFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAudio;
use Tests\TestCase;

class AudioUploadTest extends TestCase
{
    use InteractsWithAudio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->requireFfmpeg();
    }

    public function test_a_valid_audio_file_is_stored_with_a_real_preview_and_waveform(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [$this->audioUpload('গানের ফাইল.wav', 1.0)]],
            ['Accept' => 'application/json'],
        );

        $response->assertCreated()
            ->assertJsonPath('data.files.0.original_name', 'গানের ফাইল.wav')
            ->assertJsonPath('data.files.0.display_name', 'গানের ফাইল.wav')
            ->assertJsonPath('data.files.0.status', AudioFile::STATUS_READY)
            ->assertJsonPath('data.files.0.extension', 'WAV')
            ->assertJsonStructure([
                'data' => [
                    'files' => [[
                        'id', 'original_name', 'display_name', 'stream_url', 'waveform',
                        'duration_seconds', 'sample_rate', 'channels', 'effects',
                    ]],
                ],
                'message',
            ]);

        $file = AudioFile::query()->sole();

        $this->assertSame($user->id, $file->user_id);
        $this->assertSame('গানের ফাইল.wav', $file->original_name);
        $this->assertTrue($file->isReady());
        $this->assertNotEmpty($file->waveformPeaks());
        $this->assertGreaterThan(32, count($file->waveformPeaks()));
        $this->assertTrue(Storage::disk('local')->exists($file->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($file->preview_path));
        $this->assertContains($file->mime_type, ['audio/wav', 'audio/x-wav', 'audio/wave']);
        $this->assertNotNull($response->json('data.files.0.stream_url'));
    }

    public function test_the_waveform_values_are_normalized_peaks(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [$this->audioUpload('peaks.wav', 1.0)]],
            ['Accept' => 'application/json'],
        )->assertCreated();

        $peaks = AudioFile::query()->sole()->waveformPeaks();

        $this->assertNotEmpty($peaks);
        $this->assertEqualsWithDelta(1.0, max($peaks), 0.001);
        $this->assertGreaterThanOrEqual(0.0, min($peaks));
    }

    public function test_unsupported_files_are_rejected_by_validation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [UploadedFile::fake()->create('notes.txt', 4, 'text/plain')]],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('files.0');

        $this->assertDatabaseCount('audio_files', 0);
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('audio.upload'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files');
    }

    public function test_files_over_the_configured_upload_size_are_rejected(): void
    {
        config(['audio.max_upload_mb' => 1]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [UploadedFile::fake()->create('huge.wav', 2048, 'audio/wav')]],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('files.0');

        $this->assertDatabaseCount('audio_files', 0);
    }

    public function test_audio_longer_than_the_limit_is_rejected_and_removed(): void
    {
        config(['audio.max_duration_seconds' => 1]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [$this->audioUpload('long.wav', 2.0)]],
            ['Accept' => 'application/json'],
        );

        $response->assertUnprocessable();
        $this->assertStringContainsString('longer', strtolower((string) $response->json('errors.0.message')));
        $this->assertDatabaseCount('audio_files', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('audio'));
    }

    public function test_the_per_user_file_quota_is_enforced(): void
    {
        config(['audio.max_files_per_user' => 1]);
        $user = User::factory()->create();
        $this->makeAudioFile($user);

        $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [$this->audioUpload('second.wav', 1.0)]],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->assertSame(1, AudioFile::query()->count());
    }

    public function test_large_files_are_queued_for_preparation(): void
    {
        Queue::fake();
        config(['audio.large_upload_threshold_bytes' => 1]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [$this->audioUpload('queued.wav', 1.0)]],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.files.0.status', AudioFile::STATUS_PROCESSING);

        $this->assertDatabaseHas('audio_processing_jobs', [
            'user_id' => $user->id,
            'job_type' => 'preview',
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessAudioJob::class);
    }
}
