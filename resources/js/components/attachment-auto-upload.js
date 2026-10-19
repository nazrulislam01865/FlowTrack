const MAX_BYTES = 20 * 1024 * 1024;
const state = new WeakMap();

const zoneFor = (target) => target instanceof Element ? target.closest('[data-file-dropzone][data-auto-upload-method]') : null;
const inputFor = (zone) => zone?.querySelector('input[type="file"]') || null;

const allowedExtensions = (input) => String(input?.getAttribute('accept') || '')
    .split(',')
    .map((item) => item.trim().toLowerCase())
    .filter((item) => item.startsWith('.'));

const extensionOf = (name) => {
    const dot = String(name || '').lastIndexOf('.');
    return dot >= 0 ? String(name).slice(dot).toLowerCase() : '';
};

const humanAllowed = (input) => {
    const labels = allowedExtensions(input)
        .map((ext) => ext.slice(1).toUpperCase())
        .filter((value, index, all) => value !== 'JPEG' && all.indexOf(value) === index);
    return labels.join(', ');
};

const errorHost = (zone) => zone?.closest('.ft-upload-zone') || zone;

const errorBox = (zone) => {
    const host = errorHost(zone);
    let box = host?.nextElementSibling?.matches?.('[data-upload-client-error]') ? host.nextElementSibling : null;
    if (box) return box;

    box = document.createElement('div');
    box.dataset.uploadClientError = '1';
    box.className = 'ft-upload-client-error';
    box.hidden = true;
    host?.insertAdjacentElement('afterend', box);
    return box;
};

const clearError = (zone) => {
    if (!zone) return;
    zone.classList.remove('has-upload-error');
    const host = errorHost(zone);
    const box = host?.nextElementSibling?.matches?.('[data-upload-client-error]') ? host.nextElementSibling : null;
    if (box) {
        box.hidden = true;
        box.textContent = '';
    }
};

const showError = (zone, message) => {
    if (!zone) return;
    zone.classList.remove('is-uploading', 'is-saving', 'is-upload-complete');
    zone.classList.add('has-upload-error');
    const box = errorBox(zone);
    box.textContent = message;
    box.hidden = false;
    setProgress(zone, 0, '', false);
};

const progressUi = (zone) => {
    let ui = zone.querySelector('[data-auto-upload-progress]');
    if (ui) return ui;
    ui = document.createElement('div');
    ui.dataset.autoUploadProgress = '1';
    ui.className = 'ft-inline-upload-progress';
    ui.hidden = true;
    ui.innerHTML = '<div class="ft-inline-upload-progress-head"><span data-auto-upload-progress-label></span><b data-auto-upload-progress-percent></b></div><div class="ft-inline-upload-track"><i data-auto-upload-progress-bar></i></div>';
    zone.appendChild(ui);
    return ui;
};

function setProgress(zone, percent, label, visible = true) {
    if (!zone) return;
    const ui = progressUi(zone);
    const value = Math.max(0, Math.min(100, Number(percent) || 0));
    ui.hidden = !visible;
    const bar = ui.querySelector('[data-auto-upload-progress-bar]');
    const pct = ui.querySelector('[data-auto-upload-progress-percent]');
    const text = ui.querySelector('[data-auto-upload-progress-label]');
    if (bar) bar.style.width = `${value}%`;
    if (pct) pct.textContent = visible ? `${Math.round(value)}%` : '';
    if (text) text.textContent = label || '';
}

const validateFileList = (filesLike, input) => {
    const files = Array.from(filesLike || []);
    const allowed = allowedExtensions(input);
    for (const file of files) {
        if (file.size > MAX_BYTES) {
            return `${file.name} is too large. Maximum file size is 20 MB.`;
        }
        const extension = extensionOf(file.name);
        if (allowed.length && !allowed.includes(extension)) {
            return `${file.name} is not a supported file. Use ${humanAllowed(input)}.`;
        }
    }
    return null;
};

const validateFiles = (input) => validateFileList(input?.files, input);

const componentFor = (zone) => {
    const root = zone?.closest('[wire\\:id]');
    const id = root?.getAttribute('wire:id');
    if (!id || !window.Livewire?.find) return null;

    // Livewire 4's Livewire.find(id) already returns the component's $wire
    // object. Do not access .$wire again: on the magic proxy that becomes a
    // server action named "$wire" and results in MethodNotFoundException.
    return window.Livewire.find(id) || null;
};

const callComponentMethod = (wire, method) => {
    if (!wire || !method) return Promise.reject(new Error('Livewire component is unavailable.'));

    // $call is the explicit Livewire 4 API for invoking a PHP action by name.
    // Avoid probing generic properties such as .call because $wire is a magic
    // proxy and unknown property access can itself become a server action.
    if (typeof wire.$call === 'function') return wire.$call(method);
    if (typeof wire[method] === 'function') return wire[method]();

    return Promise.reject(new Error(`Livewire method ${method} is unavailable.`));
};

