<section class="editor-panel" id="editorPanel" aria-label="Audio editor">
    <div class="editor-empty {{ $selectedFile ? 'hidden' : '' }}" id="editorEmpty">
        <span class="empty-illustration"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
        <h2>No audio selected</h2>
        <p>Upload an MP3, WAV, OGG, M4A, AAC or FLAC file to open the waveform and start shaping your sound.</p>
        <button class="btn btn-studio-primary" type="button" data-add-files>
            <i class="fa-solid fa-plus me-2" aria-hidden="true"></i> Add audio files
        </button>
    </div>

    <div class="{{ $selectedFile ? '' : 'hidden' }}" id="editorContent">
        @if (! ($studio['environment']['ok'] ?? false))
            <div class="environment-banner" role="alert">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div>
                    <strong>Audio processing is unavailable.</strong>
                    Install FFmpeg and FFprobe on the server, set <code>FFMPEG_PATH</code> and <code>FFPROBE_PATH</code>, then run <code>php artisan audio:check --fresh</code>.
                    Uploads and exports are disabled until the audio engine is ready.
                </div>
            </div>
        @endif

        <div class="processing-note hidden" id="processingNote" aria-live="polite">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span>Preparing this audio for playback and waveform display…</span>
        </div>

        <div class="editor-header">
            <div class="min-w-0">
                <div class="editor-eyebrow">
                    <span>Now editing</span>
                    <span class="badge-studio {{ $selectedFile?->status === 'ready' ? 'badge-ready' : ($selectedFile?->status === 'failed' ? 'badge-failed' : 'badge-processing') }}" id="editorStatusBadge">
                        {{ $selectedFile ? ucfirst($selectedFile->status) : 'No file' }}
                    </span>
                </div>
                <h1 class="editor-title" id="editorTitle" title="{{ $selectedFile?->display_name }}">
                    {{ $selectedFile?->display_name ?? 'Select an audio file' }}
                </h1>
                <div class="editor-meta" id="editorMeta">
                    <span class="chip"><i class="fa-solid fa-file-audio" aria-hidden="true"></i><span id="metaFormat">{{ $selectedFile?->extension ? strtoupper($selectedFile->extension) : '—' }}</span></span>
                    <span class="chip"><i class="fa-regular fa-clock" aria-hidden="true"></i><span id="metaDuration">{{ $selectedFile?->formattedDuration() ?? '—' }}</span></span>
                    <span class="chip"><i class="fa-solid fa-database" aria-hidden="true"></i><span id="metaSize">{{ $selectedFile?->humanFileSize() ?? '—' }}</span></span>
                    <span class="chip"><i class="fa-solid fa-wave-square" aria-hidden="true"></i><span id="metaSampleRate">{{ $selectedFile?->sample_rate ? number_format($selectedFile->sample_rate / 1000, 1).' kHz' : '—' }}</span></span>
                    <span class="chip"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><span id="metaChannels">{{ $selectedFile?->channels === 1 ? 'Mono' : ($selectedFile?->channels === 2 ? 'Stereo' : ($selectedFile?->channels ? $selectedFile->channels.' ch' : '—')) }}</span></span>
                </div>
            </div>

            <div class="editor-header-actions">
                <button class="btn btn-studio-ghost" type="button" id="renameSelectedButton" @disabled(! $selectedFile)>
                    <i class="fa-solid fa-pen me-2" aria-hidden="true"></i> Rename
                </button>
                <button class="btn btn-studio-primary" type="button" data-export-format="" @disabled(! $selectedFile || ! $selectedFile->isReady())>
                    <i class="fa-solid fa-download me-2" aria-hidden="true"></i> Export
                </button>
            </div>
        </div>

        @include('audio.partials.waveform')
        @include('audio.partials.effect-controls')
    </div>
</section>
