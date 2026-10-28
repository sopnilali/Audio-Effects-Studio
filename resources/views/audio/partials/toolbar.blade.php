<header class="topbar" role="banner">
    <a class="brand" href="{{ route('audio.index') }}" aria-label="Audio Effects Studio home">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round">
                <path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/>
            </svg>
        </span>
        <span class="brand-copy">
            <span class="brand-title">Audio Effects Studio</span>
            <span class="brand-subtitle">Shape the sound</span>
        </span>
    </a>

    <div class="mobile-view-toggle" role="group" aria-label="Switch between library and editor">
        <button type="button" class="is-active" data-mobile-view="files" aria-pressed="true">
            <i class="fa-solid fa-list-ul me-1" aria-hidden="true"></i>Files
        </button>
        <button type="button" data-mobile-view="editor" aria-pressed="false">
            <i class="fa-solid fa-sliders me-1" aria-hidden="true"></i>Editor
        </button>
    </div>

    <div class="topbar-actions">
        <button class="btn btn-studio-primary" type="button" id="addFilesButton">
            <i class="fa-solid fa-plus me-2" aria-hidden="true"></i><span class="btn-label">Add files</span>
        </button>

        <button class="btn btn-studio-ghost" type="button" id="clearWorkspaceButton" @disabled($audioFiles->isEmpty())>
            <i class="fa-regular fa-trash-can me-2" aria-hidden="true"></i><span class="btn-label">Clear</span>
        </button>

        <div class="dropdown">
            <button class="btn btn-studio-ghost dropdown-toggle"
                    type="button"
                    id="downloadMenuButton"
                    data-bs-toggle="dropdown"
                    data-bs-strategy="fixed"
                    aria-expanded="false"
                    @disabled(! $selectedFile || ! $selectedFile->isReady())>
                <i class="fa-solid fa-download me-2" aria-hidden="true"></i><span class="btn-label">Download</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="downloadMenuButton">
                <li><h6 class="dropdown-header text-uppercase small">Export processed audio</h6></li>
                <li>
                    <button class="dropdown-item" type="button" data-export-format="wav">
                        <i class="fa-solid fa-file-audio" aria-hidden="true"></i> WAV <span class="text-studio-muted">· lossless</span>
                    </button>
                </li>
                <li>
                    <button class="dropdown-item" type="button" data-export-format="mp3">
                        <i class="fa-solid fa-music" aria-hidden="true"></i> MP3 <span class="text-studio-muted">· 192 kbps</span>
                    </button>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button class="dropdown-item" type="button" data-export-format="">
                        <i class="fa-solid fa-sliders" aria-hidden="true"></i> More export options…
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <div class="topbar-spacer"></div>

    <div class="topbar-status {{ ($studio['environment']['ok'] ?? false) ? 'is-ok' : 'is-warning' }}"
         id="engineStatus"
         title="{{ ($studio['environment']['ok'] ?? false) ? 'FFmpeg and FFprobe are ready.' : implode(' ', $studio['environment']['errors'] ?? ['Audio engine unavailable.']) }}">
        <span class="status-dot {{ ($studio['environment']['ok'] ?? false) ? 'ready' : 'failed' }}"></span>
        <span id="engineStatusText">{{ ($studio['environment']['ok'] ?? false) ? 'Audio engine ready' : 'Audio engine offline' }}</span>
    </div>

    <button class="icon-button d-none d-lg-inline-flex"
            type="button"
            data-bs-toggle="modal"
            data-bs-target="#shortcutsModal"
            aria-label="Keyboard shortcuts"
            title="Keyboard shortcuts">
        <i class="fa-regular fa-keyboard" aria-hidden="true"></i>
    </button>

    <div class="dropdown topbar-user">
        <button class="user-menu dropdown-toggle"
                type="button"
                data-bs-toggle="dropdown"
                data-bs-strategy="fixed"
                aria-expanded="false"
                aria-label="Account menu">
            <span class="user-avatar" aria-hidden="true">{{ $studio['user']['initials'] }}</span>
            <span class="user-name">{{ $studio['user']['name'] }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <span class="dropdown-item-text small text-studio-muted">
                    Signed in as<br><span class="text-white">{{ $studio['user']['email'] }}</span>
                </span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="dropdown-item" type="submit">
                        <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sign out
                    </button>
                </form>
            </li>
        </ul>
    </div>

    <input type="file"
           id="audioFileInput"
           class="d-none"
           name="files[]"
           accept=".mp3,.wav,.ogg,.m4a,.aac,.flac,audio/mpeg,audio/wav,audio/ogg,audio/mp4,audio/aac,audio/flac"
           multiple>
</header>

<div class="upload-progress-card" id="uploadProgressCard" aria-live="polite">
    <div class="upload-progress-head">
        <strong>Uploading audio</strong>
        <span id="uploadProgressPercent">0%</span>
    </div>
    <div class="progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="uploadProgressBar">
        <div class="progress-bar" style="width: 0%"></div>
    </div>
    <div class="upload-progress-name" id="uploadProgressName"></div>
</div>

<div class="drop-overlay" id="dropOverlay" aria-hidden="true">
    <div class="drop-overlay-inner">
        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
        <strong>Drop audio files to upload</strong>
        <span>MP3, WAV, OGG, M4A, AAC or FLAC · up to {{ $studio['limits']['max_upload_mb'] }} MB each</span>
    </div>
</div>
