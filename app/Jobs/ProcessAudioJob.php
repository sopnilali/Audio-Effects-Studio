<?php

namespace App\Jobs;

use App\Exceptions\AudioProcessingException;
use App\Models\AudioFile;
use App\Models\AudioProcessingJob;
use App\Services\Audio\AudioFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly int $processingJobId) {}

    public function handle(AudioFileService $audioFiles): void
    {
        $job = AudioProcessingJob::find($this->processingJobId);

        if (! $job || $job->status !== AudioProcessingJob::STATUS_PENDING) {
            return;
        }

        $audioFile = $job->audioFile;

        if (! $audioFile) {
            $job->markFailed('The audio file no longer exists.');

            return;
        }

        $job->markProcessing();

        try {
            $audioFiles->prepare($audioFile);
            $job->markCompleted();
        } catch (Throwable $exception) {
            $safe = $exception instanceof AudioProcessingException
                ? $exception->safeMessage()
                : 'The audio preview could not be prepared.';

            $job->markFailed($safe);

            $audioFile->forceFill([
                'status' => AudioFile::STATUS_FAILED,
                'error_message' => $safe,
            ])->save();

            Log::error('Queued audio preparation failed', [
                'processing_job_id' => $job->id,
                'audio_file_id' => $audioFile->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $job = AudioProcessingJob::find($this->processingJobId);

        if ($job && ! $job->isCompleted()) {
            $job->markFailed('The audio preparation worker failed.');
        }

        if ($job?->audioFile && $job->audioFile->status !== AudioFile::STATUS_READY) {
            $job->audioFile->forceFill([
                'status' => AudioFile::STATUS_FAILED,
                'error_message' => 'The audio preview could not be prepared.',
            ])->save();
        }
    }
}
