<?php

namespace App\Jobs;

use App\Exceptions\AudioProcessingException;
use App\Models\AudioProcessingJob;
use App\Services\Audio\AudioExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly int $processingJobId) {}

    public function handle(AudioExportService $exports): void
    {
        $job = AudioProcessingJob::find($this->processingJobId);

        if (! $job || $job->status !== AudioProcessingJob::STATUS_PENDING) {
            return;
        }

        $exports->process($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = AudioProcessingJob::find($this->processingJobId);

        if (! $job || $job->isCompleted()) {
            return;
        }

        $safe = $exception instanceof AudioProcessingException
            ? $exception->safeMessage()
            : 'The export worker stopped before the file could be finished.';

        $job->markFailed($safe);
    }
}
