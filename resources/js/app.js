import * as bootstrap from 'bootstrap';

import { StudioApp } from './studio-app.js';

// Several controllers (modals, confirmation dialogs, tooltips) access the
// Bootstrap API through `window.bootstrap`. The UMD bundle does not attach
// itself to `window` when Vite imports it as a module, so expose it here.
window.bootstrap = bootstrap;

const boot = () => {
    if (!document.getElementById('studioShell')) {
        return;
    }

    try {
        window.audioStudio = new StudioApp();
    } catch (error) {
        console.error('Unable to start the audio studio', error);
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
