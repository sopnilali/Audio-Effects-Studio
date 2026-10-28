export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export function readJsonScript(id) {
    const element = document.getElementById(id);

    if (!element) {
        return null;
    }

    try {
        return JSON.parse(element.textContent);
    } catch (error) {
        console.error(`Unable to parse #${id}`, error);
        return null;
    }
}

export function formatTime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '0:00';
    }

    const total = Math.round(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const remainder = total % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
    }

    return `${minutes}:${String(remainder).padStart(2, '0')}`;
}

export function formatBytes(bytes) {
    const value = Number(bytes) || 0;

    if (value >= 1024 * 1024) {
        return `${(value / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(value / 1024))} KB`;
}

export function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
}

export function round(value, decimals = 2) {
    const factor = 10 ** decimals;
    return Math.round((Number(value) + Number.EPSILON) * factor) / factor;
}

export function debounce(callback, wait = 350) {
    let timer = null;

    return (...args) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => callback(...args), wait);
    };
}

export function percent(value, minimum, maximum) {
    if (maximum <= minimum) {
        return 0;
    }

    return clamp(((Number(value) - minimum) / (maximum - minimum)) * 100, 0, 100);
}

export class ApiError extends Error {
    constructor(message, status = 0, errors = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }

    firstValidationMessage() {
        const values = Object.values(this.errors ?? {});

        for (const value of values) {
            if (Array.isArray(value) && value.length > 0) {
                return String(value[0]);
            }

            if (typeof value === 'string') {
                return value;
            }
        }

        return this.message;
    }
}

export async function fetchJson(url, options = {}) {
    const headers = new Headers(options.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    if (options.body && !(options.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }

    if (options.method && !['GET', 'HEAD'].includes(options.method.toUpperCase())) {
        headers.set('X-CSRF-TOKEN', csrfToken());
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });

    let payload = null;

    try {
        payload = await response.json();
    } catch (error) {
        payload = null;
    }

    if (!response.ok) {
        throw new ApiError(
            payload?.message ?? `Request failed with status ${response.status}.`,
            response.status,
            payload?.errors ?? {},
        );
    }

    return payload;
}

export function setRangeFill(input, value, minimum, maximum) {
    if (!input) {
        return;
    }

    input.style.setProperty('--range-progress', `${percent(value, minimum, maximum)}%`);
}
