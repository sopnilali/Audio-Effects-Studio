<?php

namespace App\Http\Controllers;

use App\Exceptions\AudioProcessingException;
use App\Models\AudioProcessingJob;
use App\Services\Audio\AudioExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioProcessingController extends Controller
{
    public function __construct(private readonly AudioExportService $exports) {}

    public function status(AudioProcessingJob $job): JsonResponse
    {
        $this->authorize('view', $job);

        $job->refresh();

        return response()->json([
            'data' => $job->toStatusArray(),
            'message' => match ($job->status) {
                AudioProcessingJob::STATUS_COMPLETED => 'Export complete.',
                AudioProcessingJob::STATUS_FAILED => $job->error_message ?: 'Export failed.',
                AudioProcessingJob::STATUS_CANCELLED => 'Export cancelled.',
                AudioProcessingJob::STATUS_PROCESSING => 'Export in progress.',
                default => 'Export queued.',
            },
        ]);
    }

    public function download(AudioProcessingJob $job): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $this->authorize('download', $job);

        try {
            return $this->exports->downloadResponse($job);
        } catch (AudioProcessingException $exception) {
            return response()->json([
                'message' => $exception->safeMessage(),
            ], 409);
        }
    }

    public function cancel(AudioProcessingJob $job): JsonResponse
    {
        $this->authorize('cancel', $job);

        $cancelled = $this->exports->cancel($job);

        return response()->json([
            'data' => $cancelled->toStatusArray(),
            'message' => 'Export cancelled.',
        ]);
    }
}
