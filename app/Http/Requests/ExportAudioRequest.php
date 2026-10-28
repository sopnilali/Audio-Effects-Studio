<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportAudioRequest extends FormRequest
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
            'format' => ['required', 'string', Rule::in(config('audio.export_formats', ['wav', 'mp3']))],
            'settings' => ['sometimes', 'array'],
            'settings.speed' => ['required_with:settings', 'numeric', 'min:0.25', 'max:2.0'],
            'settings.pitch_follows_speed' => ['sometimes', 'boolean'],
            'settings.pitch_enabled' => ['required_with:settings', 'boolean'],
            'settings.pitch_multiplier' => ['required_with:settings', 'numeric', 'min:0.5', 'max:2.0'],
            'settings.reverb' => ['required_with:settings', 'numeric', 'min:0', 'max:100'],
            'settings.bass_boost' => ['required_with:settings', 'numeric', 'min:0', 'max:100'],
            'settings.preset_name' => [
                'nullable',
                'string',
                Rule::in(array_keys(config('audio.presets', []))),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'format.in' => 'Choose WAV or MP3 for the export.',
            'settings.speed.min' => 'Speed must be at least 0.25x.',
            'settings.speed.max' => 'Speed cannot be faster than 2.0x.',
            'settings.pitch_multiplier.min' => 'Pitch must be at least 0.5x.',
            'settings.pitch_multiplier.max' => 'Pitch cannot be higher than 2.0x.',
            'settings.reverb.min' => 'Reverb cannot be below 0%.',
            'settings.reverb.max' => 'Reverb cannot exceed 100%.',
            'settings.bass_boost.min' => 'Bass boost cannot be below 0%.',
            'settings.bass_boost.max' => 'Bass boost cannot exceed 100%.',
        ];
    }
}
