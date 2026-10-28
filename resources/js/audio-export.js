import { fetchJson, formatTime } from './utils.js';
import { setButtonBusy, showToast } from './ui.js';

export class ExportManager {
    constructor(options) {
        this.getSelected = options.getSelected;
        this.getSettings = options.getSettings;
        this.getPresetLabel = options.getPresetLabel ?? (() => 'Custom');
        this.beforeStart = options.beforeStart;
        this.environment = options.environment ?? { ok: true };
        this.callbacks = options.callbacks ?? {};
        this.modalElement = document.getElementById('exportModal');
        this.modal = this.modalElement && window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(this.modalElement)
            : null;

        this.elements = {
            form: document.getElementById('exportForm'),
            start: document.getElementById('exportStartButton'),
            cancel: document.getElementById('exportCancelButton'),
            close: document.getElementById('exportCloseButton'),
            closeIcon: document.getElementById('exportCloseIcon'),
            progress: document.getElementById('exportProgress'),
            progressBar: document.getElementById('exportProgressBar'),
            progressPercent: document.getElementById('exportProgressPercent'),
            progressLabel: document.getElementById('exportProgressLabel'),
            result: document.getElementById('exportResult'),
            resultMeta: document.getElementById('exportResultMeta'),
            download: document.getElementById('exportDownloadButton'),
            error: document.getElementById('exportError'),
            preset: document.getElementById('exportPreset'),
            fileName: document.getElementById('exportFileName'),
            speed: document.getElementById('exportSpeed'),
            pitchMode: document.getElementById('exportPitchMode'),
            pitch: document.getElementById('exportPitch'),
            reverb: document.getElementById('exportReverb'),
            bass: document.getElementById('exportBass'),
            duration: document.getElementById('exportDuration'),
        };

        this.running = false;
        this.job = null;
        this.statusUrl = null;
        this.cancelUrl = null;
        this.pollTimer = null;
        this.pollStartedAt = 0;

        this.bind();
    }

