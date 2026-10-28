import { clamp } from './utils.js';

export class AudioEngine {
    constructor(audio, callbacks = {}) {
        this.audio = audio;
        this.callbacks = callbacks;
        this.context = null;
        this.source = null;
        this.bassFilter = null;
        this.dryGain = null;
        this.wetGain = null;
        this.convolver = null;
        this.masterGain = null;
        this.limiter = null;
        this.pitchNode = null;
        this.pitchSupported = false;
        this.pitchRouted = false;
        this.pitchValue = 1;
        this.pitchEnabled = false;
        this.pitchFollowsSpeed = true;
        this.lastSpeed = 1;
        this.supported = Boolean(window.AudioContext || window.webkitAudioContext);
        this.ready = false;
        this.initialization = this.init();
    }

    async init() {
        if (!this.supported) {
            this.callbacks.onUnsupported?.(
                'This browser does not support the Web Audio API. Speed still works, but reverb, bass and pitch preview are unavailable.',
            );
            return;
        }

        const AudioContextClass = window.AudioContext || window.webkitAudioContext;

        try {
            this.context = new AudioContextClass();
            this.source = this.context.createMediaElementSource(this.audio);

            this.bassFilter = this.context.createBiquadFilter();
            this.bassFilter.type = 'lowshelf';
            this.bassFilter.frequency.value = 150;
            this.bassFilter.gain.value = 0;

            this.dryGain = this.context.createGain();
            this.dryGain.gain.value = 1;

            this.wetGain = this.context.createGain();
            this.wetGain.gain.value = 0;

            this.convolver = this.context.createConvolver();
            this.convolver.normalize = true;
            this.convolver.buffer = this.createImpulseResponse();

            this.masterGain = this.context.createGain();
            this.masterGain.gain.value = 0.96;

            this.limiter = this.context.createDynamicsCompressor();
            this.limiter.threshold.value = -4;
            this.limiter.knee.value = 6;
            this.limiter.ratio.value = 16;
            this.limiter.attack.value = 0.003;
            this.limiter.release.value = 0.24;

            this.source.connect(this.bassFilter);
            this.bassFilter.connect(this.dryGain);
            this.dryGain.connect(this.masterGain);
            this.bassFilter.connect(this.convolver);
            this.convolver.connect(this.wetGain);
            this.wetGain.connect(this.masterGain);
            this.masterGain.connect(this.limiter);
            this.limiter.connect(this.context.destination);

            this.ready = true;

            await this.loadPitchWorklet();
            this.applyPitchRouting();
            this.callbacks.onReady?.();
        } catch (error) {
            console.error('Unable to initialize the Web Audio graph', error);
            this.ready = false;

            if (this.source && this.context) {
                try {
                    this.source.connect(this.context.destination);
                } catch (connectionError) {
                    console.error(connectionError);
                }
            }

            this.callbacks.onUnsupported?.(
                'Audio effects preview could not start in this browser. Playback and server exports still work.',
            );
        }
    }

    async loadPitchWorklet() {
        if (!this.context?.audioWorklet) {
            return;
        }

        try {
            await this.context.audioWorklet.addModule('/js/pitch-shift-worklet.js');

            this.pitchNode = new AudioWorkletNode(this.context, 'studio-pitch-shifter', {
                numberOfInputs: 1,
                numberOfOutputs: 1,
                outputChannelCount: [2],
                channelCount: 2,
                channelCountMode: 'explicit',
            });

            this.pitchNode.parameters.get('pitch').value = this.pitchValue;
            this.pitchSupported = true;
            this.callbacks.onPitchReady?.();
        } catch (error) {
            console.error('Pitch worklet unavailable', error);
            this.pitchSupported = false;
            this.callbacks.onPitchUnsupported?.(
                'Independent pitch preview is unavailable in this browser. Pitch export is still available.',
            );
        }
    }

