<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;

/**
 * The PHP development server inherits php.ini, which commonly limits uploads
 * to 2 MB. Raise the limits for `php artisan serve` so local uploads behave
 * like a correctly configured production web server.
 */
class ServeCommand extends BaseServeCommand
{
    /**
     * @return array<int, string>
     */
    protected function serverCommand()
    {
        $command = parent::serverCommand();
        $maxUploadMb = max(1, (int) config('audio.max_upload_mb', 10));
        $postMaxMb = $maxUploadMb + 10;

        array_splice($command, 1, 0, [
            '-d', 'upload_max_filesize='.$maxUploadMb.'M',
            '-d', 'post_max_size='.$postMaxMb.'M',
            '-d', 'memory_limit=512M',
            '-d', 'max_execution_time=900',
            '-d', 'max_input_time=900',
        ]);

        return $command;
    }
}
