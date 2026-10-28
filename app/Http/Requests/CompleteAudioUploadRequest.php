<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CompleteAudioUploadRequest extends FormRequest
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
        $maxBytes = max(1, (int) config('audio.max_upload_mb', 100)) * 1024 * 1024;

        return [
            'key' => ['required', 'string', 'max:500'],
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
            'type' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $key = (string) $this->input('key');
                $userId = (int) $this->user()?->id;
                $prefix = 'audio/'.$userId.'/';

                if (! str_starts_with($key, $prefix) || str_contains($key, '..')) {
                    $validator->errors()->add('key', 'The uploaded file does not belong to this account.');

                    return;
                }

                $extension = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));
                $supported = config('audio.supported_extensions', []);

                if (! in_array($extension, $supported, true)) {
                    $validator->errors()->add('key', 'The uploaded file has an unsupported extension.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'size.max' => 'Each audio file must be smaller than '.(int) config('audio.max_upload_mb', 100).' MB.',
        ];
    }
}
