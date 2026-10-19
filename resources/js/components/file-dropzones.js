const dropzoneState = { bound: false };

const fileDropzoneFrom = (target) => target instanceof Element ? target.closest('[data-file-dropzone]') : null;
const fileInputForDropzone = (zone) => zone?.querySelector('input[type="file"]') || null;

const updateDropzoneStatus = (zone, message = null) => {
    if (!zone) return;
    const status = zone.querySelector('[data-drop-status]');
    if (!status) return;
    if (!status.dataset.defaultText) status.dataset.defaultText = status.textContent.trim();
    status.textContent = message || status.dataset.defaultText;
};

const selectedFileSummary = (files) => {
    const list = Array.from(files || []);
    if (list.length === 0) return null;
    if (list.length === 1) return `${list[0].name} selected`;
    return `${list.length} files selected`;
};

const mergeDroppedFiles = (input, droppedFiles, replaceExisting = false) => {
    const transfer = new DataTransfer();
    const seen = new Set();
    const candidates = input.multiple
        ? (replaceExisting
            ? Array.from(droppedFiles || [])
            : [...Array.from(input.files || []), ...Array.from(droppedFiles || [])])
        : Array.from(droppedFiles || []).slice(0, 1);

    candidates.forEach((file) => {
        const key = `${file.name}:${file.size}:${file.lastModified}`;
        if (seen.has(key)) return;
        seen.add(key);
        transfer.items.add(file);
    });

    input.files = transfer.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
};

const clearDropzoneDragState = (zone) => {
    zone?.classList.remove('is-dragging');
};

export const bootFileDropzones = () => {
    if (dropzoneState.bound) return;
    dropzoneState.bound = true;

    document.addEventListener('dragenter', (event) => {
        const zone = fileDropzoneFrom(event.target);
        if (!zone) return;
        event.preventDefault();
        zone.classList.add('is-dragging');
    });

    document.addEventListener('dragover', (event) => {
        const zone = fileDropzoneFrom(event.target);
        if (!zone) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        zone.classList.add('is-dragging');
    });

    document.addEventListener('dragleave', (event) => {
        const zone = fileDropzoneFrom(event.target);
        if (!zone || (event.relatedTarget instanceof Node && zone.contains(event.relatedTarget))) return;
        clearDropzoneDragState(zone);
    });

    document.addEventListener('drop', (event) => {
        const zone = fileDropzoneFrom(event.target);
        if (!zone) return;
        event.preventDefault();
        event.stopPropagation();
        clearDropzoneDragState(zone);

        const input = fileInputForDropzone(zone);
        const files = event.dataTransfer?.files;
        if (!input || !files?.length || input.disabled) return;

        // A failed temporary upload must never be carried into the next attempt.
        // When the zone is in an error state, the next dropped selection replaces it.
        mergeDroppedFiles(input, files, zone.classList.contains('has-upload-error'));
        zone.classList.remove('has-upload-error');
        updateDropzoneStatus(zone, selectedFileSummary(input.files));
    });

    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
        const zone = input.closest('[data-file-dropzone]');
        if (!zone) return;
        // Choosing a new file is a fresh attempt, so clear the previous visual error.
        zone.classList.remove('has-upload-error');
        updateDropzoneStatus(zone, selectedFileSummary(input.files));
    });

    document.addEventListener('livewire-upload-start', (event) => {
        const zone = event.target?.closest?.('[data-file-dropzone]');
        if (!zone) return;
        zone.classList.add('is-uploading');
        updateDropzoneStatus(zone, 'Preparing files…');
    });

    document.addEventListener('livewire-upload-progress', (event) => {
        const zone = event.target?.closest?.('[data-file-dropzone]');
        if (!zone) return;
        const progress = Math.max(0, Math.min(100, Number(event.detail?.progress) || 0));
        updateDropzoneStatus(zone, `Preparing files… ${progress}%`);
    });

    const finishUpload = (event, failed = false) => {
        const input = event.target;
        const zone = input?.closest?.('[data-file-dropzone]');
        if (!zone) return;
        zone.classList.remove('is-uploading');
        zone.classList.toggle('has-upload-error', failed);
        updateDropzoneStatus(zone, failed ? 'Upload preparation failed. Please try again.' : selectedFileSummary(input.files));
    };

    document.addEventListener('livewire-upload-finish', (event) => finishUpload(event, false));
    document.addEventListener('livewire-upload-error', (event) => finishUpload(event, true));
    document.addEventListener('livewire-upload-cancel', (event) => finishUpload(event, true));
};
