const localFileActionsState = {
    bound: false,
    filesByInputId: new Map(),
};

const rememberInputFiles = (input) => {
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.id) return;
    localFileActionsState.filesByInputId.set(input.id, Array.from(input.files || []));
};

const selectedFileFor = (trigger) => {
    const inputId = trigger?.dataset?.inputId;
    if (!inputId) return null;

    const input = document.getElementById(inputId);
    if (input instanceof HTMLInputElement && input.type === 'file' && input.files?.length) {
        rememberInputFiles(input);
    }

    const expectedName = trigger.dataset.fileName || '';
    const expectedSize = Number(trigger.dataset.fileSize || 0);
    const files = localFileActionsState.filesByInputId.get(inputId) || [];

    return files.find((file) => (
        file.name === expectedName && (!expectedSize || file.size === expectedSize)
    )) || null;
};

const previewLocalFile = (file) => {
    const url = URL.createObjectURL(file);
    const opened = window.open(url, '_blank');
    if (opened) opened.opener = null;
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
};

const downloadLocalFile = (file) => {
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1_000);
};

export const bootLocalFileActions = () => {
    if (localFileActionsState.bound) return;
    localFileActionsState.bound = true;

    document.addEventListener('change', (event) => {
        rememberInputFiles(event.target);
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[data-local-file-action]')
            : null;
        if (!trigger) return;

        const file = selectedFileFor(trigger);
        if (!file) return;

        if (trigger.dataset.localFileAction === 'download') {
            downloadLocalFile(file);
            return;
        }

        previewLocalFile(file);
    });
};
