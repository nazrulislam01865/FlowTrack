const MAX_ARTWORK_BYTES = 400 * 1024 * 1024;
const DEFAULT_CHUNK_BYTES = 15 * 1024 * 1024;
const DEFAULT_CHUNK_CONCURRENCY = 3;
const MAX_CHUNK_CONCURRENCY = 6;
const MAX_FILES = 50;
const state = { bound: false };

const artworkInput = (target) => target instanceof HTMLInputElement
    && target.type === 'file'
    && target.matches('[data-artwork-chunk-input]')
    ? target
    : null;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const modalFor = (input) => {
    const local = input.closest?.('[data-artwork-upload-modal-task]');
    if (local?.isConnected) return local;

    const taskId = Math.max(0, Number(input.dataset.artworkTaskId || 0));
    return taskId > 0
        ? document.querySelector(`[data-artwork-upload-modal-task="${taskId}"]`)
        : null;
};

const setModalUploadBusy = (input, busy) => {
    const modal = modalFor(input);
    if (!modal) return;

    if (busy) modal.dataset.artworkUploading = '1';
    else delete modal.dataset.artworkUploading;

    modal.querySelectorAll('[data-artwork-chunk-input]').forEach((fileInput) => {
        fileInput.disabled = busy;
    });

    const saveButton = modal.querySelector('[data-artwork-upload-save]');
    if (saveButton instanceof HTMLButtonElement) {
        saveButton.disabled = busy || saveButton.dataset.serverDisabled === '1';
    }
};

const extensionOf = (name) => {
    const dot = String(name || '').lastIndexOf('.');
    return dot >= 0 ? String(name).slice(dot).toLowerCase() : '';
};

const allowedExtensions = (input) => String(input?.getAttribute('accept') || '')
    .split(',')
    .map((value) => value.trim().toLowerCase())
    .filter((value) => value.startsWith('.'));

const validateFiles = (input, files) => {
    const allowed = allowedExtensions(input);
    const currentCount = Math.max(0, Number(input.dataset.artworkCurrentCount || 0));
    const revisionDocumentId = Number(input.dataset.revisionDocumentId || 0);

    if (!revisionDocumentId && currentCount + files.length > MAX_FILES) {
        return `You can upload a maximum of ${MAX_FILES} artwork files at a time.`;
    }

    for (const file of files) {
        if (!file || file.size <= 0) return 'Empty artwork files are not allowed.';
        if (file.size > MAX_ARTWORK_BYTES) return `${file.name} is too large. Maximum artwork file size is 400 MB.`;
        if (allowed.length && !allowed.includes(extensionOf(file.name))) {
            return `${file.name} is not a supported business document type.`;
        }
    }

    return null;
};

const errorBox = (input) => {
    const zone = input.closest('[data-file-dropzone]') || input.parentElement;
    let box = zone?.nextElementSibling?.matches?.('[data-artwork-chunk-error]') ? zone.nextElementSibling : null;
    if (box) return box;
    box = document.createElement('p');
    box.className = 'validation-error';
    box.dataset.artworkChunkError = '1';
    box.hidden = true;
    zone?.insertAdjacentElement('afterend', box);
    return box;
};

const clearError = (input) => {
    const zone = input.closest('[data-file-dropzone]') || input.parentElement;
    const box = zone?.nextElementSibling?.matches?.('[data-artwork-chunk-error]') ? zone.nextElementSibling : null;
    if (!box) return;
    box.hidden = true;
    box.textContent = '';
};

const showError = (input, message) => {
    const box = errorBox(input);
    box.textContent = message || 'The artwork file could not be uploaded. Please try again.';
    box.hidden = false;
};

const dispatch = (input, name, detail = {}) => {
    input.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
};

const componentFor = (input) => {
    const root = input.closest('[wire\\:id]');
    const id = root?.getAttribute('wire:id');
    if (!id || !window.Livewire?.find) return null;
    return window.Livewire.find(id) || null;
};

