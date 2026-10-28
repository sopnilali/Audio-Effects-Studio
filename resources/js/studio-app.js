import { AudioEngine } from './audio-effects.js';
import { WaveformController } from './audio-waveform.js';
import { PlayerController } from './audio-player.js';
import { UploadManager } from './audio-upload.js';
import { ExportManager } from './audio-export.js';
import { readJsonScript, fetchJson, formatTime, round, clamp } from './utils.js';
import { showToast, confirmAction, setRangeFill } from './ui.js';

const PROCESSING_STATUSES = ['uploaded', 'processing'];

export class StudioApp {
    constructor() {
        this.data = readJsonScript('studioData') ?? {};
        this.files = new Map(
            (this.data.files ?? []).map((file) => [Number(file.id), file]),
        );
        this.order = (this.data.files ?? []).map((file) => Number(file.id));
        this.selectedId = this.data.selectedId ? Number(this.data.selectedId) : null;
        this.presets = this.data.presets ?? {};
        this.limits = this.data.limits ?? {};
        this.environment = this.data.environment ?? { ok: true, errors: [] };
        this.defaults = {
            speed: 1,
            pitch_follows_speed: true,
            pitch_enabled: false,
            pitch_multiplier: 1,
            reverb: 0,
            bass_boost: 0,
            preset_name: null,
            ...(this.data.defaults ?? {}),
        };

        this.searchQuery = '';
        this.pendingAutoplay = false;
        this.saveTimers = new Map();
        this.saveSequences = new Map();
        this.savePromises = new Map();
        this.pollTimers = new Map();
        this.pollInFlight = new Set();
        this.pitchWarningShown = false;
        this.effectToastTimer = null;
        this.renameFileId = null;
        this.audio = document.getElementById('studioAudio');

        this.cacheDom();
        this.engine = new AudioEngine(this.audio, {
            onUnsupported: (message) => showToast(message, 'warning', 9000),
            onPitchUnsupported: (message) => {
                this.pitchWarningShown = true;
                showToast(message, 'warning', 9000);
            },
            onReady: () => this.applyCurrentEffects(),
            onPitchReady: () => this.applyCurrentEffects(),
        });
        this.waveform = new WaveformController(this.audio, {
            onReady: () => this.handleWaveformReady(),
            onError: (message) => showToast(message, 'error'),
            onInteraction: () => this.engine.resume(),
        });
        this.player = new PlayerController(this.audio, this.waveform, {
            onPrevious: () => this.selectAdjacent(-1),
            onNext: () => this.selectAdjacent(1),
            onEnded: () => this.handleEnded(),
            onBeforePlay: () => this.engine.resume(),
            onUnavailable: (message) => showToast(message, 'warning'),
        });
        this.uploads = new UploadManager({
            input: document.getElementById('audioFileInput'),
            uploadUrl: this.data.routes?.upload ?? '/audio/upload',
            presignUrl: this.data.routes?.uploadPresign ?? '/audio/upload/presign',
            completeUrl: this.data.routes?.uploadComplete ?? '/audio/upload/complete',
            directUpload: Boolean(this.data.upload?.direct),
            maxUploadMb: this.limits.max_upload_mb ?? 10,
            maxFiles: this.limits.max_files ?? 10,
            callbacks: {
                onUploaded: (files, errors, message) => this.handleUploaded(files, errors, message),
                onError: (message) => showToast(message, 'error', 7000),
            },
        });
        this.exporter = new ExportManager({
            getSelected: () => this.getSelected(),
            getSettings: () => this.settingsForRequest(this.getSelected()),
            getPresetLabel: (settings) => this.presetLabel(settings),
            beforeStart: (file) => this.flushEffectsSave(file),
            environment: this.environment,
        });

        this.bind();
        this.renderFileList();
        this.selectFile(this.selectedId, { autoplay: false, switchView: false });
        this.startProcessingPolls();
        this.flashMessages();
        this.updateEnvironment(this.environment);

        window.addEventListener('beforeunload', () => {
            this.waveform.destroy();
            this.engine.dispose();
        });
    }

    cacheDom() {
        this.dom = {
            shell: document.getElementById('studioShell'),
            fileList: document.getElementById('fileList'),
            fileCount: document.getElementById('fileCount'),
            fileSearch: document.getElementById('fileSearch'),
            emptyFileList: document.getElementById('emptyFileList'),
            selectedInfo: document.getElementById('selectedInfo'),
            selectedInfoFormat: document.getElementById('selectedInfoFormat'),
            selectedInfoDuration: document.getElementById('selectedInfoDuration'),
            selectedInfoSize: document.getElementById('selectedInfoSize'),
            selectedInfoSampleRate: document.getElementById('selectedInfoSampleRate'),
            selectedInfoChannels: document.getElementById('selectedInfoChannels'),
            selectedInfoCodec: document.getElementById('selectedInfoCodec'),
            editorEmpty: document.getElementById('editorEmpty'),
            editorContent: document.getElementById('editorContent'),
            editorTitle: document.getElementById('editorTitle'),
            editorStatusBadge: document.getElementById('editorStatusBadge'),
            metaFormat: document.getElementById('metaFormat'),
            metaDuration: document.getElementById('metaDuration'),
            metaSize: document.getElementById('metaSize'),
            metaSampleRate: document.getElementById('metaSampleRate'),
            metaChannels: document.getElementById('metaChannels'),
            processingNote: document.getElementById('processingNote'),
            renameSelectedButton: document.getElementById('renameSelectedButton'),
            resetEffectsButton: document.getElementById('resetEffectsButton'),
            speedSlider: document.getElementById('speedSlider'),
            speedValue: document.getElementById('speedValue'),
            speedReset: document.getElementById('speedReset'),
            speedDuration: document.getElementById('speedDuration'),
            speedModeHint: document.getElementById('speedModeHint'),
            pitchFollowToggle: document.getElementById('pitchFollowToggle'),
            reverbSlider: document.getElementById('reverbSlider'),
            reverbValue: document.getElementById('reverbValue'),
            reverbReset: document.getElementById('reverbReset'),
            pitchSlider: document.getElementById('pitchSlider'),
            pitchValue: document.getElementById('pitchValue'),
            pitchUnit: document.getElementById('pitchUnit'),
            pitchToggle: document.getElementById('pitchToggle'),
            pitchReset: document.getElementById('pitchReset'),
            pitchSemitones: document.getElementById('pitchSemitones'),
            bassSlider: document.getElementById('bassSlider'),
            bassValue: document.getElementById('bassValue'),
            bassReset: document.getElementById('bassReset'),
            presetGrid: document.getElementById('presetGrid'),
            exportSummary: document.getElementById('exportSummary'),
            clearWorkspaceButton: document.getElementById('clearWorkspaceButton'),
            downloadMenuButton: document.getElementById('downloadMenuButton'),
            engineStatus: document.getElementById('engineStatus'),
            engineStatusText: document.getElementById('engineStatusText'),
            previousButton: document.getElementById('previousButton'),
            nextButton: document.getElementById('nextButton'),
            playPauseButton: document.getElementById('playPauseButton'),
            renameForm: document.getElementById('renameForm'),
            renameInput: document.getElementById('renameInput'),
            renameError: document.getElementById('renameError'),
            renameSaveButton: document.getElementById('renameSaveButton'),
            renameModal: document.getElementById('renameModal'),
        };
    }

