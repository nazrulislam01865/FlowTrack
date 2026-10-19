const state = {
        pending: new Map(),
        errors: new Map(),
        toasts: null,
    };

    const ensureUi = () => {
        if (state.toasts?.isConnected) return;

        // Inline saves no longer use a floating global status notification.
        // Keep only actionable failure toasts with Retry/Dismiss controls.
        const toasts = document.createElement('div');
        toasts.id = 'flowtrackInlineToasts';
        toasts.className = 'ft-inline-toast-region';
        toasts.setAttribute('aria-live', 'assertive');

        document.body.append(toasts);
        state.toasts = toasts;
    };

    const updateGlobal = () => {
        // Intentionally silent. Per-field saving/error state remains available
        // and failures still use the actionable toast region.
    };

    const removeToast = (key) => {
        state.toasts?.querySelector(`[data-inline-toast="${CSS.escape(String(key))}"]`)?.remove();
    };

    const friendlyNetworkMessage = (label) => `Could not confirm ${label}. The previous value is shown for safety. Retry to save the change again.`;

    const errorMessage = (error, label) => {
        const responseMessage = error?.flowtrackMessage;
        if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
            return responseMessage.trim();
        }

        // Livewire 4 #[Json] actions reject validation failures with a structured
        // `errors` object. Surface the first useful validation message instead of
        // hiding the real persistence failure behind a generic network message.
        const validationErrors = error?.errors;
        if (validationErrors && typeof validationErrors === 'object') {
            const firstMessages = Object.values(validationErrors).flat().filter((message) => typeof message === 'string' && message.trim() !== '');
            if (firstMessages.length > 0) return firstMessages[0].trim();
        }

        return friendlyNetworkMessage(label);
    };

    const showFailureToast = ({ key, label, message, retry }) => {
        ensureUi();
        removeToast(key);

        const toast = document.createElement('div');
        toast.className = 'ft-inline-toast';
        toast.dataset.inlineToast = String(key);

        const copy = document.createElement('div');
        copy.className = 'ft-inline-toast-copy';
        const title = document.createElement('strong');
        title.textContent = `Couldn’t save ${label}.`;
        const detail = document.createElement('span');
        detail.textContent = message;
        copy.append(title, detail);

        const actions = document.createElement('div');
        actions.className = 'ft-inline-toast-actions';
        const retryButton = document.createElement('button');
        retryButton.type = 'button';
        retryButton.textContent = 'Retry';
        retryButton.addEventListener('click', () => {
            toast.remove();
            retry?.();
        });
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'ft-inline-toast-close';
        closeButton.setAttribute('aria-label', 'Dismiss');
        closeButton.textContent = '×';
        closeButton.addEventListener('click', () => toast.remove());
        actions.append(retryButton, closeButton);

        toast.append(copy, actions);
        state.toasts.prepend(toast);
    };

    const resetGlobalState = () => {
        state.pending.clear();
        state.errors.clear();
        if (state.toasts) state.toasts.innerHTML = '';
    };

    export const resetInlineEditState = resetGlobalState;

    const bus = {
        start(key, label) {
            const token = `${key}:${Date.now()}:${Math.random().toString(36).slice(2)}`;
            state.pending.set(token, { key, label });
            state.errors.delete(String(key));
            removeToast(key);
            updateGlobal();
            return token;
        },
        success(token, key) {
            state.pending.delete(token);
            state.errors.delete(String(key));
            removeToast(key);
            updateGlobal();
        },
        fail(token, payload) {
            state.pending.delete(token);
            state.errors.set(String(payload.key), payload);
            showFailureToast(payload);
            updateGlobal();
        },
        clearError(key) {
            state.errors.delete(String(key));
            removeToast(key);
            updateGlobal();
        },
    };

    const normalize = (value) => value === null || value === undefined ? '' : String(value);

