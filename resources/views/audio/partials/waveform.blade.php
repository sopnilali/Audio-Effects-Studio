<section class="card-studio waveform-card" aria-label="Audio waveform">
    <div class="waveform-head">
        <div class="waveform-label">
            <i class="fa-solid fa-wave-square" aria-hidden="true"></i>
            <span>Waveform</span>
        </div>
        <div class="waveform-times">
            <span class="current" id="waveformCurrentTime">0:00</span>
            <span>/</span>
            <span id="waveformTotalTime">{{ $selectedFile?->formattedDuration() ?? '0:00' }}</span>
        </div>
    </div>

    <div class="waveform-stage">
        <div id="waveform" role="slider"
             aria-label="Audio waveform. Use the player controls or click to seek."
             aria-valuemin="0"
             aria-valuemax="{{ (int) ($selectedFile?->duration_seconds ?? 0) }}"
             aria-valuenow="0"
             tabindex="0"></div>

        <div class="waveform-empty {{ $selectedFile ? 'hidden' : '' }}" id="waveformEmpty">
            <i class="fa-solid fa-wave-square" aria-hidden="true"></i>
            <span>Select an audio file to see its waveform</span>
        </div>

        <div class="waveform-loading hidden" id="waveformLoading" aria-live="polite">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span>Preparing waveform…</span>
        </div>
    </div>

    <div class="waveform-footer">
        <span class="hint"><i class="fa-regular fa-hand-pointer" aria-hidden="true"></i> Click or drag to seek</span>
        <span id="waveformStatus">{{ $selectedFile?->isReady() ? 'Original audio waveform' : ($selectedFile ? 'Waiting for audio preparation' : 'No audio selected') }}</span>
    </div>
</section>
