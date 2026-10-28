@php
    $fx = $selectedFile?->effectSettings() ?? $studio['defaults'];
    $speedPercent = (($fx['speed'] - $studio['limits']['speed']['min']) / ($studio['limits']['speed']['max'] - $studio['limits']['speed']['min'])) * 100;
    $pitchPercent = (($fx['pitch_multiplier'] - $studio['limits']['pitch']['min']) / ($studio['limits']['pitch']['max'] - $studio['limits']['pitch']['min'])) * 100;
    $independentPitch = $fx['pitch_enabled'] ? $fx['pitch_multiplier'] : 1.0;
    $independentSemitones = $independentPitch > 0 ? 12 * log($independentPitch, 2) : 0;
    $canEdit = $selectedFile !== null;
    $canExport = $selectedFile !== null && $selectedFile->isReady() && ($studio['environment']['ok'] ?? false);
@endphp

<div class="section-title-row">
    <h2>Presets</h2>
    <span class="hint">One tap, then fine-tune</span>
</div>

<div class="preset-grid" id="presetGrid" role="group" aria-label="Audio effect presets">
    <button class="preset-card" type="button" data-preset="original" @disabled(! $canEdit)>
        <span class="preset-icon"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></span>
        <span class="preset-copy">
            <span class="preset-name">Original</span>
            <span class="preset-desc d-block">Reset speed, pitch, reverb and bass</span>
        </span>
    </button>

    <button class="preset-card {{ $fx['preset_name'] === 'pure_reverb' ? 'is-active' : '' }}" type="button" data-preset="pure_reverb" @disabled(! $canEdit)>
        <span class="preset-icon"><i class="fa-solid fa-water" aria-hidden="true"></i></span>
        <span class="preset-copy">
            <span class="preset-name">Pure Reverb</span>
            <span class="preset-desc d-block">Original speed and pitch · 40% hall reverb</span>
        </span>
    </button>

    <button class="preset-card {{ $fx['preset_name'] === 'slowed_reverb' ? 'is-active' : '' }}" type="button" data-preset="slowed_reverb" @disabled(! $canEdit)>
        <span class="preset-icon"><i class="fa-solid fa-cloud-moon" aria-hidden="true"></i></span>
        <span class="preset-copy">
            <span class="preset-name">Slowed + Reverb</span>
            <span class="preset-desc d-block">0.8x tape · deeper voice · 40% reverb</span>
        </span>
    </button>

    <button class="preset-card {{ $fx['preset_name'] === 'nightcore' ? 'is-active' : '' }}" type="button" data-preset="nightcore" @disabled(! $canEdit)>
        <span class="preset-icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
        <span class="preset-copy">
            <span class="preset-name">Nightcore</span>
            <span class="preset-desc d-block">1.25x tape · thinner voice · ≈ +4 st</span>
        </span>
    </button>
</div>

<div class="section-title-row">
    <h2>Effects</h2>
    <button class="btn btn-studio-quiet btn-sm" type="button" id="resetEffectsButton" @disabled(! $canEdit)>
        <i class="fa-solid fa-arrow-rotate-left me-1" aria-hidden="true"></i> Reset all
    </button>
</div>