    bind() {
        this.dom.fileList?.addEventListener('click', (event) => this.handleFileListClick(event));
        this.dom.fileList?.addEventListener('keydown', (event) => this.handleFileListKeydown(event));

        this.dom.fileSearch?.addEventListener('input', (event) => {
            this.searchQuery = event.target.value.trim();
            this.renderFileList();
        });

        this.dom.presetGrid?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-preset]');
            if (button) {
                this.applyPreset(button.dataset.preset);
            }
        });

        this.dom.speedSlider?.addEventListener('input', (event) => {
            this.updateEffect('speed', round(Number(event.target.value), 2));
        });
        this.dom.reverbSlider?.addEventListener('input', (event) => {
            this.updateEffect('reverb', Math.round(Number(event.target.value)));
        });
        this.dom.pitchSlider?.addEventListener('input', (event) => {
            this.updateEffect('pitch_multiplier', round(Number(event.target.value), 2));
        });
        this.dom.bassSlider?.addEventListener('input', (event) => {
            this.updateEffect('bass_boost', Math.round(Number(event.target.value)));
        });

        this.dom.speedReset?.addEventListener('click', () => this.resetEffect('speed'));
        this.dom.reverbReset?.addEventListener('click', () => this.resetEffect('reverb'));
        this.dom.pitchReset?.addEventListener('click', () => this.resetEffect('pitch'));
        this.dom.bassReset?.addEventListener('click', () => this.resetEffect('bass'));
        this.dom.resetEffectsButton?.addEventListener('click', () => this.applyPreset('original'));
        this.dom.pitchFollowToggle?.addEventListener('click', () => this.togglePitchFollow());
        this.dom.pitchToggle?.addEventListener('click', () => this.togglePitch());
        this.dom.renameSelectedButton?.addEventListener('click', () => {
            const file = this.getSelected();
            if (file) {
                this.openRename(file);
            }
        });

        this.dom.renameForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitRename();
        });

        this.dom.clearWorkspaceButton?.addEventListener('click', () => this.clearWorkspace());

        document.querySelectorAll('[data-mobile-view]').forEach((button) => {
            button.addEventListener('click', () => this.setMobileView(button.dataset.mobileView));
        });

        document.addEventListener('keydown', (event) => this.handleKeyboard(event));
    }

    getSelected() {
        return this.selectedId ? this.files.get(this.selectedId) ?? null : null;
    }

    normalizeFile(file) {
        return {
            ...file,
            id: Number(file.id),
            effects: {
                ...this.defaults,
                ...(file.effects ?? {}),
            },
        };
    }

    renderFileList() {
        if (!this.dom.fileList) {
            return;
        }

        const query = this.searchQuery.toLocaleLowerCase();
        const fragment = document.createDocumentFragment();
        let visible = 0;

        for (const id of this.order) {
            const file = this.files.get(id);

            if (!file) {
                continue;
            }

            if (query && !file.display_name.toLocaleLowerCase().includes(query)) {
                continue;
            }

            fragment.append(this.createFileItem(file));
            visible++;
        }

        this.dom.fileList.replaceChildren(fragment);
        this.dom.emptyFileList?.classList.toggle('hidden', visible > 0);

        if (this.dom.fileCount) {
            this.dom.fileCount.textContent = String(this.files.size);
        }

        if (visible === 0 && this.dom.emptyFileList) {
            const heading = this.dom.emptyFileList.querySelector('h3');
            const copy = this.dom.emptyFileList.querySelector('p');

            if (this.files.size > 0 && query) {
                heading.textContent = 'No matches';
                copy.textContent = 'Try a different search term or clear the search field.';
            } else {
                heading.textContent = 'No audio yet';
                copy.textContent = 'Add your first track to start shaping speed, pitch, reverb and bass.';
            }
        }

        this.updatePlayerNavigation();
        this.updateExportAvailability();
    }

    createFileItem(file) {
        const item = document.createElement('article');
        item.className = 'file-item';
        item.classList.toggle('is-active', file.id === this.selectedId);
        item.classList.toggle('is-failed', file.status === 'failed');
        item.setAttribute('role', 'listitem');
        item.setAttribute('tabindex', '0');
        item.setAttribute('aria-current', file.id === this.selectedId ? 'true' : 'false');
        item.setAttribute('aria-label', `Open ${file.display_name}`);
        item.dataset.audioId = String(file.id);
        item.dataset.status = file.status;

        const icon = document.createElement('span');
        icon.className = 'file-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<i class="fa-solid fa-music"></i>';

        const copy = document.createElement('div');
        copy.className = 'file-copy';

        const name = document.createElement('div');
        name.className = 'file-name';
        name.title = file.display_name;
        name.textContent = file.display_name;

        const effects = file.effects ?? this.defaults;
        const meta = document.createElement('div');
        meta.className = 'file-meta';
        meta.textContent = `${Number(effects.speed).toFixed(2)}x speed${effects.pitch_follows_speed ? ' (tape)' : ''}, ${Number(effects.pitch_multiplier).toFixed(2)}x pitch, ${Math.round(effects.reverb)}% reverb, ${Math.round(effects.bass_boost)}% bass`;

        const sub = document.createElement('div');
        sub.className = 'file-sub';
        const format = document.createElement('span');
        format.textContent = file.extension;
        const dot1 = document.createElement('span');
        dot1.className = 'dot';
        const duration = document.createElement('span');
        duration.textContent = file.formatted_duration;
        const dot2 = document.createElement('span');
        dot2.className = 'dot';
        const size = document.createElement('span');
        size.textContent = file.human_size;
        sub.append(format, dot1, duration, dot2, size);

        copy.append(name, meta, sub);

        const actions = document.createElement('div');
        actions.className = 'file-actions';

        const status = document.createElement('span');
        status.className = `status-dot ${file.status}`;
        status.title = file.status.charAt(0).toUpperCase() + file.status.slice(1);

        const dropdown = document.createElement('div');
        dropdown.className = 'dropdown';

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'icon-button';
        toggle.dataset.bsToggle = 'dropdown';
        toggle.dataset.bsStrategy = 'fixed';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', `Options for ${file.display_name}`);
        toggle.innerHTML = '<i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>';

        const menu = document.createElement('ul');
        menu.className = 'dropdown-menu dropdown-menu-end';
        menu.append(
            this.createMenuItem('fa-pen', 'Rename', 'rename', file.id),
            this.createMenuItem('fa-download', 'Download processed', 'export', file.id, file.status !== 'ready'),
            document.createElement('li'),
        );
        menu.lastElementChild.innerHTML = '<hr class="dropdown-divider">';
        menu.append(this.createMenuItem('fa-trash-can', 'Delete', 'delete', file.id, false, true));

        dropdown.append(toggle, menu);
        actions.append(status, dropdown);

        item.append(icon, copy, actions);

        return item;
    }

    createMenuItem(iconClass, label, action, fileId, disabled = false, danger = false) {
        const li = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `dropdown-item${danger ? ' text-danger' : ''}`;
        button.dataset.action = action;
        button.dataset.audioId = String(fileId);
        button.disabled = disabled;

        const icon = document.createElement('i');
        icon.className = `fa-solid ${iconClass}`;
        icon.setAttribute('aria-hidden', 'true');

        button.append(icon, document.createTextNode(` ${label}`));
        li.append(button);

        return li;
    }

    handleFileListClick(event) {
        const action = event.target.closest('[data-action]');

        if (action) {
            event.preventDefault();
            event.stopPropagation();
            this.handleFileAction(action.dataset.action, Number(action.dataset.audioId));
            return;
        }

        if (event.target.closest('[data-bs-toggle="dropdown"]')) {
            return;
        }

        const item = event.target.closest('.file-item');

        if (item) {
            this.selectFile(Number(item.dataset.audioId));
        }
    }

    handleFileListKeydown(event) {
        if (!['Enter', ' '].includes(event.key)) {
            return;
        }

        if (event.target.closest('[data-action]') || event.target.closest('[data-bs-toggle]')) {
            return;
        }

        const item = event.target.closest('.file-item');

        if (item) {
            event.preventDefault();
            this.selectFile(Number(item.dataset.audioId));
        }
    }

    handleFileAction(action, fileId) {
        const file = this.files.get(fileId);

        if (!file) {
            return;
        }

        if (action === 'rename') {
            this.openRename(file);
            return;
        }

        if (action === 'export') {
            if (file.status !== 'ready') {
                showToast('This audio is still being prepared.', 'warning');
                return;
            }

            this.selectFile(file.id, { switchView: false });
            this.exporter.open('wav');
            return;
        }

        if (action === 'delete') {
            this.deleteFile(file);
        }
    }

    selectFile(id, options = {}) {
        const file = id ? this.files.get(Number(id)) : null;

        if (!file) {
            this.selectedId = null;
            this.waveform.clear();
            this.player.setTrack(null);
            this.renderFileList();
            this.updateEditor();
            return;
        }

        const wasPlaying = this.waveform.isPlaying();
        this.selectedId = file.id;
        this.pendingAutoplay = Boolean(options.autoplay || wasPlaying);

        this.renderFileList();
        this.updateEditor();
        this.player.setTrack(file);
        this.loadSelectedWaveform(file);
        this.updateExportAvailability();
        this.updatePlayerNavigation();

        if (options.switchView !== false && window.matchMedia('(max-width: 991.98px)').matches) {
            this.setMobileView('editor');
        }
    }

    loadSelectedWaveform(file) {
        this.waveform.load(file);

        if (PROCESSING_STATUSES.includes(file.status)) {
            this.pollProcessing(file);
        }
    }

    updateEditor() {
        const file = this.getSelected();

        this.dom.editorEmpty?.classList.toggle('hidden', Boolean(file));
        this.dom.editorContent?.classList.toggle('hidden', !file);
        this.dom.selectedInfo?.classList.toggle('hidden', !file);

        if (!file) {
            if (this.dom.editorTitle) {
                this.dom.editorTitle.textContent = 'Select an audio file';
            }

            if (this.dom.editorStatusBadge) {
                this.dom.editorStatusBadge.textContent = 'No file';
                this.dom.editorStatusBadge.className = 'badge-studio';
            }

            this.dom.processingNote?.classList.add('hidden');
            this.dom.renameSelectedButton?.setAttribute('disabled', 'disabled');
            this.syncEffectsUI();
            return;
        }

        if (this.dom.editorTitle) {
            this.dom.editorTitle.textContent = file.display_name;
            this.dom.editorTitle.title = file.display_name;
        }

        if (this.dom.editorStatusBadge) {
            const statusClass = file.status === 'ready'
                ? 'badge-ready'
                : file.status === 'failed'
                    ? 'badge-failed'
                    : 'badge-processing';
            this.dom.editorStatusBadge.className = `badge-studio ${statusClass}`;
            this.dom.editorStatusBadge.textContent = file.status.charAt(0).toUpperCase() + file.status.slice(1);
        }

        this.setText(this.dom.metaFormat, file.extension);
        this.setText(this.dom.metaDuration, file.formatted_duration);
        this.setText(this.dom.metaSize, file.human_size);
        this.setText(this.dom.metaSampleRate, file.sample_rate ? `${(file.sample_rate / 1000).toFixed(1)} kHz` : '—');
        this.setText(this.dom.metaChannels, this.channelLabel(file.channels));

        this.dom.processingNote?.classList.toggle('hidden', !PROCESSING_STATUSES.includes(file.status));
        this.dom.renameSelectedButton?.removeAttribute('disabled');

        this.setText(this.dom.selectedInfoFormat, file.extension);
        this.setText(this.dom.selectedInfoDuration, file.formatted_duration);
        this.setText(this.dom.selectedInfoSize, file.human_size);
        this.setText(this.dom.selectedInfoSampleRate, file.sample_rate ? `${(file.sample_rate / 1000).toFixed(1)} kHz` : '—');
        this.setText(this.dom.selectedInfoChannels, this.channelLabel(file.channels));
        this.setText(this.dom.selectedInfoCodec, file.codec ? file.codec.toUpperCase() : '—');

        this.syncEffectsUI();
    }

    syncEffectsUI() {
        const file = this.getSelected();
        const effects = {
            ...this.defaults,
            ...(file?.effects ?? {}),
        };
        const canEdit = Boolean(file);

        this.setSlider(this.dom.speedSlider, effects.speed, this.limits.speed);
        this.setSlider(this.dom.reverbSlider, effects.reverb, this.limits.reverb);
        this.setSlider(this.dom.pitchSlider, effects.pitch_multiplier, this.limits.pitch);
        this.setSlider(this.dom.bassSlider, effects.bass_boost, this.limits.bass_boost);

        this.setText(this.dom.speedValue, Number(effects.speed).toFixed(2));
        this.setText(this.dom.reverbValue, String(Math.round(effects.reverb)));
        this.setText(this.dom.pitchValue, effects.pitch_enabled ? Number(effects.pitch_multiplier).toFixed(2) : '0');
        this.setText(this.dom.pitchUnit, effects.pitch_enabled ? 'x' : '%');
        this.setText(this.dom.bassValue, String(Math.round(effects.bass_boost)));

        const effectiveDuration = file ? (Number(file.duration_seconds) || 0) / Math.max(0.25, Number(effects.speed) || 1) : 0;
        this.setText(this.dom.speedDuration, `≈ ${formatTime(effectiveDuration)}`);

        const follow = Boolean(effects.pitch_follows_speed);
        const speed = Math.max(0.25, Number(effects.speed) || 1);
        const independentPitch = effects.pitch_enabled ? Math.max(0.5, Number(effects.pitch_multiplier) || 1) : 1;
        const independentSemitones = 12 * Math.log2(independentPitch);
        const tapeActive = follow && Math.abs(speed - 1) > 0.001;

        this.setText(
            this.dom.pitchSemitones,
            effects.pitch_enabled
                ? (Math.abs(independentSemitones) > 0.05
                    ? `${independentSemitones > 0 ? '+' : ''}${independentSemitones.toFixed(1)} semitones extra`
                    : 'No extra pitch')
                : 'Pitch off (0%)',
        );
        this.setText(
            this.dom.speedModeHint,
            follow
                ? 'Slower = deeper, faster = thinner'
                : 'Pitch stays fixed while speed changes',
        );

        if (this.dom.pitchFollowToggle) {
            this.dom.pitchFollowToggle.setAttribute('aria-checked', String(follow));
            this.dom.pitchFollowToggle.disabled = !canEdit;
        }

        if (this.dom.pitchToggle) {
            this.dom.pitchToggle.setAttribute('aria-checked', String(Boolean(effects.pitch_enabled)));
            this.dom.pitchToggle.disabled = !canEdit;
            this.dom.pitchToggle.closest('.effect-card')?.classList.toggle('is-disabled', !effects.pitch_enabled && !tapeActive);
        }

        this.dom.pitchSlider.disabled = !canEdit || !effects.pitch_enabled;
        this.dom.speedSlider.disabled = !canEdit;
        this.dom.reverbSlider.disabled = !canEdit;
        this.dom.bassSlider.disabled = !canEdit;

        [this.dom.speedReset, this.dom.reverbReset, this.dom.pitchReset, this.dom.bassReset, this.dom.resetEffectsButton].forEach((button) => {
            if (button) {
                button.disabled = !canEdit;
            }
        });

        this.dom.presetGrid?.querySelectorAll('[data-preset]').forEach((button) => {
            button.disabled = !canEdit;
        });

        this.setPresetActive(effects);

        if (file) {
            const pitchText = effects.pitch_enabled
                ? (Math.abs(independentSemitones) > 0.05
                    ? `${Number(effects.pitch_multiplier).toFixed(2)}x extra pitch (${independentSemitones > 0 ? '+' : ''}${independentSemitones.toFixed(1)} st)`
                    : 'no extra pitch')
                : 'pitch off (0%)';
            const speedText = `${Number(effects.speed).toFixed(2)}x speed${tapeActive ? ' (tape)' : ''}`;
            this.setText(
                this.dom.exportSummary,
                `${this.presetLabel(effects)} · ${speedText} · ${pitchText} · ${Math.round(effects.reverb)}% reverb · ${Math.round(effects.bass_boost)}% bass · ≈ ${formatTime(effectiveDuration)} processed`,
            );
        } else {
            this.setText(this.dom.exportSummary, 'All enabled effects are applied server-side with FFmpeg. The original file is never overwritten.');
        }

        this.applyEffectsToEngine(effects);
        this.updateExportAvailability();
    }

    setSlider(input, value, range = {}) {
        if (!input) {
            return;
        }

        const minimum = Number(range?.min ?? 0);
        const maximum = Number(range?.max ?? 100);
        const numeric = clamp(Number(value) || 0, minimum, maximum);
        input.value = String(numeric);
        setRangeFill(input, numeric, minimum, maximum);
    }

    setText(element, text) {
        if (element) {
            element.textContent = text;
        }
    }

    channelLabel(channels) {
        if (Number(channels) === 1) {
            return 'Mono';
        }

        if (Number(channels) === 2) {
            return 'Stereo';
        }

        return channels ? `${channels} ch` : '—';
    }

    applyEffectsToEngine(effects) {
        const fx = {
            ...this.defaults,
            ...(effects ?? {}),
        };

        this.engine.setPitchFollowsSpeed(fx.pitch_follows_speed);
        this.engine.setSpeed(fx.speed);
        this.engine.setReverb(fx.reverb);
        this.engine.setBass(fx.bass_boost);
        this.engine.setPitch(fx.pitch_multiplier, fx.pitch_enabled);

        if (this.engine.ready && !this.engine.pitchSupported && fx.pitch_enabled && !this.pitchWarningShown) {
            this.pitchWarningShown = true;
            showToast('Pitch preview is unavailable in this browser, but pitch will still be applied to exports.', 'warning', 8000);
        }
    }

    applyCurrentEffects() {
        const file = this.getSelected();

        if (file) {
            this.applyEffectsToEngine(file.effects);
        }
    }

    setPresetActive(effects) {
        if (!this.dom.presetGrid) {
            return;
        }

        const active = this.isOriginal(effects) ? 'original' : this.detectPreset(effects);

        this.dom.presetGrid.querySelectorAll('[data-preset]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.preset === active);
        });
    }

    isOriginal(effects) {
        return Math.abs(Number(effects.speed) - 1) < 0.001
            && !effects.pitch_enabled
            && Math.abs(Number(effects.pitch_multiplier) - 1) < 0.001
            && Number(effects.reverb) === 0
            && Number(effects.bass_boost) === 0;
    }

    detectPreset(effects) {
        for (const [key, preset] of Object.entries(this.presets)) {
            const candidate = preset.settings ?? {};

            if (
                Math.abs(Number(effects.speed) - Number(candidate.speed)) < 0.001
                && Boolean(effects.pitch_follows_speed) === Boolean(candidate.pitch_follows_speed)
                && Boolean(effects.pitch_enabled) === Boolean(candidate.pitch_enabled)
                && Math.abs(Number(effects.pitch_multiplier) - Number(candidate.pitch_multiplier)) < 0.001
                && Number(effects.reverb) === Number(candidate.reverb)
                && Number(effects.bass_boost) === Number(candidate.bass_boost)
            ) {
                return key;
            }
        }

        return null;
    }

    presetLabel(effects) {
        if (this.isOriginal(effects)) {
            return 'Original';
        }

        const key = this.detectPreset(effects);

        if (key && this.presets[key]) {
            return this.presets[key].label ?? key;
        }

        return 'Custom';
    }

    updateEffect(key, value) {
        const file = this.getSelected();

        if (!file) {
            return;
        }

        const effects = {
            ...this.defaults,
            ...(file.effects ?? {}),
            [key]: value,
        };
        effects.preset_name = this.detectPreset(effects);

        file.effects = effects;
        this.files.set(file.id, file);

        this.applyEffectsToEngine(effects);
        this.syncEffectsUI();
        this.scheduleSave(file);
        this.notifyEffectChange(file);
    }

    resetEffect(key) {
        const file = this.getSelected();

        if (!file) {
            return;
        }

        const effects = {
            ...this.defaults,
            ...(file.effects ?? {}),
        };

        if (key === 'speed') {
            effects.speed = 1;
        } else if (key === 'reverb') {
            effects.reverb = 0;
        } else if (key === 'bass') {
            effects.bass_boost = 0;
        } else if (key === 'pitch') {
            effects.pitch_enabled = false;
            effects.pitch_multiplier = 1;
        }

        effects.preset_name = this.detectPreset(effects);
        file.effects = effects;
        this.files.set(file.id, file);

        this.applyEffectsToEngine(effects);
        this.syncEffectsUI();
        this.saveEffects(file, true);
        this.notifyEffectChange(file, true);
    }

    togglePitch() {
        const file = this.getSelected();

        if (!file) {
            return;
        }

        const effects = {
            ...this.defaults,
            ...(file.effects ?? {}),
        };
        effects.pitch_enabled = !effects.pitch_enabled;
        effects.preset_name = this.detectPreset(effects);

        file.effects = effects;
        this.files.set(file.id, file);

        if (effects.pitch_enabled && !this.engine.pitchSupported && this.engine.ready && !this.pitchWarningShown) {
            this.pitchWarningShown = true;
            showToast('Pitch preview is unavailable in this browser, but pitch will still be applied to exports.', 'warning', 8000);
        }

        this.applyEffectsToEngine(effects);
        this.syncEffectsUI();
        this.saveEffects(file, true);
        this.notifyEffectChange(file, true);
    }

    togglePitchFollow() {
        const file = this.getSelected();

        if (!file) {
            return;
        }

        const effects = {
            ...this.defaults,
            ...(file.effects ?? {}),
        };
        effects.pitch_follows_speed = !effects.pitch_follows_speed;
        effects.preset_name = this.detectPreset(effects);

        file.effects = effects;
        this.files.set(file.id, file);

        this.applyEffectsToEngine(effects);
        this.syncEffectsUI();
        this.saveEffects(file, true);
        this.notifyEffectChange(file, true);
    }

    applyPreset(key) {
        const file = this.getSelected();

        if (!file) {
            return;
        }

        let effects;

        if (key === 'original') {
            effects = { ...this.defaults, preset_name: null };
        } else {
            const preset = this.presets[key];

            if (!preset) {
                showToast('That preset is not available.', 'error');
                return;
            }

            effects = {
                ...this.defaults,
                ...preset.settings,
                preset_name: key,
            };
        }

        file.effects = effects;
        this.files.set(file.id, file);

        this.applyEffectsToEngine(effects);
        this.syncEffectsUI();
        this.saveEffects(file, true);
        this.notifyEffectChange(file, true);
    }

    settingsForRequest(file) {
        const effects = {
            ...this.defaults,
            ...(file?.effects ?? {}),
        };

        return {
            speed: round(Number(effects.speed) || 1, 2),
            pitch_follows_speed: Boolean(effects.pitch_follows_speed),
            pitch_enabled: Boolean(effects.pitch_enabled),
            pitch_multiplier: round(Number(effects.pitch_multiplier) || 1, 2),
            reverb: Math.round(Number(effects.reverb) || 0),
            bass_boost: Math.round(Number(effects.bass_boost) || 0),
            preset_name: this.detectPreset(effects),
        };
    }

    settingsMatch(left, right) {
        const keys = ['speed', 'pitch_follows_speed', 'pitch_enabled', 'pitch_multiplier', 'reverb', 'bass_boost'];

        return keys.every((key) => String(left?.[key]) === String(right?.[key]));
    }

    effectToastMessage(settings) {
        const speed = Number(settings.speed) || 1;
        const follow = Boolean(settings.pitch_follows_speed);
        const tapeActive = follow && Math.abs(speed - 1) > 0.001;
        const extraPitch = settings.pitch_enabled ? (Number(settings.pitch_multiplier) || 1) : 1;
        const extraSemitones = 12 * Math.log2(extraPitch);
        const pitchText = settings.pitch_enabled
            ? (Math.abs(extraSemitones) > 0.05
                ? `${extraPitch.toFixed(2)}x extra pitch (${extraSemitones > 0 ? '+' : ''}${extraSemitones.toFixed(1)} st)`
                : 'no extra pitch')
            : 'pitch off (0%)';

        const reverb = Math.round(Number(settings.reverb) || 0);
        const bass = Math.round(Number(settings.bass_boost) || 0);
        const speedText = `${speed.toFixed(2)}x speed${tapeActive ? ' (tape)' : ''}`;

        return `Export (${this.presetLabel(settings)}): ${speedText} · ${pitchText} · ${reverb}% reverb · ${bass}% bass`;
    }

    notifyEffectChange(file, immediate = false) {
        if (!file) {
            return;
        }

        const fileId = file.id;
        const show = () => {
            this.effectToastTimer = null;
            const current = this.files.get(fileId);

            if (current) {
                showToast(this.effectToastMessage(this.settingsForRequest(current)), 'info', 3000);
            }
        };

        if (this.effectToastTimer) {
            window.clearTimeout(this.effectToastTimer);
            this.effectToastTimer = null;
        }

        if (immediate) {
            show();
        } else {
            this.effectToastTimer = window.setTimeout(show, 320);
        }
    }

    scheduleSave(file) {
        const existing = this.saveTimers.get(file.id);

        if (existing) {
            window.clearTimeout(existing);
        }

        const timer = window.setTimeout(() => {
            this.saveTimers.delete(file.id);
            this.saveEffects(file, true);
        }, 420);

        this.saveTimers.set(file.id, timer);
    }

    async saveEffects(file, immediate = false) {
        const existing = this.saveTimers.get(file.id);

        if (existing) {
            window.clearTimeout(existing);
            this.saveTimers.delete(file.id);
        }

        const snapshot = this.settingsForRequest(file);
        const sequence = (this.saveSequences.get(file.id) ?? 0) + 1;
        this.saveSequences.set(file.id, sequence);

        const previous = this.savePromises.get(file.id) ?? Promise.resolve();
        const request = previous
            .catch(() => {})
            .then(() => fetchJson(file.effects_url, {
                method: 'PATCH',
                body: JSON.stringify(snapshot),
            }));
        this.savePromises.set(file.id, request);

        try {
            const payload = await request;

            if (this.saveSequences.get(file.id) !== sequence) {
                return;
            }

            const updated = payload.data.audio;
            const currentSettings = this.settingsForRequest(file);
            const localChanged = !this.settingsMatch(snapshot, currentSettings);

            // A save response can arrive after the user has already moved a
            // slider again. Keep the newer local values instead of replacing
            // them with the older server snapshot, otherwise an immediate
            // export would render the previous settings.
            const effectiveSettings = localChanged ? currentSettings : (updated.effects ?? currentSettings);
            this.files.set(file.id, localChanged ? { ...updated, effects: effectiveSettings } : updated);

            if (this.selectedId === file.id) {
                this.setPresetActive(effectiveSettings);
            }
        } catch (error) {
            if (immediate) {
                showToast(error.firstValidationMessage?.() ?? error.message ?? 'Effect settings could not be saved.', 'error');
            }
        } finally {
            if (this.savePromises.get(file.id) === request) {
                this.savePromises.delete(file.id);
            }
        }
    }

    async flushEffectsSave(file) {
        if (!file) {
            return;
        }

        const timer = this.saveTimers.get(file.id);

        if (timer) {
            window.clearTimeout(timer);
            this.saveTimers.delete(file.id);
        }

        const pending = this.savePromises.get(file.id);

        if (pending) {
            await pending.catch(() => {});
        }
    }

    handleWaveformReady() {
        if (this.pendingAutoplay) {
            this.pendingAutoplay = false;
            this.player.play();
        }
    }

    handleEnded() {
        if (this.player.repeat) {
            this.waveform.seekTo(0);
            this.player.play();
        } else {
            this.player.updatePlayState(false);
        }
    }

    selectAdjacent(direction) {
        if (this.order.length === 0) {
            return;
        }

        const currentIndex = this.order.indexOf(this.selectedId);
        const nextIndex = currentIndex < 0
            ? 0
            : (currentIndex + direction + this.order.length) % this.order.length;

        this.selectFile(this.order[nextIndex]);
    }

    updatePlayerNavigation() {
        const multiple = this.order.length > 1;

        if (this.dom.previousButton) {
            this.dom.previousButton.disabled = !multiple;
        }

        if (this.dom.nextButton) {
            this.dom.nextButton.disabled = !multiple;
        }

        if (this.dom.playPauseButton) {
            const file = this.getSelected();
            this.dom.playPauseButton.disabled = !file?.stream_url;
        }
    }

    updateExportAvailability() {
        const file = this.getSelected();
        const ready = Boolean(file && file.status === 'ready' && this.environment.ok);

        document.querySelectorAll('[data-export-format]').forEach((button) => {
            button.disabled = !ready;
            button.title = ready ? '' : (this.environment.ok ? 'Select a ready audio file to export.' : 'The audio engine is offline.');
        });

        if (this.dom.downloadMenuButton) {
            this.dom.downloadMenuButton.disabled = !ready;
        }
    }

    updateEnvironment(environment) {
        this.environment = environment ?? { ok: true, errors: [] };
        this.exporter.environment = this.environment;

        if (this.dom.engineStatus) {
            this.dom.engineStatus.classList.toggle('is-ok', Boolean(this.environment.ok));
            this.dom.engineStatus.classList.toggle('is-warning', !this.environment.ok);
            this.dom.engineStatus.title = this.environment.ok
                ? 'FFmpeg and FFprobe are ready.'
                : (this.environment.errors ?? []).join(' ') || 'Audio engine unavailable.';
        }

        if (this.dom.engineStatusText) {
            this.dom.engineStatusText.textContent = this.environment.ok ? 'Audio engine ready' : 'Audio engine offline';
        }

        document.querySelector('.environment-banner')?.classList.toggle('hidden', Boolean(this.environment.ok));
        this.updateExportAvailability();
    }

    handleUploaded(files, errors, message) {
        const normalized = (files ?? []).map((file) => this.normalizeFile(file));

        if (normalized.length === 0) {
            (errors ?? []).forEach((error) => {
                showToast(error?.message ?? String(error), 'error', 7000);
            });

            if (message) {
                showToast(message, 'error');
            }
            return;
        }

        normalized.forEach((file) => {
            this.files.set(file.id, file);
            this.order = [file.id, ...this.order.filter((id) => id !== file.id)];
        });

        this.renderFileList();
        showToast(message || 'Audio uploaded.', 'success');

        (errors ?? []).forEach((error) => {
            showToast(error?.message ?? String(error), 'error', 7000);
        });

        const first = normalized[0];
        this.selectFile(first.id);
        normalized.forEach((file) => {
            if (PROCESSING_STATUSES.includes(file.status)) {
                this.pollProcessing(file);
            }

            if (file.status === 'failed') {
                showToast(file.error_message ?? `${file.display_name} could not be prepared.`, 'error', 8000);
            }
        });
    }

    startProcessingPolls() {
        this.files.forEach((file) => {
            if (PROCESSING_STATUSES.includes(file.status)) {
                this.pollProcessing(file);
            }
        });
    }

    pollProcessing(file) {
        if (this.pollTimers.has(file.id)) {
            return;
        }

        const startedAt = Date.now();

        const tick = async () => {
            if (this.pollInFlight.has(file.id)) {
                return;
            }

            const current = this.files.get(file.id);

            if (!current) {
                this.stopPoll(file.id);
                return;
            }

            this.pollInFlight.add(file.id);

            try {
                const payload = await fetchJson(current.metadata_url);
                const updated = this.normalizeFile(payload.data);
                this.files.set(file.id, updated);

                if (payload.environment) {
                    this.updateEnvironment(payload.environment);
                }

                this.renderFileList();

                if (this.selectedId === file.id) {
                    this.updateEditor();
                    this.player.setTrack(updated);

                    if (updated.status === 'ready' || updated.status === 'failed') {
                        this.waveform.load(updated);
                    }
                }

                if (updated.status === 'ready' || updated.status === 'failed') {
                    this.stopPoll(file.id);

                    if (updated.status === 'failed') {
                        showToast(updated.error_message ?? 'Audio preparation failed.', 'error', 8000);
                    }
                }
            } catch (error) {
                console.error('Metadata poll failed', error);
            } finally {
                this.pollInFlight.delete(file.id);
            }

            if (Date.now() - startedAt > 10 * 60 * 1000) {
                this.stopPoll(file.id);
            }
        };

        const timer = window.setInterval(tick, 3000);
        this.pollTimers.set(file.id, timer);
        tick();
    }

    stopPoll(fileId) {
        const timer = this.pollTimers.get(fileId);

        if (timer) {
            window.clearInterval(timer);
            this.pollTimers.delete(fileId);
        }

        this.pollInFlight.delete(fileId);
    }

    openRename(file) {
        this.renameFileId = file.id;
        this.dom.renameInput.value = file.display_name;
        this.dom.renameError.textContent = '';
        this.dom.renameError.classList.remove('is-visible');

        const modal = window.bootstrap?.Modal.getOrCreateInstance(this.dom.renameModal);
        modal?.show();
        window.setTimeout(() => {
            this.dom.renameInput.focus();
            this.dom.renameInput.select();
        }, 200);
    }

    async submitRename() {
        const file = this.renameFileId ? this.files.get(this.renameFileId) : null;

        if (!file) {
            return;
        }

        const value = this.dom.renameInput.value.trim();

        if (value === '') {
            this.dom.renameError.textContent = 'Enter a name for this audio file.';
            this.dom.renameError.classList.add('is-visible');
            return;
        }

        this.dom.renameSaveButton.disabled = true;

        try {
            const payload = await fetchJson(file.rename_url, {
                method: 'PATCH',
                body: JSON.stringify({ display_name: value }),
            });

            const updated = this.normalizeFile(payload.data);
            this.files.set(updated.id, updated);
            this.renderFileList();

            if (this.selectedId === updated.id) {
                this.updateEditor();
                this.player.setTrack(updated);
            }

            window.bootstrap?.Modal.getOrCreateInstance(this.dom.renameModal).hide();
            showToast(payload.message ?? 'Audio file renamed.', 'success');
        } catch (error) {
            this.dom.renameError.textContent = error.firstValidationMessage?.() ?? error.message ?? 'The file could not be renamed.';
            this.dom.renameError.classList.add('is-visible');
        } finally {
            this.dom.renameSaveButton.disabled = false;
        }
    }

    async deleteFile(file) {
        const confirmed = await confirmAction({
            title: 'Delete audio file?',
            message: `Delete “${file.display_name}”? The original upload and any processed exports will be removed.`,
            confirmLabel: 'Delete',
            danger: true,
        });

        if (!confirmed) {
            return;
        }

        const wasSelected = this.selectedId === file.id;
        const index = this.order.indexOf(file.id);

        try {
            await fetchJson(file.delete_url, { method: 'DELETE' });

            this.stopPoll(file.id);
            this.files.delete(file.id);
            this.order = this.order.filter((id) => id !== file.id);
            this.saveSequences.delete(file.id);

            const pendingSave = this.saveTimers.get(file.id);
            if (pendingSave) {
                window.clearTimeout(pendingSave);
                this.saveTimers.delete(file.id);
            }

            if (wasSelected) {
                this.waveform.clear();
                this.player.setTrack(null);
                const nextId = this.order[Math.min(index, this.order.length - 1)] ?? null;
                this.selectedId = null;
                this.selectFile(nextId);
            }

            this.renderFileList();
            this.updateEditor();
            showToast('Audio file deleted.', 'success');
        } catch (error) {
            showToast(error.firstValidationMessage?.() ?? error.message ?? 'The file could not be deleted.', 'error');
        }
    }

    async clearWorkspace() {
        if (this.files.size === 0) {
            return;
        }

        const confirmed = await confirmAction({
            title: 'Clear workspace?',
            message: 'Remove every audio file from your library? This deletes the originals and any processed exports.',
            confirmLabel: 'Clear workspace',
            danger: true,
        });

        if (!confirmed) {
            return;
        }

        try {
            const payload = await fetchJson(this.data.routes?.clear ?? '/audio', { method: 'DELETE' });

            this.pollTimers.forEach((timer) => window.clearInterval(timer));
            this.pollTimers.clear();
            this.saveTimers.forEach((timer) => window.clearTimeout(timer));
            this.saveTimers.clear();
            this.files.clear();
            this.order = [];
            this.selectedId = null;
            this.waveform.clear();
            this.player.setTrack(null);
            this.renderFileList();
            this.updateEditor();
            showToast(payload.message ?? 'Workspace cleared.', 'success');
        } catch (error) {
            showToast(error.message ?? 'The workspace could not be cleared.', 'error');
        }
    }

    setMobileView(view) {
        if (!this.dom.shell) {
            return;
        }

        this.dom.shell.dataset.mobileView = view;

        document.querySelectorAll('[data-mobile-view]').forEach((button) => {
            const active = button.dataset.mobileView === view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
        });
    }

    handleKeyboard(event) {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (event.target.closest('input, textarea, select, [contenteditable="true"]')) {
            return;
        }

        if (document.querySelector('.modal.show')) {
            return;
        }

        const key = event.key.toLowerCase();

        if (event.code === 'Space') {
            event.preventDefault();
            this.player.togglePlay();
            return;
        }

        if (key === 'arrowleft') {
            event.preventDefault();
            this.player.seekBy(-5);
            return;
        }

        if (key === 'arrowright') {
            event.preventDefault();
            this.player.seekBy(5);
            return;
        }

        if (key === 'arrowup') {
            event.preventDefault();
            this.player.setVolume(this.audio.volume + 0.05);
            return;
        }

        if (key === 'arrowdown') {
            event.preventDefault();
            this.player.setVolume(this.audio.volume - 0.05);
            return;
        }

        if (key === 'r') {
            this.player.toggleRepeat();
            return;
        }

        if (key === 'n') {
            this.selectAdjacent(1);
            return;
        }

        if (key === 'p') {
            this.selectAdjacent(-1);
        }
    }

    flashMessages() {
        const flash = readJsonScript('flashData');

        if (!flash) {
            return;
        }

        if (flash.status) {
            showToast(flash.status, 'success');
        }

        if (flash.error) {
            showToast(flash.error, 'error');
        }
    }
}