const callComponent = (wire, method, ...args) => {
    if (!wire) return Promise.reject(new Error('Livewire component is unavailable. Refresh the page and try again.'));
    if (typeof wire.$call === 'function') return wire.$call(method, ...args);
    if (typeof wire[method] === 'function') return wire[method](...args);
    return Promise.reject(new Error(`Livewire method ${method} is unavailable.`));
};

const responseMessage = async (response) => {
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
        const data = await response.json().catch(() => ({}));
        const firstError = data?.errors && typeof data.errors === 'object'
            ? Object.values(data.errors).flat().find(Boolean)
            : null;
        return String(firstError || data?.message || `Upload request failed (${response.status}).`);
    }
    const text = await response.text().catch(() => '');
    return text && text.length < 300 ? text : `Upload request failed (${response.status}).`;
};

const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

/**
 * Retry only transient transport/server responses. Validation and permission
 * errors are returned immediately so selecting an invalid 400MB file never
 * causes several duplicate requests before the user sees the real message.
 */
const requestJson = async (url, options, retries = 3) => {
    let lastError = null;

    for (let attempt = 0; attempt <= retries; attempt += 1) {
        let response;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                ...options,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                    ...(options.headers || {}),
                },
            });
        } catch (error) {
            // Parallel workers share an AbortController. Once one worker fails or
            // the modal closes, do not retry requests intentionally cancelled by
            // that controller.
            if (options?.signal?.aborted || error?.name === 'AbortError') throw error;

            lastError = error instanceof Error ? error : new Error('Network error while uploading artwork.');
            if (attempt >= retries) throw lastError;
            await sleep(500 * (2 ** attempt));
            continue;
        }

        if (response.ok) return await response.json();

        const message = await responseMessage(response);
        const retryable = response.status === 408 || response.status === 429 || response.status >= 500;
        lastError = new Error(message);
        if (!retryable || attempt >= retries) throw lastError;

        await sleep(500 * (2 ** attempt));
    }

    throw lastError || new Error('The artwork upload failed.');
};

const cancelUpload = async (url) => {
    if (!url) return;
    try {
        await fetch(url, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
    } catch (_) {
        // Stale partial uploads are swept by ArtworkUploadStagingService.
    }
};

const uploadOne = async (input, file, fileIndex, fileCount) => {
    const startUrl = input.dataset.artworkUploadStartUrl;
    const taskId = Number(input.dataset.artworkTaskId || 0);
    const revisionDocumentId = Number(input.dataset.revisionDocumentId || 0);
    if (!startUrl || !taskId) throw new Error('Artwork upload configuration is incomplete. Refresh the page and try again.');

    const start = await requestJson(startUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            task_id: taskId,
            name: file.name,
            size: file.size,
            revision_document_id: revisionDocumentId || null,
        }),
    });

    const chunkBytes = Math.max(1024 * 1024, Number(start.chunk_bytes || DEFAULT_CHUNK_BYTES));
    const chunkCount = Math.ceil(file.size / chunkBytes);
    const concurrency = Math.max(1, Math.min(
        MAX_CHUNK_CONCURRENCY,
        Number(start.chunk_concurrency || DEFAULT_CHUNK_CONCURRENCY),
    ));
    const cancelUrl = start.cancel_url || '';

    try {
        // Upload up to three chunks at once. Files themselves remain sequential,
        // so choosing many artwork files cannot multiply browser/server pressure
        // into dozens of simultaneous PHP requests.
        const controller = new AbortController();
        let nextIndex = 0;
        let uploadedBytes = 0;
        let firstError = null;

        const worker = async () => {
            while (!firstError) {
                const index = nextIndex;
                nextIndex += 1;
                if (index >= chunkCount) return;

                if (!modalFor(input)?.isConnected) {
                    firstError = new Error('Artwork upload cancelled because the upload window was closed.');
                    controller.abort();
                    return;
                }

                const begin = index * chunkBytes;
                const end = Math.min(file.size, begin + chunkBytes);
                const bytes = end - begin;
                const form = new FormData();
                form.append('index', String(index));
                form.append('chunk', file.slice(begin, end), `${file.name}.part`);

                try {
                    await requestJson(start.chunk_url, {
                        method: 'POST',
                        body: form,
                        signal: controller.signal,
                    });
                } catch (error) {
                    if (!firstError) {
                        firstError = error instanceof Error ? error : new Error('The artwork upload failed.');
                        controller.abort();
                    }
                    return;
                }

                uploadedBytes += bytes;
                const fileProgress = Math.min(1, uploadedBytes / file.size);
                const overall = ((fileIndex + fileProgress) / fileCount) * 100;
                dispatch(input, 'flowtrack-artwork-upload-progress', {
                    progress: Math.max(1, Math.min(99, Math.floor(overall))),
                    file: file.name,
                    fileIndex: fileIndex + 1,
                    fileCount,
                });
            }
        };

        const workerCount = Math.min(chunkCount, concurrency);
        await Promise.all(Array.from({ length: workerCount }, () => worker()));
        if (firstError) throw firstError;

        const completed = await requestJson(start.complete_url, { method: 'POST' });
        return {
            token: String(completed.token || start.token || ''),
            cancelUrl,
        };
    } catch (error) {
        await cancelUpload(cancelUrl);
        throw error;
    }
};

