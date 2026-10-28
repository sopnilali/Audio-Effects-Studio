<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAudio;
use Tests\TestCase;

class AudioLibraryTest extends TestCase
{
    use InteractsWithAudio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_studio_only_lists_the_signed_in_users_files(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->makeAudioFile($user, ['display_name' => 'my-song.wav']);
        $this->makeAudioFile($other, ['display_name' => 'their-song.wav']);

        $response = $this->actingAs($user)->get(route('audio.index'));

        $response->assertOk()
            ->assertSee('my-song.wav')
            ->assertDontSee('their-song.wav')
            ->assertViewHas('selectedFile', fn (?AudioFile $selected): bool => $selected?->id === $mine->id);
    }

    public function test_a_user_cannot_view_another_users_audio_page(): void
    {
        $user = User::factory()->create();
        $otherFile = $this->makeAudioFile(User::factory()->create());

        $this->actingAs($user)->get(route('audio.show', $otherFile))->assertForbidden();
    }

    public function test_metadata_returns_the_studio_payload(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user, ['display_name' => 'meta.wav']);

        $this->actingAs($user)
            ->getJson(route('audio.metadata', $file))
            ->assertOk()
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.display_name', 'meta.wav')
            ->assertJsonPath('data.stream_url', route('audio.stream', $file))
            ->assertJsonStructure(['data' => ['waveform', 'effects'], 'environment' => ['ok', 'filters']]);
    }

    public function test_metadata_is_forbidden_for_another_user(): void
    {
        $file = $this->makeAudioFile(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->getJson(route('audio.metadata', $file))
            ->assertForbidden();
    }

    public function test_the_preview_can_be_streamed_with_range_support(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);

        $this->actingAs($user)
            ->get(route('audio.stream', $file), ['Range' => 'bytes=0-3'])
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_streaming_requires_ownership_and_a_preview(): void
    {
        $owner = User::factory()->create();
        $file = $this->makeAudioFile($owner);

        $this->actingAs(User::factory()->create())
            ->get(route('audio.stream', $file))
            ->assertForbidden();

        $withoutPreview = $this->makeAudioFile($owner, ['preview_path' => null]);

        $this->actingAs($owner)->get(route('audio.stream', $withoutPreview))->assertNotFound();
    }

    public function test_a_file_can_be_renamed_and_keeps_its_extension(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user, ['display_name' => 'old.wav']);

        $this->actingAs($user)
            ->patchJson(route('audio.rename', $file), ['display_name' => 'নতুন নাম'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'নতুন নাম.wav');

        $this->assertDatabaseHas('audio_files', [
            'id' => $file->id,
            'display_name' => 'নতুন নাম.wav',
        ]);
    }

    public function test_renaming_is_forbidden_for_another_user(): void
    {
        $file = $this->makeAudioFile(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->patchJson(route('audio.rename', $file), ['display_name' => 'stolen.wav'])
            ->assertForbidden();
    }

    public function test_deleting_a_file_removes_its_source_preview_jobs_and_exports(): void
    {
        $user = User::factory()->create();
        $file = $this->makeAudioFile($user);
        $job = $this->makeProcessingJob($file, ['status' => 'completed', 'format' => 'wav']);

        $this->assertTrue(Storage::disk('local')->exists($file->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($file->preview_path));
        $this->assertTrue(Storage::disk('local')->exists($job->output_path));

        $this->actingAs($user)
            ->deleteJson(route('audio.destroy', $file))
            ->assertOk();

        $this->assertDatabaseMissing('audio_files', ['id' => $file->id]);
        $this->assertDatabaseMissing('audio_processing_jobs', ['id' => $job->id]);
        $this->assertFalse(Storage::disk('local')->exists($file->storage_path));
        $this->assertFalse(Storage::disk('local')->exists($file->preview_path));
        $this->assertFalse(Storage::disk('local')->exists($job->output_path));
    }

    public function test_deleting_is_forbidden_for_another_user(): void
    {
        $file = $this->makeAudioFile(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->deleteJson(route('audio.destroy', $file))
            ->assertForbidden();

        $this->assertDatabaseHas('audio_files', ['id' => $file->id]);
    }

    public function test_clearing_the_workspace_only_removes_the_users_files(): void
    {
        $user = User::factory()->create();
        $mine = $this->makeAudioFile($user);
        $other = $this->makeAudioFile(User::factory()->create());

        $this->actingAs($user)
            ->deleteJson(route('audio.clear'))
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('audio_files', ['id' => $mine->id]);
        $this->assertDatabaseHas('audio_files', ['id' => $other->id]);
    }
}
