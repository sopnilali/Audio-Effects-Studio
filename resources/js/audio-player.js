import { formatTime, clamp } from './utils.js';
import { setRangeFill } from './ui.js';

export class PlayerController {
    constructor(audio, waveform, callbacks = {}) {
        this.audio = audio;
        this.waveform = waveform;
        this.callbacks = callbacks;
        this.currentFile = null;
        this.repeat = false;
        this.lastVolume = 0.8;

        this.elements = {
            playPause: document.getElementById('playPauseButton'),
            playPauseIcon: document.getElementById('playPauseIcon'),
            previous: document.getElementById('previousButton'),
            next: document.getElementById('nextButton'),
            repeat: document.getElementById('repeatButton'),
            volume: document.getElementById('volumeSlider'),
            mute: document.getElementById('muteButton'),
            volumeIcon: document.getElementById('volumeIcon'),
            progress: document.getElementById('playerProgress'),
            current: document.getElementById('playerCurrentTime'),
            total: document.getElementById('playerTotalTime'),
            title: document.getElementById('playerTitle'),
            subtitle: document.getElementById('playerSubtitle'),
            error: document.getElementById('playerError'),
        };

        this.bind();
        this.restoreVolume();
        this.bindAudioEvents();
    }

    bind() {
        this.elements.playPause?.addEventListener('click', () => this.togglePlay());
        this.elements.previous?.addEventListener('click', () => this.callbacks.onPrevious?.());
        this.elements.next?.addEventListener('click', () => this.callbacks.onNext?.());
        this.elements.repeat?.addEventListener('click', () => this.toggleRepeat());

        this.elements.volume?.addEventListener('input', (event) => {
            this.setVolume(Number(event.target.value) / 100);
        });

        this.elements.mute?.addEventListener('click', () => this.toggleMute());

        this.elements.progress?.addEventListener('input', (event) => {
            const duration = this.getDuration();
            if (duration > 0) {
                this.seekTo((Number(event.target.value) / 100) * duration);
            }
        });
    }

    bindAudioEvents() {
        this.audio.addEventListener('play', () => this.updatePlayState(true));
        this.audio.addEventListener('pause', () => this.updatePlayState(false));

        this.audio.addEventListener('timeupdate', () => {
            this.updateTime(this.audio.currentTime || 0, this.getDuration());
        });

        this.audio.addEventListener('loadedmetadata', () => {
            this.updateTime(this.audio.currentTime || 0, this.getDuration());
            this.setTotalTime(this.getDuration());
        });

        this.audio.addEventListener('ended', () => {
            this.updatePlayState(false);
            this.callbacks.onEnded?.();
        });

        this.audio.addEventListener('error', () => {
            if (!this.currentFile) {
                return;
            }

            this.showError('Playback failed. The preview may still be preparing.');
            this.updatePlayState(false);
        });

        this.audio.addEventListener('volumechange', () => {
            this.updateVolumeUI();
        });
    }

    setTrack(file) {
        this.currentFile = file;

        if (!file) {
            this.pause();
            this.elements.title.textContent = 'No track selected';
            this.elements.subtitle.textContent = 'Upload or select a track';
            this.elements.total.textContent = '0:00';
            this.elements.current.textContent = '0:00';
            this.elements.progress.value = '0';
            this.elements.progress.disabled = true;
            this.elements.playPause.disabled = true;
            this.elements.playPauseIcon.className = 'fa-solid fa-play';
            this.elements.playPause.setAttribute('aria-label', 'Play');
            this.hideError();
            return;
        }

        this.elements.title.textContent = file.display_name;
        this.elements.subtitle.textContent = `${file.extension} · ${file.formatted_duration}`;
        this.elements.total.textContent = file.formatted_duration || formatTime(file.duration_seconds);
        this.elements.current.textContent = '0:00';
        this.elements.progress.value = '0';
        this.elements.progress.disabled = !file.stream_url;
        this.elements.playPause.disabled = !file.stream_url;
        this.updateRangeFill(0);
        this.updatePlayState(false);
        this.hideError();
    }

    togglePlay() {
        if (!this.currentFile?.stream_url) {
            this.callbacks.onUnavailable?.('This audio is still being prepared. Please wait a moment.');
            return;
        }

        if (this.audio.paused) {
            this.callbacks.onBeforePlay?.();
            this.waveform
                .play()
                .then(() => {
                    this.hideError();
                })
                .catch((error) => {
                    console.error('Playback failed', error);
                    this.showError('Playback could not start. Tap play again or reload the page.');
                });
        } else {
            this.waveform.pause();
        }
    }

