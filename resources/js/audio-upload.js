import { csrfToken } from './utils.js';

const SUPPORTED_EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

export class UploadManager {
    constructor(options) {
        this.input = options.input;
        this.uploadUrl = options.uploadUrl;
        this.maxUploadMb = Number(options.maxUploadMb) || 100;
        this.maxFiles = Number(options.maxFiles) || 10;
        this.callbacks = options.callbacks ?? {};
        this.progressCard = document.getElementById('uploadProgressCard');
        this.progressBar = document.getElementById('uploadProgressBar');
        this.progressPercent = document.getElementById('uploadProgressPercent');
        this.progressName = document.getElementById('uploadProgressName');
        this.dropOverlay = document.getElementById('dropOverlay');
        this.uploading = false;
        this.dragDepth = 0;

        this.bind();
    }

    bind() {
        document.querySelectorAll('[data-add-files], #addFilesButton').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                this.input?.click();
            });
        });

        this.input?.addEventListener('change', () => {
            const files = Array.from(this.input.files ?? []);
            this.input.value = '';

            if (files.length > 0) {
                this.handleFiles(files);
            }
        });

        window.addEventListener('dragenter', (event) => {
            if (!this.hasFiles(event)) {
                return;
            }

            event.preventDefault();
            this.dragDepth += 1;
            this.dropOverlay?.classList.add('is-visible');
            this.dropOverlay?.setAttribute('aria-hidden', 'false');
        });

        window.addEventListener('dragover', (event) => {
            if (!this.hasFiles(event)) {
                return;
            }

            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
        });

        window.addEventListener('dragleave', (event) => {
            if (!this.hasFiles(event)) {
                return;
            }

            this.dragDepth = Math.max(0, this.dragDepth - 1);

            if (this.dragDepth === 0) {
                this.hideDropOverlay();
            }
        });

        window.addEventListener('drop', (event) => {
            if (!this.hasFiles(event)) {
                return;
            }

            event.preventDefault();
            this.dragDepth = 0;
            this.hideDropOverlay();

            const files = Array.from(event.dataTransfer?.files ?? []);

            if (files.length > 0) {
                this.handleFiles(files);
            }
        });
    }

    hasFiles(event) {
        return Array.from(event.dataTransfer?.types ?? []).includes('Files');
    }

    hideDropOverlay() {
        this.dropOverlay?.classList.remove('is-visible');
        this.dropOverlay?.setAttribute('aria-hidden', 'true');
    }

    async handleFiles(files) {
        if (this.uploading) {
            this.callbacks.onError?.('An upload is already in progress. Please wait for it to finish.');
            return;
        }

        if (files.length > this.maxFiles) {
            this.callbacks.onError?.(`You can upload at most ${this.maxFiles} files at once.`);
            return;
        }

        const valid = [];
        const errors = [];

        for (const file of files) {
            const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

            if (!SUPPORTED_EXTENSIONS.includes(extension)) {
                errors.push(`“${file.name}” is not a supported audio format.`);
                continue;
            }

            if (file.size > this.maxUploadMb * 1024 * 1024) {
                errors.push(`“${file.name}” is larger than ${this.maxUploadMb} MB.`);
                continue;
            }

            if (file.size === 0) {
                errors.push(`“${file.name}” is empty.`);
                continue;
            }

            valid.push(file);
        }

        if (errors.length > 0) {
            errors.forEach((message) => this.callbacks.onError?.(message));
        }

        if (valid.length === 0) {
            return;
        }

        await this.upload(valid);
    }

    upload(files) {
        this.uploading = true;
        this.showProgress(0, files[0]?.name ?? '');

        return new Promise((resolve) => {
            const formData = new FormData();
            files.forEach((file) => formData.append('files[]', file, file.name));

            const request = new XMLHttpRequest();
            request.open('POST', this.uploadUrl, true);
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.setRequestHeader('X-CSRF-TOKEN', csrfToken());

            request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    this.showProgress(Math.round((event.loaded / event.total) * 100), files[0]?.name ?? '');
                }
            });

            request.addEventListener('load', () => {
                this.uploading = false;
                this.hideProgressSoon();

                let payload = null;

                try {
                    payload = JSON.parse(request.responseText);
                } catch (error) {
                    payload = null;
                }

                if (request.status >= 200 && request.status < 300) {
                    this.callbacks.onUploaded?.(
                        payload?.data?.files ?? [],
                        payload?.errors ?? [],
                        payload?.message ?? 'Audio uploaded.',
                    );
                    resolve();
                    return;
                }

                const messages = this.extractErrors(payload);

                if (messages.length > 0) {
                    messages.forEach((message) => this.callbacks.onError?.(message));
                } else {
                    this.callbacks.onError?.(
                        request.status === 419
                            ? 'Your session expired. Refresh the page and try again.'
                            : 'The upload could not be completed.',
                    );
                }

                resolve();
            });

            request.addEventListener('error', () => {
                this.uploading = false;
                this.hideProgressSoon();
                this.callbacks.onError?.('The upload failed because the connection was interrupted.');
                resolve();
            });

            request.addEventListener('abort', () => {
                this.uploading = false;
                this.hideProgressSoon();
                this.callbacks.onError?.('The upload was cancelled.');
                resolve();
            });

            request.send(formData);
        });
    }

    extractErrors(payload) {
        if (!payload) {
            return [];
        }

        if (Array.isArray(payload.errors)) {
            return payload.errors
                .map((error) => (typeof error === 'string' ? error : error?.message))
                .filter(Boolean);
        }

        if (payload.errors && typeof payload.errors === 'object') {
            return Object.values(payload.errors)
                .flat()
                .filter((message) => typeof message === 'string');
        }

        return payload.message ? [payload.message] : [];
    }

    showProgress(percent, fileName) {
        this.progressCard?.classList.add('is-visible');

        if (this.progressBar) {
            this.progressBar.style.width = `${percent}%`;
            this.progressBar.setAttribute('aria-valuenow', String(percent));
        }

        if (this.progressPercent) {
            this.progressPercent.textContent = `${percent}%`;
        }

        if (this.progressName) {
            this.progressName.textContent = fileName;
        }
    }

    hideProgressSoon() {
        window.setTimeout(() => {
            this.progressCard?.classList.remove('is-visible');

            if (this.progressBar) {
                this.progressBar.style.width = '0%';
                this.progressBar.setAttribute('aria-valuenow', '0');
            }

            if (this.progressPercent) {
                this.progressPercent.textContent = '0%';
            }
        }, 650);
    }
}
