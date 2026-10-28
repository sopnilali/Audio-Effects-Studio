<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Aws\S3\S3Client;

/**
 * Creates short-lived presigned PUT URLs so the browser can upload large audio
 * files directly to S3/R2 instead of through Vercel's 4.5 MB function body.
 */
class S3UploadService
{
    public function isConfigured(): bool
    {
        if (! (bool) config('audio.direct_upload', false)) {
            return false;
        }

        if (config('audio.disk') !== 's3') {
            return false;
        }

        $disk = config('filesystems.disks.s3', []);

        return filled($disk['bucket'] ?? null)
            && filled($disk['key'] ?? null)
            && filled($disk['secret'] ?? null);
    }

    /**
     * @return array{url: string, headers: array<string, string>, expires_in: int}
     */
    public function presign(string $key, string $contentType): array
    {
        if (! $this->isConfigured()) {
            throw new AudioProcessingException(
                'Direct uploads are not configured.',
                'Large uploads are temporarily unavailable. Please try again later.',
            );
        }

        $client = $this->client();
        $command = $client->getCommand('PutObject', [
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        $request = $client->createPresignedRequest($command, '+15 minutes');

        return [
            'url' => (string) $request->getUri(),
            'headers' => ['Content-Type' => $contentType],
            'expires_in' => 900,
        ];
    }

    private function client(): S3Client
    {
        $disk = config('filesystems.disks.s3', []);

        $options = [
            'version' => 'latest',
            'region' => ($disk['region'] ?? null) ?: 'us-east-1',
            'bucket' => $disk['bucket'] ?? null,
            'credentials' => [
                'key' => $disk['key'] ?? null,
                'secret' => $disk['secret'] ?? null,
            ],
            'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? false),
        ];

        if (filled($disk['endpoint'] ?? null)) {
            $options['endpoint'] = $disk['endpoint'];
        }

        return new S3Client($options);
    }
}
