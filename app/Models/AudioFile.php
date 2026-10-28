<?php

namespace App\Models;

use Database\Factories\AudioFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudioFile extends Model
{
    /** @use HasFactory<AudioFileFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    protected $fillable = [
        'user_id',
        'original_name',
        'display_name',
        'storage_path',
        'preview_path',
        'mime_type',
        'extension',
        'file_size',
        'duration_seconds',
        'sample_rate',
        'channels',
        'bit_rate',
        'codec',
        'container',
        'status',
        'error_message',
        'effects',
        'waveform',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'duration_seconds' => 'float',
            'sample_rate' => 'integer',
            'channels' => 'integer',
            'bit_rate' => 'integer',
            'effects' => 'array',
            'waveform' => 'array',
            'last_accessed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processingJobs(): HasMany
    {
        return $this->hasMany(AudioProcessingJob::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [self::STATUS_UPLOADED, self::STATUS_PROCESSING], true);
    }

    /**
     * Effect settings with defaults applied and canonical numeric types.
     *
     * @return array{speed: float, pitch_follows_speed: bool, pitch_enabled: bool, pitch_multiplier: float, reverb: float, bass_boost: float, preset_name: ?string}
     */
    public function effectSettings(): array
    {
        $defaults = config('audio.default_effects');
        $settings = array_replace($defaults, is_array($this->effects) ? $this->effects : []);

        return [
            'speed' => round((float) $settings['speed'], 2),
            'pitch_follows_speed' => (bool) ($settings['pitch_follows_speed'] ?? true),
            'pitch_enabled' => (bool) $settings['pitch_enabled'],
            'pitch_multiplier' => round((float) $settings['pitch_multiplier'], 2),
            'reverb' => (int) round((float) $settings['reverb']),
            'bass_boost' => (int) round((float) $settings['bass_boost']),
            'preset_name' => $settings['preset_name'] ?: null,
        ];
    }

    public function effectiveDuration(): float
    {
        $duration = (float) ($this->duration_seconds ?? 0);
        $speed = max(0.01, (float) $this->effectSettings()['speed']);

        return round($duration / $speed, 3);
    }

    /**
     * The pitch multiplier heard after tape-mode speed coupling and the
     * optional independent pitch control are combined.
     */
    public function effectivePitchMultiplier(): float
    {
        $effects = $this->effectSettings();
        $pitch = $effects['pitch_follows_speed'] ? $effects['speed'] : 1.0;

        if ($effects['pitch_enabled']) {
            $pitch *= $effects['pitch_multiplier'];
        }

        return round(max(0.01, $pitch), 4);
    }

    public function semitoneShift(): float
    {
        return round(12 * log($this->effectivePitchMultiplier(), 2), 1);
    }

    public function humanFileSize(): string
    {
        $bytes = (int) $this->file_size;

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        return number_format(max(1, $bytes) / 1024, 0).' KB';
    }

    public function formattedDuration(): string
    {
        $seconds = (int) round((float) ($this->duration_seconds ?? 0));

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public function formattedEffectiveDuration(): string
    {
        $seconds = (int) round($this->effectiveDuration());

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public function hasPreview(): bool
    {
        return $this->preview_path
            && Storage::disk('local')->exists($this->preview_path);
    }

    /**
     * The waveform points are real peak values extracted from the source.
     *
     * @return list<float>
     */
    public function waveformPeaks(): array
    {
        return is_array($this->waveform) ? array_values($this->waveform) : [];
    }

    public function streamUrl(): ?string
    {
        return $this->hasPreview() ? route('audio.stream', $this) : null;
    }

    /**
     * Serialised shape consumed by the JavaScript studio.
     */
    public function toStudioArray(): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'display_name' => $this->display_name,
            'extension' => strtoupper($this->extension),
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'human_size' => $this->humanFileSize(),
            'duration_seconds' => (float) ($this->duration_seconds ?? 0),
            'formatted_duration' => $this->formattedDuration(),
            'effective_duration' => $this->effectiveDuration(),
            'formatted_effective_duration' => $this->formattedEffectiveDuration(),
            'sample_rate' => $this->sample_rate,
            'channels' => $this->channels,
            'bit_rate' => $this->bit_rate,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'effects' => $this->effectSettings(),
            'effective_pitch' => $this->effectivePitchMultiplier(),
            'semitone_shift' => $this->semitoneShift(),
            'waveform' => $this->waveformPeaks(),
            'stream_url' => $this->streamUrl(),
            'metadata_url' => route('audio.metadata', $this),
            'effects_url' => route('audio.effects.update', $this),
            'export_url' => route('audio.export.store', $this),
            'rename_url' => route('audio.rename', $this),
            'delete_url' => route('audio.destroy', $this),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function downloadBasename(): string
    {
        $name = Str::of($this->display_name)->beforeLast('.')->trim()->toString();
        $name = preg_replace('/[^\p{L}\p{M}\p{N}\-_ .()]+/u', '_', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = trim($name, ' ._-');

        return $name !== '' ? Str::limit($name, 120, '') : 'audio';
    }
}