const handleSelection = async (input) => {
    const files = Array.from(input.files || []);
    const modal = modalFor(input);
    if (!files.length || input.dataset.artworkUploading === '1' || modal?.dataset.artworkUploading === '1') return;

    clearError(input);
    const validation = validateFiles(input, files);
    if (validation) {
        input.value = '';
        showError(input, validation);
        dispatch(input, 'flowtrack-artwork-upload-error', { message: validation });
        return;
    }

    const wire = componentFor(input);
    if (!wire) {
        input.value = '';
        showError(input, 'The Order page is not ready. Refresh the page and try again.');
        return;
    }

    input.dataset.artworkUploading = '1';
    setModalUploadBusy(input, true);
    dispatch(input, 'flowtrack-artwork-upload-start', { progress: 0, fileCount: files.length });

    const completedUploads = [];
    const revisionDocumentId = Number(input.dataset.revisionDocumentId || 0);

    try {
        for (let index = 0; index < files.length; index += 1) {
            completedUploads.push(await uploadOne(input, files[index], index, files.length));
        }

        const tokens = completedUploads.map((upload) => upload.token).filter(Boolean);
        if (tokens.length !== files.length) throw new Error('The server did not return a valid artwork upload token. Please try again.');

        // Register only compact tokens in Livewire. The 400MB payload itself has
        // already travelled through small requests and never enters Livewire's
        // temporary upload endpoint.
        if (revisionDocumentId) {
            await callComponent(wire, 'registerOverviewTaskArtworkRevisionUpload', revisionDocumentId, tokens[0]);
        } else {
            await callComponent(wire, 'registerOverviewTaskArtworkUploads', tokens);
        }

        dispatch(input, 'flowtrack-artwork-upload-finish', { progress: 100, fileCount: files.length });
    } catch (error) {
        // A transfer may finish successfully but fail while registering its token
        // because the modal/task changed. Remove those staged files immediately.
        await Promise.all(completedUploads.map((upload) => cancelUpload(upload.cancelUrl)));

        const message = error instanceof Error ? error.message : 'The artwork file could not be uploaded.';
        showError(input, message);
        dispatch(input, 'flowtrack-artwork-upload-error', { message });
    } finally {
        input.value = '';
        delete input.dataset.artworkUploading;
        setModalUploadBusy(input, false);
    }
};

export const bootArtworkChunkUpload = () => {
    if (state.bound) return;
    state.bound = true;

    document.addEventListener('change', (event) => {
        const input = artworkInput(event.target);
        if (!input) return;
        // No wire:model exists on these Artwork-only inputs, so the browser file
        // goes exclusively through the chunk transport.
        void handleSelection(input);
    });
};
