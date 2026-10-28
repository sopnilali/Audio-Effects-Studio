<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAudioEffectsRequest;
use App\Models\AudioFile;
use App\Services\Audio\AudioEffectsService;
use Illuminate\Http\JsonResponse;

class AudioEffectController extends Controller
{
    public function __construct(private readonly AudioEffectsService $effects) {}

    public function update(UpdateAudioEffectsRequest $request, AudioFile $audioFile): JsonResponse
    {
        $this->authorize('update', $audioFile);

        $settings = $this->effects->normalize($request->validated());
        $activePreset = $this->effects->activePreset($settings);

        $audioFile->forceFill([
            'effects' => $settings,
            'last_accessed_at' => now(),
        ])->save();

        $fresh = $audioFile->fresh();

        return response()->json([
            'data' => [
                'audio' => $fresh->toStudioArray(),
                'effects' => $settings,
                'active_preset' => $activePreset,
                'description' => $this->effects->describe($settings),
                'semitone_shift' => $fresh->semitoneShift(),
                'effective_duration' => $fresh->effectiveDuration(),
                'formatted_effective_duration' => $fresh->formattedEffectiveDuration(),
            ],
            'message' => 'Effect settings saved.',
        ]);
    }
}
