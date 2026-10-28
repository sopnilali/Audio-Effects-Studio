<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Services\Audio\AudioExportService;
use App\Services\Audio\AudioFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class CleanupTemporaryAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(AudioExportService $exports, AudioFileService $audioFiles): void
    {
        $exports->cleanupExpired();

        $cutoff = now()->subHours(max(1, (int) config('audio.trash_retention_hours', 24)));

        AudioFile::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($files): void {
                foreach ($files as $file) {
                    foreach (array_filter([$file->storage_path, $file->preview_path]) as $path) {
                        if (Storage::disk('local')->exists($path)) {
                            Storage::disk('local')->delete($path);
                        }
                    }

                    $file->forceDelete();
                }
            });
    }
}
