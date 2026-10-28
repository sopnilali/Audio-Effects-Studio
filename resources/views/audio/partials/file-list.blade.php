<aside class="file-panel" id="filePanel" aria-label="Audio library">
    <div class="file-panel-scroll">
        <div class="panel-heading">
            <h2>Your audio</h2>
            <span class="count" id="fileCount">{{ $audioFiles->count() }}</span>
        </div>

        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <label class="visually-hidden" for="fileSearch">Search audio files</label>
            <input type="search" id="fileSearch" placeholder="Search your library…" autocomplete="off">
        </div>

        <div class="file-list" id="fileList" role="list">
            @foreach ($audioFiles as $file)
                @php($fx = $file->effectSettings())
                <article class="file-item {{ $selectedFile?->id === $file->id ? 'is-active' : '' }} {{ $file->status === 'failed' ? 'is-failed' : '' }}"
                         role="listitem"
                         tabindex="0"
                         data-audio-id="{{ $file->id }}"
                         data-status="{{ $file->status }}"
                         aria-current="{{ $selectedFile?->id === $file->id ? 'true' : 'false' }}"
                         aria-label="Open {{ $file->display_name }}">
                    <span class="file-icon" aria-hidden="true">
                        <i class="fa-solid fa-music"></i>
                    </span>
                    <div class="file-copy">
                        <div class="file-name" title="{{ $file->display_name }}">{{ $file->display_name }}</div>
                        <div class="file-meta">
                            {{ number_format($fx['speed'], 2) }}x speed, {{ number_format($fx['pitch_multiplier'], 2) }}x pitch, {{ $fx['reverb'] }}% reverb, {{ $fx['bass_boost'] }}% bass
                        </div>
                        <div class="file-sub">
                            <span>{{ strtoupper($file->extension) }}</span>
                            <span class="dot"></span>
                            <span>{{ $file->formattedDuration() }}</span>
                            <span class="dot"></span>
                            <span>{{ $file->humanFileSize() }}</span>
                        </div>
                    </div>
                    <div class="file-actions">
                        <span class="status-dot {{ $file->status }}" title="{{ ucfirst($file->status) }}"></span>
                        <div class="dropdown">
                            <button class="icon-button"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    data-bs-strategy="fixed"
                                    aria-expanded="false"
                                    aria-label="Options for {{ $file->display_name }}">
                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <button class="dropdown-item" type="button" data-action="rename" data-audio-id="{{ $file->id }}">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i> Rename
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item" type="button" data-action="export" data-audio-id="{{ $file->id }}" @disabled(! $file->isReady())>
                                        <i class="fa-solid fa-download" aria-hidden="true"></i> Download processed
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button class="dropdown-item text-danger" type="button" data-action="delete" data-audio-id="{{ $file->id }}">
                                        <i class="fa-regular fa-trash-can" aria-hidden="true"></i> Delete
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="empty-list {{ $audioFiles->isNotEmpty() ? 'hidden' : '' }}" id="emptyFileList">
            <span class="empty-icon"><i class="fa-solid fa-wave-square" aria-hidden="true"></i></span>
            <h3>No audio yet</h3>
            <p>Add your first track to start shaping speed, pitch, reverb and bass.</p>
            <button class="btn btn-studio-ghost btn-sm mt-3" type="button" data-add-files>
                <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Add audio
            </button>
        </div>

        <section class="selected-card {{ $selectedFile ? '' : 'hidden' }}" id="selectedInfo" aria-label="Selected file information">
            <h3>Selected file</h3>
            <dl class="selected-card-grid">
                <div>
                    <dt>Format</dt>
                    <dd id="selectedInfoFormat">{{ $selectedFile?->extension ? strtoupper($selectedFile->extension) : '—' }}</dd>
                </div>
                <div>
                    <dt>Duration</dt>
                    <dd id="selectedInfoDuration">{{ $selectedFile?->formattedDuration() ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Size</dt>
                    <dd id="selectedInfoSize">{{ $selectedFile?->humanFileSize() ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Sample rate</dt>
                    <dd id="selectedInfoSampleRate">{{ $selectedFile?->sample_rate ? number_format($selectedFile->sample_rate / 1000, 1).' kHz' : '—' }}</dd>
                </div>
                <div>
                    <dt>Channels</dt>
                    <dd id="selectedInfoChannels">{{ $selectedFile?->channels === 1 ? 'Mono' : ($selectedFile?->channels === 2 ? 'Stereo' : ($selectedFile?->channels ? $selectedFile->channels.' ch' : '—')) }}</dd>
                </div>
                <div>
                    <dt>Codec</dt>
                    <dd id="selectedInfoCodec">{{ $selectedFile?->codec ? strtoupper($selectedFile->codec) : '—' }}</dd>
                </div>
            </dl>
        </section>

        <div class="d-flex align-items-center justify-content-center gap-2 mt-3 text-studio-muted" style="font-size: 0.62rem;">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <span>Private storage · only you can access these files</span>
        </div>
    </div>
</aside>
