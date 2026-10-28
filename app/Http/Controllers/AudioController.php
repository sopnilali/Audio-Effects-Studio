<?php

namespace App\Http\Controllers;

use App\Exceptions\AudioProcessingException;
use App\Http\Requests\CompleteAudioUploadRequest;
use App\Http\Requests\PresignAudioUploadRequest;
use App\Http\Requests\RenameAudioRequest;
use App\Http\Requests\UploadAudioRequest;
use App\Models\AudioFile;
use App\Services\Audio\AudioFileService;
use App\Services\Audio\AudioStorageService;
use App\Services\Audio\FfmpegRunner;
use App\Services\Audio\S3UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AudioController extends Controller
{
    public function __construct(
        private readonly AudioFileService $audioFiles,
        private readonly FfmpegRunner $ffmpeg,
        private readonly AudioStorageService $storage,
        private readonly S3UploadService $uploads,
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

    public function presign(PresignAudioUploadRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $extension = $this->audioFiles->extensionFromName((string) $validated['name']);

        try {
            $this->audioFiles->assertCanUpload($user, (int) $validated['size']);

            $key = 'audio/'.$user->id.'/'.Str::uuid().'.'.$extension;
            $contentType = (string) (($validated['type'] ?? null) ?: 'application/octet-stream');
            $presigned = $this->uploads->presign($key, $contentType);
        } catch (AudioProcessingException $exception) {
            return response()->json([
                'message' => $exception->safeMessage(),
                'errors' => ['audio' => [$exception->safeMessage()]],
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            Log::error('Unable to create a presigned upload URL', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Large uploads are temporarily unavailable. Please try again later.',
                'errors' => ['audio' => ['Large uploads are temporarily unavailable. Please try again later.']],
            ], 422);
        }

        return response()->json([
            'data' => [
                'key' => $key,
                'url' => $presigned['url'],
                'headers' => $presigned['headers'],
                'expires_in' => $presigned['expires_in'],
            ],
        ]);
    }

    public function complete(CompleteAudioUploadRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $key = (string) $validated['key'];

        if (AudioFile::query()->where('storage_path', $key)->exists()) {
            return response()->json([
                'message' => 'This upload was already finalized.',
                'errors' => ['audio' => ['This upload was already finalized.']],
            ], 422);
        }

        try {
            if (! $this->storage->exists($key)) {
                throw new AudioProcessingException(
                    'The uploaded object is missing.',
                    'The upload did not reach storage. Please try again.',
                );
            }

            $actualSize = $this->storage->size($key);

            if ($actualSize !== (int) $validated['size']) {
                throw new AudioProcessingException(
                    'The uploaded object size did not match.',
                    'The uploaded file was incomplete. Please try again.',
                );
            }

            $audioFile = $this->audioFiles->storeFromStorage(
                $key,
                (string) $validated['name'],
                $actualSize,
                $user,
            );
        } catch (AudioProcessingException $exception) {
            $this->storage->delete($key);

            return response()->json([
                'message' => $exception->safeMessage(),
                'errors' => ['audio' => [$exception->safeMessage()]],
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            $this->storage->delete($key);
            Log::error('Unexpected direct upload finalization failure', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'This file could not be uploaded.',
                'errors' => ['audio' => ['This file could not be uploaded.']],
            ], 422);
        }

        return response()->json([
            'data' => ['files' => [$audioFile->toStudioArray()]],
            'errors' => [],
            'message' => 'Audio file uploaded successfully.',
        ], 201);
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

    public function stream(AudioFile $audioFile): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('stream', $audioFile);

        if (! $audioFile->hasPreview()) {
            abort(404, 'The audio preview is not available yet.');
        }

        return $this->storage->streamResponse((string) $audioFile->preview_path, 'audio/mpeg');
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
            'upload' => [
                'direct' => $this->uploads->isConfigured(),
                'max_mb' => (int) config('audio.max_upload_mb', 100),
            ],
            'routes' => [
                'upload' => route('audio.upload'),
                'uploadPresign' => route('audio.upload.presign'),
                'uploadComplete' => route('audio.upload.complete'),
                'clear' => route('audio.clear'),
                'index' => route('audio.index'),
            ],
        ];

        return view('audio.index', [
            'audioFiles' => $files,
            'selectedFile' => $selected,
            'studio' => $studio,
        ]);
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
