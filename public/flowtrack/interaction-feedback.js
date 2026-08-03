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

    function loaderExcluded(button) {
        return Boolean(
            button?.matches('[data-no-button-loader],.ft-no-button-loader')
            || button?.closest('#sidebar,.sidebar')
        );
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
            const state = markRequestStart(interaction);
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
        const clickedButton = event.target instanceof Element ? event.target.closest('button') : null;
        if (clickedButton?.classList.contains('ft-button-loading')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        const button = eligibleButton(event.target);
        if (!button) return;

        const navigation = isNavigationButton(button);
        const state = begin(button, { navigation });
        if (!state) return;

        activeInteraction = { button, navigation, at: now() };

        if (!navigation) {
            clearTimeout(state.stopTimer);
            state.stopTimer = window.setTimeout(() => {
                if (state.pending === 0) finish(button);
            }, MIN_VISIBLE_MS + 80);
        }
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form) return;
        const submitter = event.submitter instanceof HTMLButtonElement
            ? event.submitter
            : form.querySelector('button[type="submit"]');
        if (!submitter) return;
        begin(submitter, { navigation: true });
        activeInteraction = { button: submitter, navigation: true, at: now() };
    }, true);

    window.addEventListener('flowtrack:navigation-complete', () => {
        const interaction = activeInteraction;
        if (!interaction?.navigation) return;
        const state = states.get(interaction.button);
        if (!state) return;
        state.navigation = false;
        if (state.pending === 0) finish(interaction.button);
    });

    window.addEventListener('pageshow', () => {
        document.querySelectorAll('button.ft-button-loading').forEach((button) => finish(button, true));
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
