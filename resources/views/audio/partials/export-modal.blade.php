<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="exportModalLabel">Export processed audio</h2>
                    <div class="text-studio-muted mt-1" style="font-size: 0.7rem;">This export uses the preset and effects currently selected in the editor.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="exportCloseIcon"></button>
            </div>

            <div class="modal-body">
                <form id="exportForm" novalidate>
                    <div class="export-format-grid" role="radiogroup" aria-label="Export format">
                        <div class="format-option">
                            <input type="radio" name="exportFormat" id="formatWav" value="wav" checked>
                            <label for="formatWav">
                                <span class="format-icon"><i class="fa-solid fa-file-audio" aria-hidden="true"></i></span>
                                <span class="format-copy">
                                    <strong>WAV</strong>
                                    <span>Lossless PCM · 16-bit</span>
                                </span>
                            </label>
                        </div>
                        <div class="format-option">
                            <input type="radio" name="exportFormat" id="formatMp3" value="mp3">
                            <label for="formatMp3">
                                <span class="format-icon"><i class="fa-solid fa-music" aria-hidden="true"></i></span>
                                <span class="format-copy">
                                    <strong>MP3</strong>
                                    <span>192 kbps · libmp3lame</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="export-summary">
                        <h3>What will be applied</h3>
                        <dl>
                            <dt>Preset</dt>
                            <dd id="exportPreset">—</dd>
                            <dt>File</dt>
                            <dd id="exportFileName">—</dd>
                            <dt>Speed</dt>
                            <dd id="exportSpeed">—</dd>
                            <dt>Pitch mode</dt>
                            <dd id="exportPitchMode">—</dd>
                            <dt>Pitch (extra)</dt>
                            <dd id="exportPitch">—</dd>
                            <dt>Reverb</dt>
                            <dd id="exportReverb">—</dd>
                            <dt>Bass boost</dt>
                            <dd id="exportBass">—</dd>
                            <dt>Processed duration</dt>
                            <dd id="exportDuration">—</dd>
                        </dl>
                    </div>

                    <div class="export-progress" id="exportProgress" aria-live="polite">
                        <div class="export-progress-head">
                            <span id="exportProgressLabel">Queued for processing…</span>
                            <strong id="exportProgressPercent">0%</strong>
                        </div>
                        <div class="progress" role="progressbar" aria-label="Export progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="exportProgressBar">
                            <div class="progress-bar" style="width: 0%"></div>
                        </div>
                    </div>

                    <div class="export-result" id="exportResult">
                        <span class="result-icon"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                        <div class="flex-grow-1">
                            <strong class="d-block">Your export is ready</strong>
                            <span class="text-studio-muted" id="exportResultMeta">Processed audio · verified</span>
                        </div>
                        <a class="btn btn-studio-primary btn-sm" id="exportDownloadButton" href="#" download>
                            <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Download
                        </a>
                    </div>

                    <div class="export-error" id="exportError" role="alert"></div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-studio-quiet d-none" id="exportCancelButton">
                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Cancel export
                </button>
                <button type="button" class="btn btn-studio-ghost" data-bs-dismiss="modal" id="exportCloseButton">Close</button>
                <button type="button" class="btn btn-studio-primary" id="exportStartButton">
                    <i class="fa-solid fa-wand-magic-sparkles me-2" aria-hidden="true"></i> Export WAV
                </button>
            </div>
        </div>
    </div>
</div>
