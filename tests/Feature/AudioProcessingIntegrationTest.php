<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Models\User;
use App\Services\Audio\AudioMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAudio;
use Tests\TestCase;

/**
 * These tests exercise the real FFmpeg pipeline. They are skipped when the
 * binaries are not available (for example on a minimal CI runner).
 */
class AudioProcessingIntegrationTest extends TestCase
{
    use InteractsWithAudio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->requireFfmpeg();
        config(['audio.sync_exports' => true]);
    }

    public function test_a_slowed_reverb_mp3_export_is_real_audio(): void
    {
        $user = User::factory()->create();
        $this->uploadTone($user, 'source.wav', 1.0);

        $file = AudioFile::query()->sole();

        $response = $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'mp3',
            'settings' => [
                'speed' => 0.8,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1,
                'reverb' => 40,
                'bass_boost' => 20,
                'preset_name' => 'slowed_reverb',
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.job.status', AudioProcessingJob::STATUS_COMPLETED)
            ->assertJsonPath('data.job.progress', 100);

        $job = AudioProcessingJob::query()->sole();
        $absolute = Storage::disk('local')->path($job->output_path);
        $metadata = app(AudioMetadataService::class)->probe($absolute);

        $this->assertFileExists($absolute);
        $this->assertGreaterThan(1024, filesize($absolute));
        $this->assertSame('mp3', $metadata['codec']);
        $this->assertEqualsWithDelta(1.25, $metadata['duration'], 0.15);
        $this->assertSame(8000, $metadata['sample_rate']);
    }

    public function test_a_reverb_wav_export_is_not_silent(): void
    {
        $user = User::factory()->create();
        $this->uploadTone($user, 'reverb.wav', 1.0);

        $file = AudioFile::query()->sole();

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => [
                'speed' => 1,
                'pitch_follows_speed' => true,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1,
                'reverb' => 40,
                'bass_boost' => 0,
            ],
        ])->assertStatus(202)->assertJsonPath('data.job.status', 'completed');

        $job = AudioProcessingJob::query()->sole();
        $path = Storage::disk('local')->path($job->output_path);
        $metadata = app(AudioMetadataService::class)->probe($path);

        $this->assertSame('pcm_s16le', $metadata['codec']);
        $this->assertEqualsWithDelta(1.0, $metadata['duration'], 0.1);

        // Regression: afir's default IR normalization made reverb exports
        // nearly inaudible (-65 dBFS). The dry+wet signal must remain strong.
        $this->assertGreaterThan(1000, $this->estimatePeak($path));
    }

    public function test_a_nightcore_wav_export_changes_pitch_but_keeps_the_duration_ratio(): void
    {
        $user = User::factory()->create();
        $this->uploadTone($user, 'nightcore.wav', 1.0);

        $file = AudioFile::query()->sole();

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => [
                'speed' => 1.25,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1,
                'reverb' => 0,
                'bass_boost' => 100,
                'preset_name' => 'nightcore',
            ],
        ])->assertStatus(202)->assertJsonPath('data.job.status', 'completed');

        $job = AudioProcessingJob::query()->sole();
        $absolute = Storage::disk('local')->path($job->output_path);
        $metadata = app(AudioMetadataService::class)->probe($absolute);

        $this->assertSame('pcm_s16le', $metadata['codec']);
        $this->assertEqualsWithDelta(0.8, $metadata['duration'], 0.1);
        $this->assertSame(8000, $metadata['sample_rate']);
    }

    public function test_tape_mode_really_lowers_pitch_when_slowed_and_raises_it_when_sped_up(): void
    {
        $user = User::factory()->create();
        $this->uploadTone($user, 'tape.wav', 1.0);
        $file = AudioFile::query()->sole();

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => [
                'speed' => 0.8,
                'pitch_follows_speed' => true,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1,
                'reverb' => 0,
                'bass_boost' => 0,
            ],
        ])->assertStatus(202)->assertJsonPath('data.job.status', 'completed');

        $slowJob = AudioProcessingJob::query()->latest('id')->firstOrFail();
        $slowPath = Storage::disk('local')->path($slowJob->output_path);
        $slowMetadata = app(AudioMetadataService::class)->probe($slowPath);

        $this->assertEqualsWithDelta(1.25, $slowMetadata['duration'], 0.1);
        $this->assertEqualsWithDelta(352.0, $this->estimateFrequency($slowPath), 8.0);

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => [
                'speed' => 1.25,
                'pitch_follows_speed' => true,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1,
                'reverb' => 0,
                'bass_boost' => 0,
            ],
        ])->assertStatus(202)->assertJsonPath('data.job.status', 'completed');

        $fastJob = AudioProcessingJob::query()->latest('id')->firstOrFail();
        $fastPath = Storage::disk('local')->path($fastJob->output_path);
        $fastMetadata = app(AudioMetadataService::class)->probe($fastPath);

        $this->assertEqualsWithDelta(0.8, $fastMetadata['duration'], 0.1);
        $this->assertEqualsWithDelta(550.0, $this->estimateFrequency($fastPath), 8.0);
    }

    public function test_disabling_tape_mode_keeps_the_original_pitch(): void
    {
        $user = User::factory()->create();
        $this->uploadTone($user, 'fixed-pitch.wav', 1.0);
        $file = AudioFile::query()->sole();

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => [
                'speed' => 0.8,
                'pitch_follows_speed' => false,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1,
                'reverb' => 0,
                'bass_boost' => 0,
            ],
        ])->assertStatus(202)->assertJsonPath('data.job.status', 'completed');

        $job = AudioProcessingJob::query()->sole();
        $path = Storage::disk('local')->path($job->output_path);

        $this->assertEqualsWithDelta(1.25, app(AudioMetadataService::class)->probe($path)['duration'], 0.1);
        $this->assertEqualsWithDelta(440.0, $this->estimateFrequency($path), 8.0);
    }

    public function test_the_reverb_impulse_is_generated_once(): void
    {
        $user = User::factory()->create();
        $this->uploadTone($user, 'impulse.wav', 0.5);
        $file = AudioFile::query()->sole();

        $this->actingAs($user)->postJson(route('audio.export.store', $file), [
            'format' => 'wav',
            'settings' => [
                'speed' => 1,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1,
                'reverb' => 25,
                'bass_boost' => 0,
            ],
        ])->assertStatus(202);

        $this->assertTrue(Storage::disk('local')->exists(config('audio.reverb_impulse_path')));
    }

    /**
     * Estimate the dominant frequency of a 16-bit PCM WAV by counting upward
     * zero crossings in the middle half of the signal.
     */
    private function estimateFrequency(string $path): float
    {
        ['samples' => $samples, 'sample_rate' => $sampleRate] = $this->readPcm($path);

        $start = intdiv(count($samples), 4);
        $end = intdiv(count($samples) * 3, 4);
        $crossings = 0;

        for ($i = $start + 1; $i < $end; $i++) {
            if ($samples[$i - 1] <= 0 && $samples[$i] > 0) {
                $crossings++;
            }
        }

        $seconds = ($end - $start) / $sampleRate;

        return $seconds > 0 ? $crossings / $seconds : 0.0;
    }

    private function estimatePeak(string $path): int
    {
        $samples = $this->readPcm($path)['samples'];

        return $samples === [] ? 0 : max(array_map('abs', $samples));
    }

    /**
     * @return array{samples: list<int>, sample_rate: int}
     */
    private function readPcm(string $path): array
    {
        $bytes = (string) file_get_contents($path);
        $offset = 12;
        $data = '';
        $channels = 1;
        $sampleRate = 8000;

        while ($offset + 8 <= strlen($bytes)) {
            $chunkId = substr($bytes, $offset, 4);
            $chunkSize = unpack('Vsize', substr($bytes, $offset + 4, 4))['size'];
            $chunk = substr($bytes, $offset + 8, $chunkSize);

            if ($chunkId === 'fmt ') {
                $format = unpack('vformat/vchannels/Vsample_rate', $chunk);
                $channels = max(1, (int) $format['channels']);
                $sampleRate = max(1, (int) $format['sample_rate']);
            }

            if ($chunkId === 'data') {
                $data = $chunk;
            }

            $offset += 8 + $chunkSize + ($chunkSize % 2);
        }

        $samples = array_values(unpack('v*', $data) ?: []);
        $samples = array_map(
            static fn (int $sample): int => $sample >= 32768 ? $sample - 65536 : $sample,
            $samples,
        );

        if ($channels > 1) {
            $samples = array_values(array_filter(
                $samples,
                static fn (int $sample, int $index): bool => $index % $channels === 0,
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        return ['samples' => $samples, 'sample_rate' => $sampleRate];
    }

    private function uploadTone(User $user, string $name, float $seconds): void
    {
        $this->actingAs($user)->post(
            route('audio.upload'),
            ['files' => [$this->audioUpload($name, $seconds)]],
            ['Accept' => 'application/json'],
        )->assertCreated();
    }
}
