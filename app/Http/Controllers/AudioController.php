<?php

namespace App\Http\Controllers;

use App\Exceptions\AudioProcessingException;
use App\Http\Requests\RenameAudioRequest;
use App\Http\Requests\UploadAudioRequest;
use App\Models\AudioFile;
use App\Services\Audio\AudioFileService;
use App\Services\Audio\FfmpegRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AudioController extends Controller
{
    public function __construct(
        private readonly AudioFileService $audioFiles,
        private readonly FfmpegRunner $ffmpeg,
    ) {}

    public function index(Request $request): View
    {
        return $this->renderStudio($request);
    }

    public function show(Request $request, AudioFile $audioFile): View
    {
        $this->authorize('view', $audioFile);

        return $this->renderStudio($request, $audioFile);
    }

    public function store(UploadAudioRequest $request): JsonResponse
    {
        $stored = [];
        $errors = [];

        foreach ($request->file('files', []) as $uploadedFile) {
            try {
                $audioFile = $this->audioFiles->store($uploadedFile, $request->user());
                $stored[] = $audioFile->toStudioArray();
            } catch (AudioProcessingException $exception) {
                $errors[] = [
                    'name' => $uploadedFile->getClientOriginalName(),
                    'message' => $exception->safeMessage(),
                ];
            } catch (Throwable $exception) {
                Log::error('Unexpected audio upload failure', [
                    'message' => $exception->getMessage(),
                ]);

                $errors[] = [
                    'name' => $uploadedFile->getClientOriginalName(),
                    'message' => 'This file could not be uploaded.',
                ];
            }
        }

        $status = count($stored) > 0 ? 201 : 422;

        return response()->json([
            'data' => ['files' => $stored],
            'errors' => $errors,
            'message' => $this->uploadMessage($stored, $errors),
        ], $status);
    }

    public function metadata(AudioFile $audioFile): JsonResponse
    {
        $this->authorize('view', $audioFile);

        $audioFile->forceFill(['last_accessed_at' => now()])->save();

        return response()->json([
            'data' => $audioFile->fresh()->toStudioArray(),
            'environment' => $this->ffmpeg->environment(),
        ]);
    }

    public function rename(RenameAudioRequest $request, AudioFile $audioFile): JsonResponse
    {
        $this->authorize('update', $audioFile);

        $renamed = $this->audioFiles->rename($audioFile, (string) $request->validated('display_name'));

        return response()->json([
            'data' => $renamed->toStudioArray(),
            'message' => 'Audio file renamed.',
        ]);
    }

    public function destroy(Request $request, AudioFile $audioFile): JsonResponse|Response
    {
        $this->authorize('delete', $audioFile);

        $this->audioFiles->delete($audioFile);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Audio file deleted.']);
        }

        return response()->noContent();
    }

    public function clear(Request $request): JsonResponse
    {
        $deleted = $this->audioFiles->clear($request->user());

        return response()->json([
            'message' => $deleted > 0 ? "{$deleted} audio file(s) removed." : 'The workspace is already empty.',
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function stream(AudioFile $audioFile): BinaryFileResponse
    {
        $this->authorize('stream', $audioFile);

        if (! $audioFile->hasPreview()) {
            abort(404, 'The audio preview is not available yet.');
        }

        $absolutePath = Storage::disk('local')->path((string) $audioFile->preview_path);

        return response()->file($absolutePath, [
            'Content-Type' => 'audio/mpeg',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function renderStudio(Request $request, ?AudioFile $selected = null): View
    {
        $files = AudioFile::query()
            ->ownedBy($request->user())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($selected && $selected->user_id !== $request->user()->id) {
            abort(403);
        }

        $selected ??= $files->firstWhere('id', (int) $request->query('file')) ?? $files->first();

        $studio = [
            'files' => $files->map(fn (AudioFile $file): array => $file->toStudioArray())->values()->all(),
            'selectedId' => $selected?->id,
            'presets' => config('audio.presets'),
            'defaults' => config('audio.default_effects'),
            'limits' => [
                'speed' => config('audio.speed'),
                'pitch' => config('audio.pitch'),
                'reverb' => config('audio.reverb'),
                'bass_boost' => config('audio.bass_boost'),
                'max_upload_mb' => config('audio.max_upload_mb'),
                'max_files' => config('audio.max_files_per_upload'),
                'max_duration_seconds' => config('audio.max_duration_seconds'),
                'formats' => config('audio.export_formats'),
            ],
            'environment' => $this->ffmpeg->environment(),
            'routes' => [
                'upload' => route('audio.upload'),
                'clear' => route('audio.clear'),
                'index' => route('audio.index'),
            ],
            'user' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'initials' => $this->initials($request->user()->name),
            ],
        ];

        return view('audio.index', [
            'audioFiles' => $files,
            'selectedFile' => $selected,
            'studio' => $studio,
        ]);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'A';
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     * @param  list<array{name: string, message: string}>  $errors
     */
    private function uploadMessage(array $stored, array $errors): string
    {
        if ($stored !== [] && $errors === []) {
            return count($stored) === 1
                ? 'Audio file uploaded successfully.'
                : count($stored).' audio files uploaded successfully.';
        }

        if ($stored !== []) {
            return count($stored).' uploaded, '.count($errors).' failed.';
        }

        return 'No audio files could be uploaded.';
    }
}