export const createInlineEdit = (config = {}) => {
        const initialValue = normalize(config.value);
        const initialDisplay = normalize(config.display ?? config.value);
        const initialAvatarUrl = normalize(config.avatarUrl);

        return {
            key: String(config.key || `inline-${Math.random().toString(36).slice(2)}`),
            label: String(config.label || 'change'),
            serverValue: initialValue,
            value: initialValue,
            display: initialDisplay,
            savedValue: initialValue,
            savedDisplay: initialDisplay,
            avatarUrl: initialAvatarUrl,
            savedAvatarUrl: initialAvatarUrl,
            draftValue: initialValue,
            editing: false,
            status: '',
            error: '',
            attemptedValue: null,
            attemptedDisplay: null,
            attemptedOptions: null,
            retryAction: null,
            clearTimer: null,
            requestSequence: 0,
            lastResponse: null,
            hasRichTextOverride: false,
            richTextOverrideHtml: '',

            beginEdit() {
                if (this.status === 'saving') return false;
                this.draftValue = this.value;
                this.editing = true;
                return true;
            },

            openRemotePicker(source) {
                // Capture the opener geometry before beginEdit() changes the DOM.
                // Several inline editors hide the display row (and therefore the
                // pencil button) as soon as editing starts. Reading its rect on
                // the following Alpine tick returns a zero-sized rectangle and
                // makes fixed dropdowns fall back to the top-left of the viewport.
                const sourceRect = source?.getBoundingClientRect?.();
                const anchorRect = sourceRect && sourceRect.width > 0 && sourceRect.height > 0
                    ? {
                        left: sourceRect.left,
                        top: sourceRect.top,
                        right: sourceRect.right,
                        bottom: sourceRect.bottom,
                        width: sourceRect.width,
                        height: sourceRect.height,
                    }
                    : null;

                if (!this.beginEdit()) return false;

                this.$nextTick(() => {
                    const host = source?.closest?.('.ft-inline-edit-shell');
                    const picker = host?.querySelector?.('[data-ft-inline-remote-picker]');
                    if (!picker) return;

                    picker.dispatchEvent(new CustomEvent('ft-inline-remote-open', {
                        detail: {
                            value: this.value,
                            label: this.display,
                            anchor: source || null,
                            rect: anchorRect,
                        },
                    }));
                });

                return true;
            },

            cancelEdit() {
                this.draftValue = this.value;
                this.editing = false;
            },

            beginRichTextEdit(source) {
                if (!this.beginEdit()) return false;
                this.$nextTick(() => {
                    source?.__flowtrackRichTextSetValue?.(this.value);
                    source?.focus?.();
                });
                return true;
            },

            cancelRichTextEdit(source) {
                source?.__flowtrackRichTextSetValue?.(this.value);
                this.cancelEdit();
            },

            async saveRichText(source, emptyDisplay, requestFactory) {
                if (!source || typeof requestFactory !== 'function' || this.status === 'saving') return false;

                const raw = typeof source.__flowtrackRichTextValueAsync === 'function'
                    ? await source.__flowtrackRichTextValueAsync()
                    : (typeof source.__flowtrackRichTextValue === 'function'
                        ? source.__flowtrackRichTextValue()
                        : source.value);
                const clean = normalize(raw).trim();
                this.draftValue = clean;

                const ok = await this.commit(
                    clean,
                    clean ? 'Description updated.' : emptyDisplay,
                    () => requestFactory(clean),
                );

                if (!ok) return false;

                // Description updates are Renderless Livewire actions. Do not
                // force a second $refresh() request after the database save.
                // Instead, close the editor and use the server-rendered safe
                // HTML returned by the save action so the new text/images are
                // visible immediately without a full page refresh.
                const displayHtml = this.lastResponse && typeof this.lastResponse === 'object'
                    ? this.lastResponse.displayHtml
                    : null;

                if (typeof displayHtml === 'string') {
                    this.richTextOverrideHtml = displayHtml;
                    this.hasRichTextOverride = true;
                }

                this.editing = false;
                return true;
            },

            selectedLabel(event, fallback = '') {
                const option = event?.target?.selectedOptions?.[0];
                return (option?.textContent || fallback || event?.target?.value || '').trim();
            },

            formatDate(value, short = false) {
                if (!value) return short ? 'Set date' : 'Not set';
                const [year, month, day] = String(value).split('-').map(Number);
                if (!year || !month || !day) return String(value);
                const date = new Date(year, month - 1, day);
                return date.toLocaleDateString('en-US', short
                    ? { month: 'short', day: 'numeric' }
                    : { month: 'short', day: 'numeric', year: 'numeric' });
            },

            formatDateTime(value) {
                if (!value) return '—';
                const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
                if (!match) return String(value);
                const [, year, month, day, hour, minute] = match;
                const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute));
                if (Number.isNaN(date.getTime())) return String(value);
                const datePart = date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                });
                const timePart = date.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                });
                return `${datePart} · ${timePart}`;
            },

            numberLabel(value) {
                const number = Number.parseInt(String(value || '0'), 10);
                return Number.isFinite(number) ? Math.max(0, number).toLocaleString() : String(value || '');
            },

            positiveInteger(value, minimum = 1) {
                const number = Number.parseInt(String(value || minimum), 10);
                return String(Number.isFinite(number) ? Math.max(minimum, number) : minimum);
            },

            initials(value = this.display) {
                return String(value || '?')
                    .split(/\s+/)
                    .filter(Boolean)
                    .slice(0, 2)
                    .map((part) => part[0])
                    .join('')
                    .toUpperCase() || '?';
            },

            syncConfirmed(nextValue, nextDisplay, nextOptions = {}) {
                const confirmedValue = normalize(nextValue);
                const confirmedDisplay = normalize(nextDisplay ?? nextValue);
                const hasAvatarOverride = nextOptions && Object.prototype.hasOwnProperty.call(nextOptions, 'avatarUrl');

                this.serverValue = confirmedValue;
                this.value = confirmedValue;
                this.savedValue = confirmedValue;
                this.draftValue = confirmedValue;
                this.display = confirmedDisplay;
                this.savedDisplay = confirmedDisplay;
                if (hasAvatarOverride) {
                    const confirmedAvatarUrl = normalize(nextOptions.avatarUrl);
                    this.avatarUrl = confirmedAvatarUrl;
                    this.savedAvatarUrl = confirmedAvatarUrl;
                }
                this.editing = false;

                if (this.status !== 'saving') {
                    this.status = '';
                    this.error = '';
                }
            },

            async commit(nextValue, nextDisplay, requestFactory, nextOptions = {}) {
                if (typeof requestFactory !== 'function' || this.status === 'saving') return false;

                const normalizedValue = normalize(nextValue);
                const normalizedDisplay = normalize(nextDisplay ?? nextValue);
                const hasAvatarOverride = nextOptions && Object.prototype.hasOwnProperty.call(nextOptions, 'avatarUrl');
                const nextAvatarUrl = hasAvatarOverride ? normalize(nextOptions.avatarUrl) : this.savedAvatarUrl;
                if (normalizedValue === this.savedValue && normalizedDisplay === this.savedDisplay) {
                    this.lastResponse = null;
                    this.value = this.savedValue;
                    this.display = this.savedDisplay;
                    this.draftValue = this.savedValue;
                    if (hasAvatarOverride) {
                        this.avatarUrl = nextAvatarUrl;
                        this.savedAvatarUrl = nextAvatarUrl;
                    }
                    this.editing = false;
                    return true;
                }

                const previousValue = this.savedValue;
                const previousDisplay = this.savedDisplay;
                const previousAvatarUrl = this.savedAvatarUrl;
                const sequence = ++this.requestSequence;
                const token = bus.start(this.key, this.label);
                this.lastResponse = null;

                this.attemptedValue = normalizedValue;
                this.attemptedDisplay = normalizedDisplay;
                this.attemptedOptions = { ...nextOptions };
                this.retryAction = requestFactory;
                this.value = normalizedValue;
                this.display = normalizedDisplay;
                if (hasAvatarOverride) this.avatarUrl = nextAvatarUrl;
                this.draftValue = normalizedValue;
                this.editing = false;
                this.status = 'saving';
                this.error = '';

                if (this.clearTimer) window.clearTimeout(this.clearTimer);

                try {
                    const response = await requestFactory();
                    if (sequence !== this.requestSequence) return false;

                    // Never mark an optimistic edit as saved unless the server
                    // explicitly confirms persistence. Previously null/undefined (or
                    // any non-object Livewire result) silently fell through as success,
                    // leaving a new value visible even when it was never confirmed.
                    if (!response || typeof response !== 'object' || response.ok !== true) {
                        const message = response && typeof response === 'object'
                            ? response.message
                            : friendlyNetworkMessage(this.label);
                        const inlineError = new Error(message || friendlyNetworkMessage(this.label));
                        inlineError.flowtrackMessage = message;
                        throw inlineError;
                    }

                    const responseHasAvatar = Object.prototype.hasOwnProperty.call(response, 'avatarUrl');
                    const confirmedAvatarUrl = responseHasAvatar ? normalize(response.avatarUrl) : nextAvatarUrl;
                    const responseHasValue = Object.prototype.hasOwnProperty.call(response, 'value');
                    const confirmedValue = responseHasValue ? normalize(response.value) : normalizedValue;
                    const responseHasDisplay = Object.prototype.hasOwnProperty.call(response, 'display');
                    const confirmedDisplay = responseHasDisplay ? normalize(response.display) : normalizedDisplay;

                    this.lastResponse = response;
                    this.value = confirmedValue;
                    this.display = confirmedDisplay;
                    this.draftValue = confirmedValue;
                    this.savedValue = confirmedValue;
                    this.savedDisplay = confirmedDisplay;
                    if (hasAvatarOverride || responseHasAvatar) {
                        this.avatarUrl = confirmedAvatarUrl;
                        this.savedAvatarUrl = confirmedAvatarUrl;
                    }
                    this.editing = false;
                    this.status = 'saved';
                    bus.success(token, this.key);
                    this.clearTimer = window.setTimeout(() => {
                        if (this.status === 'saved' && sequence === this.requestSequence) this.status = '';
                    }, 1600);
                    return true;
                } catch (error) {
                    if (sequence !== this.requestSequence) return false;

                    this.value = previousValue;
                    this.display = previousDisplay;
                    this.avatarUrl = previousAvatarUrl;
                    this.savedAvatarUrl = previousAvatarUrl;
                    this.draftValue = previousValue;
                    this.lastResponse = null;
                    this.status = 'error';
                    this.error = errorMessage(error, this.label);

                    bus.fail(token, {
                        key: this.key,
                        label: this.label,
                        message: this.error,
                        retry: () => this.retry(),
                    });
                    return false;
                }
            },

            retry() {
                if (!this.retryAction || this.attemptedValue === null || this.status === 'saving') return false;
                bus.clearError(this.key);
                return this.commit(this.attemptedValue, this.attemptedDisplay, this.retryAction, this.attemptedOptions || {});
            },
        };
    };