    bind() {
        document.querySelectorAll('[data-export-format]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                this.open(button.dataset.exportFormat || '');
            });
        });

        this.elements.start?.addEventListener('click', () => this.start());
        this.elements.cancel?.addEventListener('click', () => this.cancel());

        document.querySelectorAll('input[name="exportFormat"]').forEach((input) => {
            input.addEventListener('change', () => this.updateStartLabel());
        });

        this.modalElement?.addEventListener('hidden.bs.modal', () => {
            if (!this.running) {
                this.stopPolling();
            }
        });
    }

    open(format = '') {
        const selected = this.getSelected();

        if (!selected) {
            showToast('Select an audio file before exporting.', 'warning');
            return;
        }

        if (!selected.stream_url || selected.status !== 'ready') {
            showToast('This audio is still being prepared. Try again when it is ready.', 'warning');
            return;
        }

        if (!this.environment.ok) {
            showToast('Export is unavailable because the audio engine is offline.', 'error');
            return;
        }

        this.reset();

        const targetFormat = format === 'mp3' ? 'mp3' : 'wav';
        const radio = document.querySelector(`input[name="exportFormat"][value="${targetFormat}"]`);

        if (radio) {
            radio.checked = true;
        }

        this.updateSummary();
        this.updateStartLabel();
        this.modal?.show();
    }

    reset() {
        this.stopPolling();
        this.running = false;
        this.job = null;
        this.statusUrl = null;
        this.cancelUrl = null;

        this.elements.progress?.classList.remove('is-visible');
        this.elements.result?.classList.remove('is-visible');
        this.elements.error?.classList.remove('is-visible');
        this.elements.cancel?.classList.add('d-none');
        this.setProgress(0, 'Queued for processing…');

        if (this.elements.download) {
            this.elements.download.href = '#';
            this.elements.download.removeAttribute('download');
        }

        if (this.elements.start) {
            this.elements.start.disabled = false;
        }

        if (this.elements.close) {
            this.elements.close.disabled = false;
        }

        if (this.elements.closeIcon) {
            this.elements.closeIcon.disabled = false;
        }

        this.updateStartLabel();
    }

    updateSummary() {
        const selected = this.getSelected();

        if (!selected) {
            return;
        }

        const settings = this.getSettings();
        const speedValue = Number(settings.speed) || 1;
        const speed = Math.max(0.25, speedValue);
        const duration = (Number(selected.duration_seconds) || 0) / speed;
        const follow = Boolean(settings.pitch_follows_speed);
        const extraPitch = settings.pitch_enabled ? Math.max(0.5, Number(settings.pitch_multiplier) || 1) : 1;
        const extraSemitones = 12 * Math.log2(extraPitch);
        const pitchMode = follow
            ? (Math.abs(speed - 1) > 0.001 ? 'Tape mode · pitch follows speed' : 'Tape mode · speed unchanged')
            : 'Fixed pitch · speed does not change pitch';
        const pitchText = settings.pitch_enabled
            ? (Math.abs(extraSemitones) > 0.05
                ? `${extraPitch.toFixed(2)}x (${extraSemitones > 0 ? '+' : ''}${extraSemitones.toFixed(1)} st)`
                : 'On · no extra shift')
            : 'Off (0%)';

        this.elements.preset.textContent = this.getPresetLabel(settings);
        this.elements.fileName.textContent = selected.display_name;
        this.elements.speed.textContent = `${speedValue.toFixed(2)}x`;
        this.elements.pitchMode.textContent = pitchMode;
        this.elements.pitch.textContent = pitchText;
        this.elements.reverb.textContent = `${Math.round(Number(settings.reverb))}%`;
        this.elements.bass.textContent = `${Math.round(Number(settings.bass_boost))}%`;
        this.elements.duration.textContent = formatTime(duration);
    }

    updateStartLabel() {
        if (!this.elements.start || this.running) {
            return;
        }

        const format = document.querySelector('input[name="exportFormat"]:checked')?.value ?? 'wav';
        this.elements.start.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles me-2" aria-hidden="true"></i> Export ${format.toUpperCase()}`;
    }

    async start() {
        if (this.running) {
            return;
        }

        const selected = this.getSelected();

        if (!selected) {
            showToast('Select an audio file before exporting.', 'warning');
            return;
        }

        const format = document.querySelector('input[name="exportFormat"]:checked')?.value ?? 'wav';
        this.running = true;
        this.setRunningUI(true);
        this.elements.error?.classList.remove('is-visible');
        this.elements.result?.classList.remove('is-visible');
        this.elements.progress?.classList.add('is-visible');
        this.setProgress(0, 'Submitting export…');

        try {
            await this.beforeStart?.(selected);

            const settings = this.getSettings();
            this.updateSummary();

            const payload = await fetchJson(selected.export_url, {
                method: 'POST',
                body: JSON.stringify({
                    format,
                    settings,
                }),
            });

            this.job = payload.data.job;
            this.statusUrl = payload.data.status_url;
            this.cancelUrl = payload.data.cancel_url;

            showToast(payload.message ?? 'Export queued.', 'info');

            if (this.job.status === 'completed') {
                this.complete(this.job);
                return;
            }

            if (this.job.status === 'failed') {
                this.fail(this.job.error ?? 'The export failed.');
                return;
            }

            this.updateProgress(this.job);
            this.startPolling();
        } catch (error) {
            this.fail(error.firstValidationMessage?.() ?? error.message ?? 'The export could not be started.');
        }
    }

    startPolling() {
        this.stopPolling();
        this.pollStartedAt = Date.now();
        this.pollTimer = window.setInterval(() => this.poll(), 1200);
    }

    stopPolling() {
        if (this.pollTimer) {
            window.clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    async poll() {
        if (!this.statusUrl) {
            return;
        }

        if (Date.now() - this.pollStartedAt > 15 * 60 * 1000) {
            this.fail('The export is taking too long. Check that a queue worker is running, then try again.');
            return;
        }

        try {
            const payload = await fetchJson(this.statusUrl);
            const job = payload.data;
            this.job = job;
            this.updateProgress(job);

            if (job.status === 'completed') {
                this.complete(job);
            } else if (job.status === 'failed') {
                this.fail(job.error ?? 'The export failed.');
            } else if (job.status === 'cancelled') {
                this.fail('The export was cancelled.');
            }
        } catch (error) {
            this.fail(error.message ?? 'Unable to check the export status.');
        }
    }

    updateProgress(job) {
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        let label = 'Queued for processing…';

        if (job.status === 'processing') {
            label = 'Rendering audio with FFmpeg…';
        } else if (job.status === 'completed') {
            label = 'Export complete';
        } else if (job.status === 'failed') {
            label = 'Export failed';
        } else if (job.status === 'cancelled') {
            label = 'Export cancelled';
        }

        this.setProgress(progress, label);
        this.elements.progress?.classList.add('is-visible');
        this.elements.cancel?.classList.toggle('d-none', !job.cancellable);
    }

    setProgress(percent, label) {
        if (this.elements.progressBar) {
            this.elements.progressBar.style.width = `${percent}%`;
            this.elements.progressBar.setAttribute('aria-valuenow', String(percent));
        }

        if (this.elements.progressPercent) {
            this.elements.progressPercent.textContent = `${percent}%`;
        }

        if (this.elements.progressLabel) {
            this.elements.progressLabel.textContent = label;
        }
    }

    complete(job) {
        this.running = false;
        this.stopPolling();
        this.setProgress(100, 'Export complete');
        this.elements.progress?.classList.add('is-visible');
        this.elements.result?.classList.add('is-visible');
        this.elements.cancel?.classList.add('d-none');
        this.elements.error?.classList.remove('is-visible');
        this.elements.resultMeta.textContent = job.download_name ?? 'Processed audio · verified';

        if (this.elements.download && job.download_url) {
            this.elements.download.href = job.download_url;
            this.elements.download.setAttribute('download', job.download_name ?? 'processed-audio');
        }

        this.setRunningUI(false);
        this.elements.start.disabled = true;
        showToast('Export complete. Your download is ready.', 'success');
        this.callbacks.onCompleted?.(job);
    }

    fail(message) {
        this.running = false;
        this.stopPolling();
        this.elements.error.textContent = message;
        this.elements.error.classList.add('is-visible');
        this.elements.cancel?.classList.add('d-none');
        this.setRunningUI(false);
        showToast(message, 'error');
    }

    setRunningUI(running) {
        this.running = running;
        setButtonBusy(this.elements.start, running, 'Rendering…');

        if (this.elements.close) {
            this.elements.close.disabled = running;
        }

        if (this.elements.closeIcon) {
            this.elements.closeIcon.disabled = running;
        }

        if (running) {
            this.elements.cancel?.classList.remove('d-none');
        } else {
            this.elements.cancel?.classList.add('d-none');
            this.updateStartLabel();
        }
    }

    async cancel() {
        if (!this.cancelUrl) {
            return;
        }

        try {
            const payload = await fetchJson(this.cancelUrl, { method: 'POST' });
            this.updateProgress(payload.data);
            this.fail('The export was cancelled.');
        } catch (error) {
            showToast(error.firstValidationMessage?.() ?? error.message ?? 'This export cannot be cancelled.', 'error');
        }
    }
}
