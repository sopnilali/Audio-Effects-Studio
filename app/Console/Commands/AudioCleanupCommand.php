<?php

namespace App\Console\Commands;

use App\Services\Audio\AudioExportService;
use Illuminate\Console\Command;

class AudioCleanupCommand extends Command
{
    protected $signature = 'audio:cleanup';

    protected $description = 'Delete expired exports and temporary audio files';

    public function handle(AudioExportService $exports): int
    {
        $result = $exports->cleanupExpired();

        $this->components->info("Removed {$result['jobs']} expired processing job(s) and {$result['files']} output file(s).");

        return self::SUCCESS;
    }
}
