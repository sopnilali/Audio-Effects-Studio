/**
 * Real-time granular/Doppler pitch shifter used for browser preview.
 *
 * Two read heads travel through a circular delay buffer in opposite phase and
 * are cross-faded with triangular windows. Changing the delay at
 * (1 - pitch) samples per sample changes the playback rate of the read heads
 * while the write head stays at the source rate, so duration is preserved and
 * pitch moves independently. This is a preview-quality algorithm; FFmpeg's
 * asetrate + atempo chain is used for the exported file.
 */
class StudioPitchShifter extends AudioWorkletProcessor {
    static get parameterDescriptors() {
        return [
            {
                name: 'pitch',
                defaultValue: 1,
                minValue: 0.25,
                maxValue: 4,
                automationRate: 'k-rate',
            },
        ];
    }

    constructor() {
        super();
        this.windowSize = Math.max(1024, Math.round(sampleRate * 0.12));
        this.ring = [];
        this.writeIndex = 0;
        this.phaseA = 0;
        this.phaseB = 0.5;
    }

    initialize(channels) {
        this.ring = Array.from({ length: channels }, () => new Float32Array(this.windowSize));
        this.writeIndex = 0;
        this.phaseA = 0;
        this.phaseB = 0.5;
    }

    readSample(channel, delay) {
        let position = this.writeIndex - delay;

        while (position < 0) {
            position += this.windowSize;
        }

        const index = Math.floor(position);
        const fraction = position - index;
        const next = (index + 1) % this.windowSize;
        const data = this.ring[channel];

        return data[index] * (1 - fraction) + data[next] * fraction;
    }

    process(inputs, outputs, parameters) {
        const input = inputs[0];
        const output = outputs[0];

        if (!input || input.length === 0 || !output || output.length === 0) {
            return true;
        }

        const outputChannels = output.length;

        if (this.ring.length !== outputChannels) {
            this.initialize(outputChannels);
        }

        const pitch = Math.max(0.25, Math.min(4, parameters.pitch[0] ?? 1));
        const increment = (1 - pitch) / this.windowSize;
        const blockLength = output[0].length;
        const inputChannels = input.length;

        for (let i = 0; i < blockLength; i++) {
            for (let channel = 0; channel < outputChannels; channel++) {
                const sourceChannel = Math.min(channel, inputChannels - 1);
                this.ring[channel][this.writeIndex] = input[sourceChannel]?.[i] ?? 0;
            }

            const delayA = this.phaseA * this.windowSize;
            const delayB = this.phaseB * this.windowSize;
            const gainA = 1 - Math.abs(2 * this.phaseA - 1);
            const gainB = 1 - Math.abs(2 * this.phaseB - 1);

            for (let channel = 0; channel < outputChannels; channel++) {
                const sampleA = this.readSample(channel, delayA);
                const sampleB = this.readSample(channel, delayB);
                output[channel][i] = sampleA * gainA + sampleB * gainB;
            }

            this.writeIndex = (this.writeIndex + 1) % this.windowSize;

            this.phaseA += increment;
            if (this.phaseA >= 1) {
                this.phaseA -= 1;
            } else if (this.phaseA < 0) {
                this.phaseA += 1;
            }

            this.phaseB = this.phaseA + 0.5;
            if (this.phaseB >= 1) {
                this.phaseB -= 1;
            }
        }

        return true;
    }
}

registerProcessor('studio-pitch-shifter', StudioPitchShifter);
