<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class UploadAudioRequest extends FormRequest
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
        $maxKilobytes = max(1, (int) config('audio.max_upload_mb', 100)) * 1024;
        $maxFiles = max(1, (int) config('audio.max_files_per_upload', 10));
        $extensions = implode(',', config('audio.supported_extensions', []));

        return [
            'files' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => ['required', 'file', 'max:'.$maxKilobytes, 'mimes:'.$extensions],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Choose at least one audio file to upload.',
            'files.array' => 'The upload must contain a list of audio files.',
            'files.max' => 'You can upload at most :max files at once.',
            'files.*.file' => 'One of the uploads was not a valid file.',
            'files.*.max' => 'Each audio file must be smaller than '.(int) config('audio.max_upload_mb', 100).' MB.',
            'files.*.mimes' => 'Only MP3, WAV, OGG, M4A, AAC and FLAC files are supported.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $supported = config('audio.supported_extensions', []);
                $files = $this->file('files', []);

                if (! is_array($files)) {
                    return;
                }

                foreach ($files as $index => $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }

                    $extension = strtolower($file->getClientOriginalExtension());

                    if ($extension === '') {
                        $extension = strtolower((string) $file->guessExtension());
                    }

                    $normalized = match ($extension) {
                        'mpga', 'mpeg' => 'mp3',
                        'wave' => 'wav',
                        'mp4' => 'm4a',
                        default => $extension,
                    };

                    if (! in_array($normalized, $supported, true)) {
                        $validator->errors()->add(
                            "files.{$index}",
                            'The file "'.$file->getClientOriginalName().'" has an unsupported extension.',
                        );
                    }

                    if ((int) $file->getSize() > (int) config('audio.max_upload_mb', 100) * 1024 * 1024) {
                        $validator->errors()->add(
                            "files.{$index}",
                            'The file "'.$file->getClientOriginalName().'" exceeds the maximum upload size.',
                        );
                    }
                }
            },
        ];
    }
}
