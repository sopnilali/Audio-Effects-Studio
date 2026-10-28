<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAudioEffectsRequest extends FormRequest
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
            'speed' => ['required', 'numeric', 'min:0.25', 'max:2.0'],
            'pitch_follows_speed' => ['sometimes', 'boolean'],
            'pitch_enabled' => ['required', 'boolean'],
            'pitch_multiplier' => ['required', 'numeric', 'min:0.5', 'max:2.0'],
            'reverb' => ['required', 'numeric', 'min:0', 'max:100'],
            'bass_boost' => ['required', 'numeric', 'min:0', 'max:100'],
            'preset_name' => ['nullable', 'string', Rule::in(array_keys(config('audio.presets', [])))],
        ];
    }

    public function messages(): array
    {
        return [
            'speed.min' => 'Speed must be at least 0.25x.',
            'speed.max' => 'Speed cannot be faster than 2.0x.',
            'pitch_multiplier.min' => 'Pitch must be at least 0.5x.',
            'pitch_multiplier.max' => 'Pitch cannot be higher than 2.0x.',
            'reverb.min' => 'Reverb cannot be below 0%.',
            'reverb.max' => 'Reverb cannot exceed 100%.',
            'bass_boost.min' => 'Bass boost cannot be below 0%.',
            'bass_boost.max' => 'Bass boost cannot exceed 100%.',
            'preset_name.in' => 'That preset does not exist.',
        ];
    }
}
