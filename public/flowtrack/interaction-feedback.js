(() => {
    'use strict';

    if (window.__FLOWTRACK_INTERACTION_FEEDBACK__) return;
    window.__FLOWTRACK_INTERACTION_FEEDBACK__ = true;

    const MIN_VISIBLE_MS = 180;
    const FETCH_ASSOCIATION_MS = 800;
    const NAVIGATION_TIMEOUT_MS = 20000;
    const states = new WeakMap();
    let activeInteraction = null;

    function now() {
        return typeof performance?.now === 'function' ? performance.now() : Date.now();
    }

    const FILE_PICKER_TRIGGER_SELECTOR = [
        '[data-task-upload-browse]',
        '[data-ft-task-browse]',
        '[data-job-doc-browse]',
        '[data-job-v4-browse]',
        '[data-job-upload-browse]',
        '[data-new-job-browse]',
        '#changeProfilePhoto',
        '#uploadSystemLogo',
        '#uploadSystemFavicon',
    ].join(',');
    const FILE_PICKER_DROPZONE_SELECTOR = [
        '[data-task-upload-dropzone]',
        '[data-ft-task-dropzone]',
        '[data-job-doc-dropzone]',
        '[data-job-v4-dropzone]',
        '[data-job-upload-dropzone]',
        '#newJobAttachmentDrop',
    ].join(',');
    const ATTACHMENT_UTILITY_SELECTOR = [
        FILE_PICKER_TRIGGER_SELECTOR,
        '[data-task-choose-documents]',
        '[data-ft-task-choose-documents]',
        '[data-job-doc-choose]',
        '[data-job-v4-choose-documents]',
        '[data-job-choose-documents]',
        '[data-new-job-choose-documents]',
    ].join(',');
    const NAVIGATION_UTILITY_SELECTOR = [
        '[data-page]',
        '[data-page-jump]',
        '[data-go]',
        '[data-detailtab]',
        '[role="tab"]',
        '[data-standalone-back]',
    ].join(',');
    const EXPLICIT_ACTION_LAUNCHER_SELECTOR = [
        '#newJob',
        '#jobCreate',
        '#boardCreate',
        '#addClient',
        '#addWorkflow',
        '#addTaskPack',
        '[data-dashboard-action="create"]',
        '[data-add-task]',
        '[data-job-v4-add-task]',
        '[data-new-client-job]',
    ].join(',');

    function loaderExcluded(button) {
        return Boolean(
            button?.matches('[data-no-button-loader],.ft-no-button-loader')
            || button?.matches(ATTACHMENT_UTILITY_SELECTOR)
            || button?.matches(NAVIGATION_UTILITY_SELECTOR)
            || button?.closest('#sidebar,.sidebar')
        );
    }

    function explicitActionLauncher(button) {
        return Boolean(button?.matches(EXPLICIT_ACTION_LAUNCHER_SELECTOR));
    }

    function requestMethod(args) {
        const input = args?.[0];
        const init = args?.[1];
        const method = init?.method || (typeof Request !== 'undefined' && input instanceof Request ? input.method : 'GET');
        return String(method || 'GET').trim().toUpperCase();
    }

    function mutatingRequest(args) {
        return !['GET', 'HEAD', 'OPTIONS'].includes(requestMethod(args));
    }

    function fileInputForTrigger(trigger) {
        if (!(trigger instanceof Element)) return null;
        const explicitTargets = {
            changeProfilePhoto: '#profileAvatarInput',
            uploadSystemLogo: '#systemLogoInput',
            uploadSystemFavicon: '#systemFaviconInput',
        };
        const explicitSelector = trigger.id ? explicitTargets[trigger.id] : null;
        if (explicitSelector) {
            const explicitInput = document.querySelector(explicitSelector);
            return explicitInput instanceof HTMLInputElement && !explicitInput.disabled ? explicitInput : null;
        }
        const scope = trigger.closest(FILE_PICKER_DROPZONE_SELECTOR)
            || trigger.closest('.ft-task-dropzone,.task-attachment-dropzone,.job-v6-dropzone,.job-document-dropzone,.job-create-task-dropzone')
            || trigger.parentElement;
        const input = scope?.querySelector?.('input[type="file"]');
        return input instanceof HTMLInputElement && !input.disabled ? input : null;
    }

    function openNativeFilePicker(input) {
        if (!(input instanceof HTMLInputElement) || input.disabled) return false;
        // Clearing the value allows selecting the same file twice in a row and
        // still emits a change event after a failed/retried upload.
        input.value = '';
        try {
            if (typeof input.showPicker === 'function') {
                input.showPicker();
                return true;
            }
        } catch (_) {
            // Safari/older Chromium can reject showPicker while click() remains valid.
        }
        input.click();
        return true;
    }

    function eligibleButton(value) {
        const button = value instanceof Element ? value.closest('button') : null;
        if (!(button instanceof HTMLButtonElement)) return null;
        if (button.disabled || loaderExcluded(button)) return null;
        return button;
    }

    function isNavigationButton(button) {
        return Boolean(button?.matches('[data-page],[data-page-jump],[data-go]'));
    }

    function ensureSpinner(button) {
        let spinner = button.querySelector(':scope > .ft-button-spinner');
        if (spinner) return spinner;
        spinner = document.createElement('span');
        spinner.className = 'ft-button-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        button.prepend(spinner);
        return spinner;
    }

    function begin(button, options = {}) {
        if (!(button instanceof HTMLButtonElement) || button.disabled || loaderExcluded(button)) return null;

        let state = states.get(button);
        if (!state) {
            state = {
                button,
                startedAt: now(),
                pending: 0,
                navigation: false,
                stopTimer: null,
                timeoutTimer: null,
                originalAriaBusy: button.getAttribute('aria-busy'),
                originalSpinnerColor: button.style.getPropertyValue('--ft-button-spinner-color'),
            };
            states.set(button, state);
        }

        state.startedAt = now();
        state.navigation = Boolean(options.navigation || state.navigation);
        clearTimeout(state.stopTimer);
        clearTimeout(state.timeoutTimer);

        const spinnerColor = getComputedStyle(button).color || '#2563eb';
        button.style.setProperty('--ft-button-spinner-color', spinnerColor);
        ensureSpinner(button);
        button.classList.add('ft-button-loading');
        button.setAttribute('aria-busy', 'true');

        state.timeoutTimer = window.setTimeout(() => finish(button, true), NAVIGATION_TIMEOUT_MS);
        return state;
    }

    function restoreButton(state) {
        const { button } = state;
        if (!(button instanceof HTMLButtonElement) || !button.isConnected) return;
        button.classList.remove('ft-button-loading');
        button.querySelector(':scope > .ft-button-spinner')?.remove();
        if (state.originalSpinnerColor) button.style.setProperty('--ft-button-spinner-color', state.originalSpinnerColor);
        else button.style.removeProperty('--ft-button-spinner-color');
        if (state.originalAriaBusy == null) button.removeAttribute('aria-busy');
        else button.setAttribute('aria-busy', state.originalAriaBusy);
    }

    function finish(button, force = false) {
        const state = states.get(button);
        if (!state) return;
        if (!force && (state.pending > 0 || state.navigation)) return;

        clearTimeout(state.stopTimer);
        clearTimeout(state.timeoutTimer);
        const remaining = Math.max(0, MIN_VISIBLE_MS - (now() - state.startedAt));
        state.stopTimer = window.setTimeout(() => {
            restoreButton(state);
            states.delete(button);
            if (activeInteraction?.button === button) activeInteraction = null;
        }, remaining);
    }

    function markRequestStart(interaction) {
        if (!interaction?.button?.isConnected) return null;
        const state = begin(interaction.button, { navigation: interaction.navigation });
        if (!state) return null;
        state.pending += 1;
        clearTimeout(state.stopTimer);
        return state;
    }

    function markRequestEnd(state) {
        if (!state) return;
        state.pending = Math.max(0, state.pending - 1);
        if (state.pending > 0 || state.navigation) return;
        clearTimeout(state.stopTimer);
        state.stopTimer = window.setTimeout(() => finish(state.button), 80);
    }

    function recentInteraction() {
        if (!activeInteraction) return null;
        if ((now() - activeInteraction.at) > FETCH_ASSOCIATION_MS) return null;
        if (!activeInteraction.button?.isConnected) return null;
        return activeInteraction;
    }

    const nativeFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
    if (nativeFetch) {
        window.fetch = function (...args) {
            const interaction = recentInteraction();
            // Button spinners belong to real mutations only. Read-only GETs used
            // by tabs, filters, expand/collapse, detail panels and refreshes use
            // the page/inline circular loader instead of flashing a button spinner.
            const state = mutatingRequest(args) ? markRequestStart(interaction) : null;
            let result;
            try {
                result = nativeFetch(...args);
            } catch (error) {
                markRequestEnd(state);
                throw error;
            }
            return Promise.resolve(result).finally(() => markRequestEnd(state));
        };
    }

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const browseTrigger = target?.closest(FILE_PICKER_TRIGGER_SELECTOR);
        if (browseTrigger) {
            const input = fileInputForTrigger(browseTrigger);
            if (!input) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            openNativeFilePicker(input);
            return;
        }

        // Make the whole attachment dropzone a reliable native file-picker target.
        // Buttons/links inside it keep their own actions (Choose from Documents, etc.).
        const dropzone = target?.closest(FILE_PICKER_DROPZONE_SELECTOR);
        if (dropzone && !target.closest('button,a,input,select,textarea,[role="button"]')) {
            const input = fileInputForTrigger(dropzone);
            if (input) {
                event.preventDefault();
                event.stopImmediatePropagation();
                openNativeFilePicker(input);
                return;
            }
        }

        const clickedButton = target?.closest('button') || null;
        if (clickedButton?.classList.contains('ft-button-loading')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        const button = eligibleButton(event.target);
        if (!button) return;

        const navigation = isNavigationButton(button);
        if (navigation) return;

        // Remember the initiating button, but do not show a spinner merely for
        // a click. If the action performs POST/PUT/PATCH/DELETE, the fetch hook
        // starts the spinner and owns its lifetime. This prevents loaders on
        // tabs, filters, menus, phase toggles, document chips and other UI-only
        // controls. Explicit create-form launchers keep the short action feedback
        // requested by the UI even when the form is already available locally.
        activeInteraction = { button, navigation: false, at: now() };
        if (explicitActionLauncher(button)) {
            const state = begin(button, { navigation: false });
            if (!state) return;
            clearTimeout(state.stopTimer);
            state.stopTimer = window.setTimeout(() => {
                if (state.pending === 0) finish(button);
            }, MIN_VISIBLE_MS + 80);
        }
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form) return;
        const method = String(form.getAttribute('method') || form.method || 'GET').toUpperCase();
        if (method === 'GET') return;
        const submitter = event.submitter instanceof HTMLButtonElement
            ? event.submitter
            : form.querySelector('button[type="submit"]');
        if (!submitter || loaderExcluded(submitter)) return;
        begin(submitter, { navigation: false });
        activeInteraction = { button: submitter, navigation: false, at: now() };
    }, true);

    window.addEventListener('flowtrack:navigation-complete', () => {
        const interaction = activeInteraction;
        if (!interaction?.navigation) return;
        const state = states.get(interaction.button);
        if (!state) return;
        state.navigation = false;
        if (state.pending === 0) finish(interaction.button);
    });

    function clearAllButtonLoaders() {
        document.querySelectorAll('button.ft-button-loading').forEach((button) => finish(button, true));
        activeInteraction = null;
    }

    window.addEventListener('pageshow', clearAllButtonLoaders);
    window.addEventListener('flowtrack:navigation-complete', () => {
        window.setTimeout(clearAllButtonLoaders, 120);
    });
    window.addEventListener('unhandledrejection', () => {
        window.setTimeout(clearAllButtonLoaders, 120);
    });

    window.FlowTrackButtonLoading = Object.freeze({
        start(button) {
            return begin(button);
        },
        stop(button) {
            finish(button, true);
        },
        track(button, promise) {
            const state = begin(button);
            if (state) state.pending += 1;
            return Promise.resolve(promise).finally(() => {
                if (state) {
                    state.pending = Math.max(0, state.pending - 1);
                    finish(button);
                }
            });
        },
    });
})();
