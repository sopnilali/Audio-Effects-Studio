<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameAudioRequest extends FormRequest
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
        return [
            'display_name' => ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'Enter a name for this audio file.',
            'display_name.max' => 'The name must be 200 characters or fewer.',
            'display_name.not_regex' => 'The name contains characters that are not allowed.',
        ];
    }
}
