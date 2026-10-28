<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PresignAudioUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxBytes = max(1, (int) config('audio.max_upload_mb', 10)) * 1024 * 1024;

        return [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
            'type' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $extension = strtolower((string) pathinfo((string) $this->input('name'), PATHINFO_EXTENSION));
                $extension = match ($extension) {
                    'mpga', 'mpeg' => 'mp3',
                    'wave' => 'wav',
                    'mp4' => 'm4a',
                    default => $extension,
                };
                $supported = config('audio.supported_extensions', []);

                if (! in_array($extension, $supported, true)) {
                    $validator->errors()->add('name', 'Only MP3, WAV, OGG, M4A, AAC and FLAC files are supported.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'size.max' => 'Each audio file must be smaller than '.(int) config('audio.max_upload_mb', 10).' MB.',
        ];
    }
}
