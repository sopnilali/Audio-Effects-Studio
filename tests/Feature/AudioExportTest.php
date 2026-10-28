<?php

namespace Tests\Feature;

use App\Jobs\ExportAudioJob;
use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Models\User;
use App\Services\Audio\AudioExportService;
use App\Services\Audio\FfmpegRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAudio;
use Tests\TestCase;

class AudioExportTest extends TestCase
{
    use InteractsWithAudio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeFfmpegEnvironment();
    }

    public function test_an_export_is_queued_with_the_requested_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);

        $response = $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'mp3',
            'settings' => [
                'speed' => 0.8,
                'pitch_enabled' => true,
                'pitch_multiplier' => 1.25,
                'reverb' => 40,
                'bass_boost' => 30,
                'preset_name' => 'slowed_reverb',
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.job.status', AudioProcessingJob::STATUS_PENDING)
            ->assertJsonPath('data.job.format', 'mp3')
            ->assertJsonPath('data.reused', false)
            ->assertJsonStructure(['data' => ['job' => ['id', 'status', 'progress'], 'status_url', 'cancel_url']]);

        $job = AudioProcessingJob::query()->sole();

        $this->assertSame($file->id, $job->audio_file_id);
        $this->assertSame($user->id, $job->user_id);
        $this->assertSame('mp3', $job->format);
        $this->assertSame(0.8, $job->settings['speed']);
        $this->assertSame(40, $job->settings['reverb']);
        $this->assertSame(0.8, $file->fresh()->effectSettings()['speed']);

        Queue::assertPushed(ExportAudioJob::class);
    }

    public function test_a_second_request_reuses_the_active_export(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);
        $payload = ['format' => 'wav', 'settings' => config('audio.default_effects')];

        $first = $this->actingAs($user)->postJson(route('audio.export.store', $file), $payload)->assertStatus(202);
        $second = $this->actingAs($user)->postJson(route('audio.export.store', $file), $payload)->assertStatus(202);

        $this->assertSame($first->json('data.job.id'), $second->json('data.job.id'));
        $this->assertTrue($second->json('data.reused'));
        $this->assertSame(1, AudioProcessingJob::query()->count());
    }

    public function test_changing_settings_creates_a_new_export_instead_of_reusing_the_old_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);

        $first = $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => config('audio.default_effects'),
        ])->assertStatus(202);

        $second = $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => [
                'speed' => 0.8,
                'pitch_follows_speed' => true,
                'pitch_enabled' => true,
                'pitch_multiplier' => 1.25,
                'reverb' => 40,
                'bass_boost' => 30,
                'preset_name' => 'slowed_reverb',
            ],
        ])->assertStatus(202);

        $this->assertFalse($second->json('data.reused'));
        $this->assertNotSame($first->json('data.job.id'), $second->json('data.job.id'));
        $this->assertSame(2, AudioProcessingJob::query()->count());

        $job = AudioProcessingJob::query()->latest('id')->firstOrFail();
        $this->assertSame(0.8, $job->settings['speed']);
        $this->assertTrue($job->settings['pitch_enabled']);
        $this->assertSame(1.25, $job->settings['pitch_multiplier']);
        $this->assertSame(40, $job->settings['reverb']);
        $this->assertSame(30, $job->settings['bass_boost']);
        $this->assertSame(0.8, $file->fresh()->effectSettings()['speed']);
    }

    public function test_changing_format_creates_a_new_export_instead_of_reusing_the_old_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);
        $settings = config('audio.default_effects');

        $first = $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => $settings,
        ])->assertStatus(202);

        $second = $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'mp3',
            'settings' => $settings,
        ])->assertStatus(202);

        $this->assertFalse($second->json('data.reused'));
        $this->assertNotSame($first->json('data.job.id'), $second->json('data.job.id'));
        $this->assertSame('mp3', AudioProcessingJob::query()->latest('id')->firstOrFail()->format);
    }

    public function test_an_unsupported_format_is_rejected(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'flac',
        ])->assertUnprocessable()->assertJsonValidationErrors('format');
    }

    public function test_a_file_that_is_not_ready_cannot_be_exported(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user, ['status' => AudioFile::STATUS_PROCESSING]);

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
        ])->assertUnprocessable()->assertJsonPath('errors.audio.0', 'This audio file is still being prepared. Please wait a moment and try again.');
    }

    public function test_another_user_cannot_export_or_read_the_job(): void
    {
        $file = $this->makeAudioFile(User::factory()->create());
        $job = $this->makeProcessingJob($file);

        $this->actingAs(User::factory()->create())
            ->postJson(route('audio.export.store', $file), ['format' => 'wav'])
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->getJson(route('audio.processing.status', $job))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('audio.processing.download', $job))
            ->assertForbidden();
    }

    public function test_status_and_download_work_for_a_completed_export(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user, ['display_name' => 'গানের ফাইল.wav']);
        $job = $this->makeProcessingJob($file, [
            'status' => AudioProcessingJob::STATUS_COMPLETED,
            'format' => 'mp3',
            'output_path' => 'exports/'.$user->id.'/ready.mp3',
        ]);

        $this->actingAs($user)
            ->getJson(route('audio.processing.status', $job))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.cancellable', false)
            ->assertJsonPath('data.download_url', route('audio.processing.download', $job))
            ->assertJsonPath('data.download_name', 'গানের ফাইল - Audio Effects Studio.mp3');

        $response = $this->actingAs($user)->get(route('audio.processing.download', $job));

        $response->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringContainsString('%E0%A6%97', $disposition);
    }

    public function test_a_pending_download_returns_conflict(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);
        $job = $this->makeProcessingJob($file);

        $this->actingAs($user)
            ->getJson(route('audio.processing.download', $job))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This export is not ready or has expired.');
    }

    public function test_a_pending_export_can_be_cancelled(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);
        $job = $this->makeProcessingJob($file);

        $this->actingAs($user)
            ->postJson(route('audio.processing.cancel', $job))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(AudioProcessingJob::STATUS_CANCELLED, $job->fresh()->status);
    }

    public function test_a_running_export_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);
        $job = $this->makeProcessingJob($file, ['status' => AudioProcessingJob::STATUS_PROCESSING]);

        $this->actingAs($user)
            ->postJson(route('audio.processing.cancel', $job))
            ->assertStatus(422);
    }

    public function test_expired_exports_are_cleaned_up(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);
        $job = $this->makeProcessingJob($file, [
            'status' => AudioProcessingJob::STATUS_COMPLETED,
            'output_path' => 'exports/'.$user->id.'/expired.wav',
        ]);

        $job->forceFill(['updated_at' => now()->subDays(2)])->save();

        $result = app(AudioExportService::class)->cleanupExpired();

        $this->assertSame(1, $result['jobs']);
        $this->assertSame(1, $result['files']);
        $this->assertDatabaseMissing('audio_processing_jobs', ['id' => $job->id]);
        $this->assertFalse(Storage::disk('local')->exists('exports/'.$user->id.'/expired.wav'));
    }

    private function fakeFfmpegEnvironment(): void
    {
        $environment = [
            'ok' => true,
            'ffmpeg' => true,
            'ffprobe' => true,
            'mp3_encoder' => true,
            'filters' => ['atempo', 'asetrate', 'aresample', 'lowshelf', 'afir', 'alimiter', 'anull'],
            'version' => 'ffmpeg test',
            'errors' => [],
        ];

        $this->mock(FfmpegRunner::class, function ($mock) use ($environment): void {
            $mock->shouldReceive('environment')->andReturn($environment);
        });
    }
}
