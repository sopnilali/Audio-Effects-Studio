const TOAST_ICONS = {
    success: 'fa-circle-check',
    error: 'fa-circle-exclamation',
    warning: 'fa-triangle-exclamation',
    info: 'fa-circle-info',
};

export function showToast(message, type = 'info', timeout = 5000) {
    const stack = document.getElementById('toastStack');

    if (!stack) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = `studio-toast ${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const icon = document.createElement('i');
    icon.className = `fa-solid ${TOAST_ICONS[type] ?? TOAST_ICONS.info} toast-icon`;
    icon.setAttribute('aria-hidden', 'true');

    const copy = document.createElement('div');
    copy.className = 'flex-grow-1';
    copy.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

    const remove = () => {
        if (toast.classList.contains('is-leaving')) {
            return;
        }

        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 180);
    };

    close.addEventListener('click', remove);

    toast.append(icon, copy, close);
    stack.appendChild(toast);

    if (timeout > 0) {
        window.setTimeout(remove, timeout);
    }
}

export function confirmAction({
    title = 'Confirm action',
    message = 'Are you sure?',
    confirmLabel = 'Confirm',
    danger = false,
} = {}) {
    const modalElement = document.getElementById('confirmModal');

    if (!modalElement || !window.bootstrap) {
        return Promise.resolve(window.confirm(message));
    }

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
    const titleElement = document.getElementById('confirmModalLabel');
    const messageElement = document.getElementById('confirmMessage');
    const actionButton = document.getElementById('confirmActionButton');
    const cancelButton = document.getElementById('confirmCancelButton');

    titleElement.textContent = title;
    messageElement.textContent = message;
    actionButton.textContent = confirmLabel;
    actionButton.className = danger ? 'btn btn-danger' : 'btn btn-studio-primary';

    return new Promise((resolve) => {
        let confirmed = false;

        const cleanup = () => {
            actionButton.removeEventListener('click', onConfirm);
            modalElement.removeEventListener('hidden.bs.modal', onHidden);
        };

        const onConfirm = () => {
            confirmed = true;
            cleanup();
            modal.hide();
            resolve(true);
        };

        const onHidden = () => {
            cleanup();
            if (!confirmed) {
                resolve(false);
            }
        };

        actionButton.addEventListener('click', onConfirm);
        modalElement.addEventListener('hidden.bs.modal', onHidden, { once: true });
        cancelButton.addEventListener('click', () => modal.hide(), { once: true });

        modal.show();
    });
}

export function setButtonBusy(button, busy, busyLabel = 'Working…') {
    if (!button) {
        return;
    }

    if (busy) {
        if (!button.dataset.originalHtml) {
            button.dataset.originalHtml = button.innerHTML;
        }

        button.disabled = true;
        button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${busyLabel}`;
    } else {
        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
            delete button.dataset.originalHtml;
        }

        button.disabled = false;
    }
}

export function setRangeFill(input, value, minimum, maximum) {
    if (!input) {
        return;
    }

    const span = Math.max(0.0001, maximum - minimum);
    const ratio = Math.max(0, Math.min(1, (Number(value) - minimum) / span));
    input.style.setProperty('--range-progress', `${(ratio * 100).toFixed(2)}%`);
}
