<?php

namespace App\Models;

use Database\Factories\AudioProcessingJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AudioProcessingJob extends Model
{
    /** @use HasFactory<AudioProcessingJobFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_EXPORT = 'export';

    public const TYPE_PREVIEW = 'preview';

    protected $fillable = [
        'audio_file_id',
        'user_id',
        'job_type',
        'input_path',
        'output_path',
        'format',
        'settings',
        'status',
        'progress',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function audioFile(): BelongsTo
    {
        return $this->belongsTo(AudioFile::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancellable(): bool
    {
        return $this->isPending();
    }

    public function outputExists(): bool
    {
        return $this->output_path
            && Storage::disk('local')->exists($this->output_path);
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'progress' => max(1, $this->progress),
            'started_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markCompleted(?string $outputPath = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'progress' => 100,
            'output_path' => $outputPath ?? $this->output_path,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => mb_substr($message, 0, 1000),
        ])->save();
    }

    public function downloadName(): string
    {
        $base = $this->audioFile?->downloadBasename() ?? 'audio';
        $format = $this->format ?: 'wav';

        return "{$base} - Audio Effects Studio.{$format}";
    }

    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'progress' => (int) $this->progress,
            'format' => $this->format,
            'error' => $this->error_message,
            'cancellable' => $this->isCancellable(),
            'download_url' => $this->isCompleted() && $this->outputExists()
                ? route('audio.processing.download', $this)
                : null,
            'download_name' => $this->downloadName(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