    play() {
        if (!this.currentFile?.stream_url) {
            return;
        }

        this.callbacks.onBeforePlay?.();
        this.waveform.play().catch((error) => {
            console.error('Playback failed', error);
            this.showError('Playback could not start.');
        });
    }

    pause() {
        this.waveform.pause();
    }

    seekTo(seconds) {
        this.waveform.seekTo(seconds);
    }

    seekBy(seconds) {
        this.waveform.seekBy(seconds);
    }

    seekPercent(percentValue) {
        const duration = this.getDuration();

        if (duration > 0) {
            this.seekTo((clamp(percentValue, 0, 100) / 100) * duration);
        }
    }

    toggleRepeat() {
        this.repeat = !this.repeat;
        this.elements.repeat?.classList.toggle('is-active', this.repeat);
        this.elements.repeat?.setAttribute('aria-pressed', String(this.repeat));
        this.callbacks.onRepeatChange?.(this.repeat);
    }

    setVolume(value) {
        const volume = clamp(Number(value) || 0, 0, 1);
        this.audio.volume = volume;

        if (volume > 0) {
            this.lastVolume = volume;
            this.audio.muted = false;
        }

        this.updateVolumeUI();
        this.persistVolume(volume);
    }

    toggleMute() {
        this.audio.muted = !this.audio.muted;
        this.updateVolumeUI();
    }

    updateVolumeUI() {
        const volume = this.audio.muted ? 0 : this.audio.volume;

        if (this.elements.volume) {
            this.elements.volume.value = String(Math.round(volume * 100));
            setRangeFill(this.elements.volume, volume * 100, 0, 100);
        }

        if (this.elements.volumeIcon) {
            let icon = 'fa-volume-high';

            if (volume === 0 || this.audio.muted) {
                icon = 'fa-volume-xmark';
            } else if (volume < 0.5) {
                icon = 'fa-volume-low';
            }

            this.elements.volumeIcon.className = `fa-solid ${icon}`;
        }

        if (this.elements.mute) {
            this.elements.mute.setAttribute('aria-label', this.audio.muted ? 'Unmute' : 'Mute');
        }
    }

    updatePlayState(playing) {
        if (!this.elements.playPauseIcon) {
            return;
        }

        this.elements.playPauseIcon.className = `fa-solid ${playing ? 'fa-pause' : 'fa-play'}`;
        this.elements.playPause?.setAttribute('aria-label', playing ? 'Pause' : 'Play');
        this.callbacks.onPlayStateChange?.(playing);
    }

    updateTime(current, duration) {
        if (this.elements.current) {
            this.elements.current.textContent = formatTime(current);
        }

        if (this.elements.total && duration > 0) {
            this.elements.total.textContent = formatTime(duration);
        }

        if (this.elements.progress && duration > 0) {
            const percent = clamp((current / duration) * 100, 0, 100);
            this.elements.progress.value = String(percent);
            this.updateRangeFill(percent);
        }
    }

    setTotalTime(duration) {
        if (this.elements.total && duration > 0) {
            this.elements.total.textContent = formatTime(duration);
        }
    }

    updateRangeFill(percentValue) {
        if (this.elements.progress) {
            this.elements.progress.style.setProperty('--range-progress', `${percentValue}%`);
        }
    }

    getDuration() {
        if (Number.isFinite(this.audio.duration) && this.audio.duration > 0) {
            return this.audio.duration;
        }

        return this.waveform.getDuration() || Number(this.currentFile?.duration_seconds) || 0;
    }

    showError(message) {
        if (!this.elements.error) {
            return;
        }

        const span = this.elements.error.querySelector('span');
        if (span) {
            span.textContent = message;
        }

        this.elements.error.classList.add('is-visible');
    }

    hideError() {
        this.elements.error?.classList.remove('is-visible');
    }

    restoreVolume() {
        let stored = null;

        try {
            stored = window.localStorage.getItem('studio-volume');
        } catch (error) {
            stored = null;
        }

        const volume = stored === null ? 0.8 : clamp(Number(stored), 0, 1);
        this.audio.volume = volume;
        this.lastVolume = volume > 0 ? volume : 0.8;
        this.updateVolumeUI();
    }

    persistVolume(volume) {
        try {
            window.localStorage.setItem('studio-volume', String(volume));
        } catch (error) {
            // Storage can be unavailable in private browsing; volume still works.
        }
    }
}
