<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportAudioRequest;
use App\Models\AudioFile;
use App\Services\Audio\AudioEffectsService;
use App\Services\Audio\AudioExportService;
use Illuminate\Http\JsonResponse;

class AudioExportController extends Controller
{
    public function __construct(
        private readonly AudioExportService $exports,
        private readonly AudioEffectsService $effects,
    ) {}

    public function store(ExportAudioRequest $request, AudioFile $audioFile): JsonResponse
    {
        $this->authorize('export', $audioFile);

        $settings = $request->validated('settings') ?? $audioFile->effectSettings();
        $settings = $this->effects->normalize((array) $settings);

        $audioFile->forceFill(['effects' => $settings])->save();

        $result = $this->exports->create(
            $audioFile,
            $request->user(),
            (string) $request->validated('format'),
            $settings,
        );

        $job = $result['job'];

        return response()->json([
            'data' => [
                'job' => $job->toStatusArray(),
                'reused' => $result['reused'],
                'status_url' => route('audio.processing.status', $job),
                'cancel_url' => route('audio.processing.cancel', $job),
            ],
            'message' => $this->exportMessage($job->status, $result['reused']),
        ], 202);
    }

    private function exportMessage(string $status, bool $reused): string
    {
        if ($reused) {
            return 'An export for this file is already in progress.';
        }

        return match ($status) {
            'completed' => 'Your export is ready.',
            'failed' => 'The export failed.',
            default => 'Export queued. Your download will be ready shortly.',
        };
    }
}
