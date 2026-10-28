<?php

namespace App\Exceptions;

use RuntimeException;

class AudioProcessingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $userMessage = null,
        ?\Throwable $previous = null,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function ffmpegUnavailable(string $detail = ''): self
    {
        return new self(
            'FFmpeg is not available: '.$detail,
            'Audio processing is unavailable because FFmpeg is not installed or not executable on this server.',
            null,
            503,
        );
    }

    public static function unsupportedFormat(string $format): self
    {
        return new self(
            "Unsupported audio format: {$format}",
            'That audio format is not supported. Please upload MP3, WAV, OGG, M4A, AAC or FLAC.',
        );
    }

    public static function tooLong(float $seconds, int $max): self
    {
        return new self(
            "Audio duration {$seconds}s exceeds the configured limit of {$max}s.",
            'This audio file is longer than the allowed limit of '.intdiv($max, 60).' minutes.',
        );
    }

    public static function metadataFailed(string $detail = ''): self
    {
        return new self(
            'Unable to read audio metadata. '.$detail,
            'We could not read this file as audio. It may be corrupt or use an unsupported codec.',
        );
    }

    public static function processingFailed(string $detail = ''): self
    {
        return new self(
            'Audio processing failed. '.$detail,
            'The audio could not be processed. Please try again or use a different file.',
        );
    }

    public static function exportFailed(string $detail = ''): self
    {
        return new self(
            'Audio export failed. '.$detail,
            'The export could not be completed. Please try again.',
        );
    }

    public function safeMessage(): string
    {
        return $this->userMessage ?: 'The audio operation could not be completed.';
    }
}