    applyPitchRouting() {
        if (!this.ready || !this.pitchNode || !this.source || !this.bassFilter) {
            return;
        }

        const shouldRoute = this.pitchEnabled && this.pitchSupported && Math.abs(this.pitchValue - 1) > 0.001;

        if (shouldRoute === this.pitchRouted) {
            if (shouldRoute) {
                this.pitchNode.parameters.get('pitch').setTargetAtTime(this.pitchValue, this.context.currentTime, 0.02);
            }
            return;
        }

        try {
            this.source.disconnect(this.bassFilter);
        } catch (error) {
            // The source may not be connected yet.
        }

        try {
            this.pitchNode.disconnect(this.bassFilter);
        } catch (error) {
            // The worklet may not be connected yet.
        }

        if (shouldRoute) {
            this.source.connect(this.pitchNode);
            this.pitchNode.connect(this.bassFilter);
            this.pitchNode.parameters.get('pitch').setTargetAtTime(this.pitchValue, this.context.currentTime, 0.02);
        } else {
            this.source.connect(this.bassFilter);
        }

        this.pitchRouted = shouldRoute;
    }

    setSpeed(speed) {
        const value = clamp(Number(speed) || 1, 0.25, 2);
        const preservePitch = !this.pitchFollowsSpeed;

        this.lastSpeed = value;
        this.audio.preservesPitch = preservePitch;
        this.audio.webkitPreservesPitch = preservePitch;
        this.audio.mozPreservesPitch = preservePitch;
        this.audio.playbackRate = value;
    }

    /**
     * Tape mode: the playback rate changes pitch as well as tempo, so slower
     * audio sounds deeper and faster audio sounds thinner. When disabled the
     * browser keeps the original pitch and only the tempo changes.
     */
    setPitchFollowsSpeed(follow) {
        this.pitchFollowsSpeed = Boolean(follow);
        this.setSpeed(this.lastSpeed);
    }

    setReverb(amount) {
        if (!this.ready) {
            return;
        }

        const wetAmount = clamp(Number(amount) || 0, 0, 100) / 100;
        const dry = 1 - 0.35 * wetAmount;
        const wet = 0.9 * wetAmount;
        const now = this.context.currentTime;

        this.dryGain.gain.setTargetAtTime(dry, now, 0.03);
        this.wetGain.gain.setTargetAtTime(wet, now, 0.03);
    }

    setBass(amount) {
        if (!this.ready) {
            return;
        }

        const gain = 12 * (clamp(Number(amount) || 0, 0, 100) / 100);
        this.bassFilter.gain.setTargetAtTime(gain, this.context.currentTime, 0.03);
    }

    setPitch(value, enabled) {
        this.pitchValue = clamp(Number(value) || 1, 0.5, 2);
        this.pitchEnabled = Boolean(enabled);

        if (this.pitchNode) {
            this.pitchNode.parameters.get('pitch').setTargetAtTime(
                this.pitchEnabled ? this.pitchValue : 1,
                this.context.currentTime,
                0.02,
            );
        }

        this.applyPitchRouting();
    }

    async resume() {
        if (this.context && this.context.state === 'suspended') {
            try {
                await this.context.resume();
            } catch (error) {
                console.error('Unable to resume the audio context', error);
            }
        }
    }

    createImpulseResponse() {
        const sampleRate = this.context.sampleRate;
        const seconds = 2.4;
        const length = Math.floor(sampleRate * seconds);
        const buffer = this.context.createBuffer(2, length, sampleRate);
        const earlyReflections = [
            [Math.floor(0.031 * sampleRate), 0, 0.42],
            [Math.floor(0.047 * sampleRate), 1, 0.35],
            [Math.floor(0.071 * sampleRate), 0, -0.24],
            [Math.floor(0.113 * sampleRate), 1, 0.18],
        ];

        for (let channel = 0; channel < 2; channel++) {
            const data = buffer.getChannelData(channel);
            let smoothed = 0;

            for (let i = 0; i < length; i++) {
                const progress = i / length;
                const envelope = Math.exp(-3.2 * progress) * (1 - progress) ** 1.4;
                smoothed = smoothed * 0.35 + (Math.random() * 2 - 1) * 0.65;
                data[i] = smoothed * envelope * 0.55;
            }

            for (const [index, reflectionChannel, gain] of earlyReflections) {
                if (reflectionChannel === channel && index < length) {
                    data[index] += gain;
                }
            }
        }

        return buffer;
    }

    dispose() {
        try {
            this.source?.disconnect();
            this.bassFilter?.disconnect();
            this.dryGain?.disconnect();
            this.wetGain?.disconnect();
            this.convolver?.disconnect();
            this.masterGain?.disconnect();
            this.limiter?.disconnect();
            this.pitchNode?.disconnect();
            this.context?.close();
        } catch (error) {
            console.error(error);
        }
    }
}
