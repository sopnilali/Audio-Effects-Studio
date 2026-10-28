<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Wraps the private audio disk so the application can run with local storage
 * during development and S3/R2 in serverless environments. FFmpeg always needs
 * a real local path, so remote files are copied to the temporary directory.
 */
class AudioStorageService
{
    public function diskName(): string
    {
        return (string) config('audio.disk', 'local');
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    public function isLocal(): bool
    {
        return $this->diskName() === 'local';
    }

    public function temporaryDirectory(): string
    {
        $directory = (string) config('audio.temp_directory', storage_path('app/private/temporary'));
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    public function temporaryPath(string $extension = ''): string
    {
        $suffix = $extension !== '' ? '.'.ltrim($extension, '.') : '';

        return $this->temporaryDirectory().'/'.Str::uuid().$suffix;
    }

    public function storeUploadedFile(UploadedFile $file, string $directory, string $filename): string
    {
        $path = $file->storeAs($directory, $filename, $this->diskName());

        if (! is_string($path) || $path === '') {
            throw new AudioProcessingException('Unable to store the uploaded file.', 'The file could not be saved. Please try again.');
        }

        return $path;
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function size(string $path): int
    {
        return (int) $this->disk()->size($path);
    }

    public function delete(string|array $paths): void
    {
        foreach ((array) $paths as $path) {
            if (is_string($path) && $path !== '' && $this->disk()->exists($path)) {
                $this->disk()->delete($path);
            }
        }
    }

    /**
     * Run a callback with a readable local path. Remote objects are downloaded
     * once and removed afterwards; local paths are never deleted.
     */
    public function withLocalCopy(string $path, callable $callback): mixed
    {
        if ($this->isLocal()) {
            $absolute = $this->disk()->path($path);

            if (! is_file($absolute) || ! is_readable($absolute)) {
                throw AudioProcessingException::processingFailed('The source audio file is missing.');
            }

            return $callback($absolute);
        }

        $temporary = $this->downloadToTemporary($path);

        try {
            return $callback($temporary);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function downloadToTemporary(string $path): string
    {
        $stream = $this->disk()->readStream($path);

        if (! is_resource($stream)) {
            throw AudioProcessingException::processingFailed('The source audio file is missing.');
        }

        $temporary = $this->temporaryPath(pathinfo($path, PATHINFO_EXTENSION));
        $output = fopen($temporary, 'wb');

        if ($output === false) {
            fclose($stream);
            throw AudioProcessingException::processingFailed('Unable to create a temporary audio file.');
        }

        try {
            stream_copy_to_stream($stream, $output);
        } finally {
            fclose($stream);
            fclose($output);
        }

        if (! is_file($temporary) || filesize($temporary) < 1) {
            @unlink($temporary);
            throw AudioProcessingException::processingFailed('The source audio file could not be read.');
        }

        return $temporary;
    }

    public function putLocalFile(string $localPath, string $destination): void
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw AudioProcessingException::processingFailed('The processed audio file is missing.');
        }

        if ($this->isLocal()) {
            $absolute = $this->disk()->path($destination);
            File::ensureDirectoryExists(dirname($absolute));

            if (! @copy($localPath, $absolute)) {
                throw AudioProcessingException::processingFailed('Unable to save the processed audio file.');
            }

            return;
        }

        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw AudioProcessingException::processingFailed('Unable to read the processed audio file.');
        }

        try {
            if (! $this->disk()->writeStream($destination, $stream)) {
                throw AudioProcessingException::processingFailed('Unable to save the processed audio file.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function putContents(string $path, string $contents): bool
    {
        return $this->disk()->put($path, $contents);
    }

    public function temporaryUrl(string $path, int $minutes = 10): ?string
    {
        if ($this->isLocal()) {
            return null;
        }

        try {
            return $this->disk()->temporaryUrl($path, now()->addMinutes(max(1, $minutes)));
        } catch (Throwable) {
            return null;
        }
    }

    public function streamResponse(string $path, string $mimeType): BinaryFileResponse|StreamedResponse
    {
        $headers = [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ];

        if ($this->isLocal()) {
            $headers['Accept-Ranges'] = 'bytes';

            return response()->file($this->disk()->path($path), $headers);
        }

        return response()->stream(function () use ($path): void {
            $stream = $this->disk()->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, $headers);
    }

    public function downloadResponse(string $path, string $name, string $mimeType): BinaryFileResponse|StreamedResponse
    {
        $headers = [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ];

        if ($this->isLocal()) {
            return response()->download($this->disk()->path($path), $name, $headers);
        }

        return response()->streamDownload(function () use ($path): void {
            $stream = $this->disk()->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $name, $headers);
    }
}