const resetNativeInput = (zone) => {
    const input = inputFor(zone);
    if (input) input.value = '';
};

const bootState = { bound: false };

export const bootAttachmentAutoUpload = () => {
    if (bootState.bound) return;
    bootState.bound = true;
    // Drag/drop is handled by FlowTrack's shared dropzone script. Validate first
    // in the capture phase so that script cannot silently replace our error state.
    document.addEventListener('drop', (event) => {
        const zone = zoneFor(event.target);
        if (!zone) return;
        const input = inputFor(zone);
        const files = event.dataTransfer?.files;
        if (!input || !files?.length) return;

        clearError(zone);
        const message = validateFileList(files, input);
        if (!message) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        input.value = '';
        showError(zone, message);
    }, true);

    // Validate before Livewire sees the change. This is intentionally a capture
    // listener so unsupported/oversized files never enter temporary upload state.
    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
        const zone = zoneFor(input);
        if (!zone) return;

        clearError(zone);
        const message = validateFiles(input);
        if (!message) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        input.value = '';
        showError(zone, message);
    }, true);

    document.addEventListener('livewire-upload-start', (event) => {
        const zone = zoneFor(event.target);
        if (!zone) return;
        clearError(zone);
        zone.classList.add('is-uploading');
        zone.classList.remove('is-saving', 'is-upload-complete');
        state.set(zone, { saving: false });
        setProgress(zone, 1, 'Uploading…');
    });

    document.addEventListener('livewire-upload-progress', (event) => {
        const zone = zoneFor(event.target);
        if (!zone) return;
        // 100% here only means the temporary transfer is done. Reserve 100% for
        // the actual persisted/linked success state.
        const raw = Math.max(0, Math.min(100, Number(event.detail?.progress) || 0));
        const visible = raw >= 100 ? 99 : raw;
        setProgress(zone, visible, 'Uploading…');
    });

    document.addEventListener('livewire-upload-error', (event) => {
        const zone = zoneFor(event.target);
        if (!zone) return;
        resetNativeInput(zone);
        showError(zone, `The file could not be uploaded. Use ${humanAllowed(inputFor(zone))} and keep each file under 20 MB.`);
    });

    document.addEventListener('livewire-upload-cancel', (event) => {
        const zone = zoneFor(event.target);
        if (!zone) return;
        resetNativeInput(zone);
        showError(zone, 'The upload was cancelled. Choose the file again when you are ready.');
    });

    document.addEventListener('livewire-upload-finish', async (event) => {
        const originalZone = zoneFor(event.target);
        if (!originalZone) return;
        const current = state.get(originalZone) || {};
        if (current.saving) return;
        current.saving = true;
        state.set(originalZone, current);

        originalZone.classList.remove('is-uploading');
        originalZone.classList.add('is-saving');
        setProgress(originalZone, 100, 'Saving & linking…');

        const method = originalZone.dataset.autoUploadMethod;
        const inputId = inputFor(originalZone)?.id || '';
        const component = componentFor(originalZone);
        if (!method || !component) {
            resetNativeInput(originalZone);
            showError(originalZone, 'FlowTrack could not finish this upload. Refresh the page and try again.');
            return;
        }

        try {
            const result = await callComponentMethod(component, method);
            if (result && typeof result === 'object' && result.ok === false) {
                throw new Error(result.message || 'The document could not be saved.');
            }

            // Livewire may morph the uploader after the server call, so resolve it
            // again before updating/clearing the final browser-side state.
            const escapedId = window.CSS?.escape ? CSS.escape(inputId) : inputId.replace(/([:.\\[\\],=@])/g, '\\$1');
            const freshInput = inputId ? document.getElementById(inputId) : null;
            const freshZone = freshInput ? zoneFor(freshInput) : originalZone;
            if (!freshZone) return;

            clearError(freshZone);
            resetNativeInput(freshZone);
            freshZone.classList.remove('is-uploading', 'is-saving');
            freshZone.classList.add('is-upload-complete');
            setProgress(freshZone, 100, 'Upload complete · Linked automatically');

            window.setTimeout(() => {
                const liveInput = inputId ? document.getElementById(inputId) : null;
                const liveZone = liveInput ? zoneFor(liveInput) : freshZone;
                if (!liveZone) return;
                liveZone.classList.remove('is-upload-complete');
                setProgress(liveZone, 0, '', false);
                const status = liveZone.querySelector('[data-drop-status]');
                if (status?.dataset.defaultText) status.textContent = status.dataset.defaultText;
            }, 1100);
        } catch (error) {
            const freshInput = inputId ? document.getElementById(inputId) : null;
            const freshZone = freshInput ? zoneFor(freshInput) : originalZone;
            if (!freshZone) return;
            resetNativeInput(freshZone);
            showError(freshZone, 'FlowTrack could not save and link this file. Check the file type/size and try again.');
        }
    });
};
