import WaveSurfer from 'wavesurfer.js';
import { formatTime } from './utils.js';

export class WaveformController {
    constructor(audio, callbacks = {}) {
        this.audio = audio;
        this.callbacks = callbacks;
        this.container = document.getElementById('waveform');
        this.loadingElement = document.getElementById('waveformLoading');
        this.emptyElement = document.getElementById('waveformEmpty');
        this.currentTimeElement = document.getElementById('waveformCurrentTime');
        this.totalTimeElement = document.getElementById('waveformTotalTime');
        this.statusElement = document.getElementById('waveformStatus');
        this.currentFile = null;
        this.ws = null;
        this.create();
    }

    create() {
        if (!this.container) {
            return;
        }

        this.ws = WaveSurfer.create({
            container: this.container,
            media: this.audio,
            height: 118,
            waveColor: '#b89cbf',
            progressColor: '#ffffff',
            cursorColor: '#ffffff',
            cursorWidth: 2,
            barWidth: 2,
            barGap: 2,
            barRadius: 2,
            normalize: true,
            fillParent: true,
            interact: true,
            dragToSeek: true,
            hideScrollbar: true,
            autoScroll: false,
        });

        this.ws.on('ready', (duration) => {
            this.showLoading(false);
            this.setTotalTime(duration);
            this.callbacks.onReady?.(this.currentFile, duration);
        });

        this.ws.on('timeupdate', (currentTime, duration) => {
            this.updateTime(currentTime, duration);
            this.callbacks.onTime?.(currentTime, duration);
        });

        this.ws.on('play', () => this.callbacks.onPlay?.());
        this.ws.on('pause', () => this.callbacks.onPause?.());
        this.ws.on('finish', () => this.callbacks.onFinish?.());
        this.ws.on('interaction', () => this.callbacks.onInteraction?.());

        this.ws.on('error', (error) => {
            console.error('Waveform error', error);
            this.showLoading(false);
            this.setStatus('Waveform could not be loaded');
            this.callbacks.onError?.('The waveform could not be loaded. Playback may still be available.');
        });
    }

    load(file) {
        this.currentFile = file;

        if (!this.ws) {
            return;
        }

        this.audio.pause();

        if (!file) {
            this.clear();
            return;
        }

        this.setTotalTime(file.duration_seconds ?? 0);
        this.updateTime(0, file.duration_seconds ?? 0);

        if (!file.stream_url || !Array.isArray(file.waveform) || file.waveform.length === 0) {
            this.ws.empty();
            this.showLoading(false);
            this.setEmptyMessage(
                file.status === 'failed'
                    ? 'This audio could not be prepared for playback'
                    : 'Preparing this audio for playback…',
            );
            this.emptyElement?.classList.remove('hidden');
            this.setStatus(
                file.status === 'failed'
                    ? 'Audio preparation failed'
                    : 'Waiting for audio preparation',
            );

            return;
        }

        this.emptyElement?.classList.add('hidden');
        this.setStatus('Original audio waveform');
        this.setEmptyMessage('Select an audio file to see its waveform');
        this.showLoading(true);

        this.ws
            .load(file.stream_url, [file.waveform], Number(file.duration_seconds) || undefined)
            .then(() => {
                this.showLoading(false);
            })
            .catch((error) => {
                console.error('Unable to load waveform', error);
                this.showLoading(false);
                this.setStatus('Waveform could not be loaded');
                this.callbacks.onError?.('The waveform could not be loaded.');
            });
    }

    clear() {
        this.currentFile = null;
        this.audio.pause();
        this.ws?.empty();
        this.showLoading(false);
        this.emptyElement?.classList.remove('hidden');
        this.setEmptyMessage('Select an audio file to see its waveform');
        this.setStatus('No audio selected');
        this.updateTime(0, 0);
        this.setTotalTime(0);
    }

    play() {
        if (!this.ws) {
            return Promise.reject(new Error('Waveform is not ready.'));
        }

        return this.ws.play();
    }

    pause() {
        this.ws?.pause();
    }

    seekTo(seconds) {
        if (!this.ws) {
            return;
        }

        const duration = this.ws.getDuration();

        if (!Number.isFinite(duration) || duration <= 0) {
            return;
        }

        this.ws.seekTo(Math.max(0, Math.min(1, seconds / duration)));
    }

    seekBy(seconds) {
        if (!this.ws) {
            return;
        }

        const duration = this.ws.getDuration();
        const current = this.ws.getCurrentTime();

        if (Number.isFinite(duration) && duration > 0) {
            this.seekTo(current + seconds);
        }
    }

    isPlaying() {
        return Boolean(this.ws?.isPlaying());
    }

    getCurrentTime() {
        return this.ws?.getCurrentTime() ?? this.audio.currentTime ?? 0;
    }

    getDuration() {
        return this.ws?.getDuration() ?? this.audio.duration ?? 0;
    }

    updateTime(currentTime, duration) {
        if (this.currentTimeElement) {
            this.currentTimeElement.textContent = formatTime(currentTime);
        }

        if (this.container) {
            this.container.setAttribute('aria-valuenow', String(Math.round(currentTime || 0)));
            this.container.setAttribute('aria-valuemax', String(Math.round(duration || 0)));
        }
    }

    setTotalTime(duration) {
        if (this.totalTimeElement) {
            this.totalTimeElement.textContent = formatTime(duration);
        }
    }

    setStatus(text) {
        if (this.statusElement) {
            this.statusElement.textContent = text;
        }
    }

    setEmptyMessage(text) {
        const span = this.emptyElement?.querySelector('span');

        if (span) {
            span.textContent = text;
        }
    }

    showLoading(visible) {
        this.loadingElement?.classList.toggle('hidden', !visible);
    }

    destroy() {
        this.ws?.destroy();
        this.ws = null;
    }
}