<div class="effects-grid" id="effectsGrid">
    <section class="effect-card" aria-labelledby="speedLabel">
        <div class="effect-head">
            <div class="effect-label" id="speedLabel">
                <span class="effect-icon"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i></span>
                <span>Speed</span>
            </div>
            <div class="d-flex align-items-center gap-1">
                <span class="effect-value"><span id="speedValue">{{ number_format($fx['speed'], 2) }}</span><span class="unit">x</span></span>
                <button class="icon-button effect-reset" type="button" id="speedReset" aria-label="Reset speed to 1.00x" title="Reset speed" @disabled(! $canEdit)>
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="slider-row">
            <span class="slider-min">{{ number_format($studio['limits']['speed']['min'], 2) }}x</span>
            <input class="studio-range"
                   id="speedSlider"
                   type="range"
                   min="{{ $studio['limits']['speed']['min'] }}"
                   max="{{ $studio['limits']['speed']['max'] }}"
                   step="{{ $studio['limits']['speed']['step'] }}"
                   value="{{ $fx['speed'] }}"
                   style="--range-progress: {{ round($speedPercent, 2) }}%"
                   aria-labelledby="speedLabel"
                   @disabled(! $canEdit)>
            <span class="slider-max">{{ number_format($studio['limits']['speed']['max'], 2) }}x</span>
        </div>
        <div class="effect-sub">
            <span>Tape mode · pitch follows speed</span>
            <button class="toggle"
                    type="button"
                    id="pitchFollowToggle"
                    role="switch"
                    aria-checked="{{ $fx['pitch_follows_speed'] ? 'true' : 'false' }}"
                    aria-label="Pitch follows speed"
                    title="Slow down for a deeper voice, speed up for a thinner voice"
                    @disabled(! $canEdit)></button>
        </div>
        <div class="effect-sub">
            <span id="speedModeHint">{{ $fx['pitch_follows_speed'] ? 'Slower = deeper, faster = thinner' : 'Pitch stays fixed while speed changes' }}</span>
            <span id="speedDuration">≈ {{ $selectedFile?->formattedEffectiveDuration() ?? '0:00' }}</span>
        </div>
    </section>

    <section class="effect-card" aria-labelledby="reverbLabel">
        <div class="effect-head">
            <div class="effect-label" id="reverbLabel">
                <span class="effect-icon"><i class="fa-solid fa-water" aria-hidden="true"></i></span>
                <span>Reverb</span>
            </div>
            <div class="d-flex align-items-center gap-1">
                <span class="effect-value"><span id="reverbValue">{{ $fx['reverb'] }}</span><span class="unit">%</span></span>
                <button class="icon-button effect-reset" type="button" id="reverbReset" aria-label="Reset reverb to 0%" title="Reset reverb" @disabled(! $canEdit)>
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="slider-row">
            <span class="slider-min">0%</span>
            <input class="studio-range"
                   id="reverbSlider"
                   type="range"
                   min="{{ $studio['limits']['reverb']['min'] }}"
                   max="{{ $studio['limits']['reverb']['max'] }}"
                   step="{{ $studio['limits']['reverb']['step'] }}"
                   value="{{ $fx['reverb'] }}"
                   style="--range-progress: {{ $fx['reverb'] }}%"
                   aria-labelledby="reverbLabel"
                   @disabled(! $canEdit)>
            <span class="slider-max">100%</span>
        </div>
        <div class="effect-sub">
            <span>Convolution hall, dry/wet mix</span>
            <span>0–100%</span>
        </div>
    </section>

    <section class="effect-card {{ $fx['pitch_enabled'] ? '' : 'is-disabled' }}" aria-labelledby="pitchLabel">
        <div class="effect-head">
            <div class="effect-label" id="pitchLabel">
                <span class="effect-icon"><i class="fa-solid fa-arrows-up-down" aria-hidden="true"></i></span>
                <span>Pitch</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="effect-value"><span id="pitchValue">{{ $fx['pitch_enabled'] ? number_format($fx['pitch_multiplier'], 2) : '0' }}</span><span class="unit" id="pitchUnit">{{ $fx['pitch_enabled'] ? 'x' : '%' }}</span></span>
                <button class="toggle"
                        type="button"
                        id="pitchToggle"
                        role="switch"
                        aria-checked="{{ $fx['pitch_enabled'] ? 'true' : 'false' }}"
                        aria-label="Enable independent pitch shifting"
                        @disabled(! $canEdit)></button>
            </div>
        </div>
        <div class="slider-row">
            <span class="slider-min">{{ number_format($studio['limits']['pitch']['min'], 2) }}x</span>
            <input class="studio-range"
                   id="pitchSlider"
                   type="range"
                   min="{{ $studio['limits']['pitch']['min'] }}"
                   max="{{ $studio['limits']['pitch']['max'] }}"
                   step="{{ $studio['limits']['pitch']['step'] }}"
                   value="{{ $fx['pitch_multiplier'] }}"
                   style="--range-progress: {{ round($pitchPercent, 2) }}%"
                   aria-labelledby="pitchLabel"
                   @disabled(! $canEdit || ! $fx['pitch_enabled'])>
            <span class="slider-max">{{ number_format($studio['limits']['pitch']['max'], 2) }}x</span>
        </div>
        <div class="effect-sub">
            <span id="pitchSemitones">{{ $fx['pitch_enabled'] ? (abs($independentSemitones) > 0.05 ? ($independentSemitones > 0 ? '+' : '').number_format($independentSemitones, 1).' semitones extra' : 'No extra pitch') : 'Pitch off (0%)' }}</span>
            <button class="btn btn-link p-0 border-0 text-decoration-none" type="button" id="pitchReset" style="font-size: 0.6rem;" @disabled(! $canEdit)>Reset</button>
        </div>
    </section>

    <section class="effect-card" aria-labelledby="bassLabel">
        <div class="effect-head">
            <div class="effect-label" id="bassLabel">
                <span class="effect-icon"><i class="fa-solid fa-volume-high" aria-hidden="true"></i></span>
                <span>Bass boost</span>
            </div>
            <div class="d-flex align-items-center gap-1">
                <span class="effect-value"><span id="bassValue">{{ $fx['bass_boost'] }}</span><span class="unit">%</span></span>
                <button class="icon-button effect-reset" type="button" id="bassReset" aria-label="Reset bass boost to 0%" title="Reset bass boost" @disabled(! $canEdit)>
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="slider-row">
            <span class="slider-min">0%</span>
            <input class="studio-range"
                   id="bassSlider"
                   type="range"
                   min="{{ $studio['limits']['bass_boost']['min'] }}"
                   max="{{ $studio['limits']['bass_boost']['max'] }}"
                   step="{{ $studio['limits']['bass_boost']['step'] }}"
                   value="{{ $fx['bass_boost'] }}"
                   style="--range-progress: {{ $fx['bass_boost'] }}%"
                   aria-labelledby="bassLabel"
                   @disabled(! $canEdit)>
            <span class="slider-max">100%</span>
        </div>
        <div class="effect-sub">
            <span>Low-shelf filter · 150 Hz</span>
            <span>0–12 dB</span>
        </div>
    </section>
</div>

<section class="export-card" aria-label="Export processed audio">
    <div class="export-copy">
        <h2>Ready to export?</h2>
        <p id="exportSummary">All enabled effects are applied server-side with FFmpeg. The original file is never overwritten.</p>
    </div>
    <div class="export-actions">
        <button class="btn btn-studio-ghost" type="button" data-export-format="wav" @disabled(! $canExport)>
            <i class="fa-solid fa-file-audio me-2" aria-hidden="true"></i> WAV
        </button>
        <button class="btn btn-studio-primary" type="button" data-export-format="mp3" @disabled(! $canExport)>
            <i class="fa-solid fa-music me-2" aria-hidden="true"></i> MP3
        </button>
    </div>
</section>
