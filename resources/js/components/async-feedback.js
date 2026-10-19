/**
 * FlowTrack centralized async feedback.
 *
 * Principles:
 * - Centralize the visual/behavior rules, not the physical placement.
 * - Never call a plain Livewire form-state sync "Saved".
 * - Prefer feature-level inline feedback when it already exists.
 * - Use one contextual fallback badge beside the control that started a real
 *   request when no local feedback is present.
 * - Never participate in sidebar interactions.
 */

const feedbackState = {
    intentBound: false,
    livewireBound: false,
    viewportBound: false,
    pendingIntent: null,
    active: new Map(),
    sequence: 0,
    hideTimer: null,
    showTimer: null,
    positionFrame: null,
    lastShownAt: 0,
    visibleIntent: null,
};

const INTENT_TTL = 1600;
const SHOW_DELAY = 180;
const MIN_VISIBLE = 300;
const SAVED_VISIBLE = 1050;
const ERROR_VISIBLE = 3000;
const VIEWPORT_GAP = 8;
const VIEWPORT_EDGE = 12;

// Global async feedback must never appear inside data-entry forms. Form
// controls often synchronize temporary Livewire state and validation can return
// a successful request without persisting a record. Forms use their own local
// button/upload/validation feedback instead.
const FORM_FEEDBACK_SILENT_SELECTOR = [
    'form',
    '[data-ft-feedback-scope=\"form\"]',
    '.ft-form-standard',
].join(', ');

// Saving/Saved is intentionally NOT inferred from method names.
// A method called openCreate, startPreview, createModal, etc. can be UI-only.
// Global saving feedback is opt-in via data-ft-feedback-kind="saving".
// Actual inline editors use the dedicated <x-ui.inline-save-state> component.

const LOAD_WORDS = [
    'load', 'fetch', 'search', 'filter', 'sort', 'page', 'refresh', 'preview',
    'export', 'query',
];

// Usually instant UI/state actions. They should not create a badge unless the
// feature explicitly opts in with data-ft-feedback-kind.
const UI_ONLY_WORDS = [
    'open', 'close', 'show', 'hide', 'toggle', 'select', 'reset', 'clear',
    'tab', 'set', 'change',
];

const normalize = (value) => String(value || '').trim().toLowerCase();

const instructionTokens = (value) => String(value || '')
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean);

const containsAnyToken = (value, words) => {
    const tokens = instructionTokens(value);
    return words.some((word) => tokens.includes(word));
};

const directiveValue = (element, prefix) => {
    if (!(element instanceof Element)) return '';

    const attribute = Array.from(element.attributes || [])
        .find((item) => item.name === prefix || item.name.startsWith(`${prefix}.`));

    return attribute?.value || '';
};

const hasDirective = (element, prefix) => {
    if (!(element instanceof Element)) return false;
    return Array.from(element.attributes || [])
        .some((item) => item.name === prefix || item.name.startsWith(`${prefix}.`));
};

const closestWireTrigger = (source) => {
    if (!(source instanceof Element)) return null;

    let current = source;
    while (current && current !== document.documentElement) {
        if (current.id === 'sidebar' || current.closest?.('#sidebar')) return null;

        if (current.matches?.('.main, .main *')) {
            const attributes = Array.from(current.attributes || []);
            const hasWireAction = attributes.some((attribute) => (
                attribute.name === 'wire:click'
                || attribute.name.startsWith('wire:click.')
                || attribute.name === 'wire:change'
                || attribute.name.startsWith('wire:change.')
                || attribute.name === 'wire:submit'
                || attribute.name.startsWith('wire:submit.')
                || attribute.name === 'wire:model'
                || attribute.name.startsWith('wire:model.')
            ));

            const hasExplicitFeedback = current.hasAttribute?.('data-ft-feedback-kind');
            const hasAsyncAlpineAction = attributes.some((attribute) => {
                if (!(attribute.name.startsWith('x-on:') || attribute.name.startsWith('@'))) return false;

                const expression = attribute.value || '';
                return expression.includes('$wire.')
                    || containsAnyToken(expression, LOAD_WORDS);
            });

            if (hasWireAction || hasExplicitFeedback || hasAsyncAlpineAction) return current;
        }

        current = current.parentElement;
    }

    return null;
};

const hasLocalLoadingCopy = (trigger) => {
    if (!(trigger instanceof Element)) return false;

    const nodes = [trigger, ...trigger.querySelectorAll('*')];
    return nodes.some((node) => Array.from(node.attributes || []).some((attribute) => {
        if (!attribute.name.startsWith('wire:loading')) return false;

        return !attribute.name.includes('.attr')
            && !attribute.name.includes('.class')
            && !attribute.name.includes('.remove');
    }));
};

const explicitKind = (trigger) => {
    const value = normalize(trigger?.dataset?.ftFeedbackKind);
    return ['saving', 'loading'].includes(value) ? value : '';
};

const isFormFeedbackSilent = (trigger) => {
    if (!(trigger instanceof Element)) return false;
    return Boolean(trigger.closest(FORM_FEEDBACK_SILENT_SELECTOR));
};

/**
 * Return "saving", "loading", or an empty string when the request should stay
 * silent. Silence is intentional for ordinary form draft-state synchronization.
 */
const inferKind = (trigger, eventType) => {
    const forced = explicitKind(trigger);
    if (forced) return forced;

    const click = directiveValue(trigger, 'wire:click');
    const change = directiveValue(trigger, 'wire:change');
    const submit = directiveValue(trigger, 'wire:submit');
    const model = directiveValue(trigger, 'wire:model');
    const alpine = Array.from(trigger?.attributes || [])
        .filter((attribute) => attribute.name.startsWith('x-on:') || attribute.name.startsWith('@'))
        .map((attribute) => attribute.value)
        .join(' ');

    const type = normalize(eventType);

    // Form submissions are handled locally by their button/upload/validation UI.
    // The global badge never guesses that a submit means the record was saved.
    if (submit || type === 'submit') return '';

    // A plain wire:model sync changes only Livewire component state. It is not
    // a database save, so do not show Saving/Saved. Search/filter models are the
    // one exception because their request visibly loads new result content.
    const hasActionDirective = Boolean(click || change || alpine);
    if (model && !hasActionDirective) {
        const modelTokens = instructionTokens(model);
        const isQueryModel = modelTokens.some((token) => (
            ['search', 'filter', 'filters', 'sort', 'query', 'page', 'term'].includes(token)
        ));

        return isQueryModel ? 'loading' : '';
    }

    const instruction = [click, change, alpine].filter(Boolean).join(' ');
    const tokens = instructionTokens(instruction);
    const first = tokens[0] || '';

    // UI navigation/state methods must win before any other word in the method
    // name. Example: openCreate must stay silent; it opens a screen and does not
    // persist a client.
    if (first === 'set' || UI_ONLY_WORDS.includes(first)) return '';

    // Global Loading remains available for operations whose purpose is actually
    // retrieving/rebuilding visible content.
    if (containsAnyToken(instruction, LOAD_WORDS)) return 'loading';

    // Changes and all other unknown actions stay silent. Persistence feedback is
    // explicit-only so a successful Livewire round-trip is never misreported as
    // a successful database save.
    if (change || type === 'change' || type === 'input') return '';
    if (click || alpine) return '';

    return '';
};

const feedbackUi = () => ({
    host: document.querySelector('[data-ft-context-feedback]'),
    label: document.querySelector('[data-ft-context-feedback-label]'),
});

const clearHideTimer = () => {
    if (!feedbackState.hideTimer) return;
    window.clearTimeout(feedbackState.hideTimer);
    feedbackState.hideTimer = null;
};

const clearShowTimer = () => {
    if (!feedbackState.showTimer) return;
    window.clearTimeout(feedbackState.showTimer);
    feedbackState.showTimer = null;
};

const captureRect = (element) => {
    if (!(element instanceof Element)) return null;

    const rect = element.getBoundingClientRect?.();
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;

    return {
        left: rect.left,
        top: rect.top,
        right: rect.right,
        bottom: rect.bottom,
        width: rect.width,
        height: rect.height,
    };
};

const currentRect = (intent) => {
    if (intent?.trigger?.isConnected) {
        return captureRect(intent.trigger) || intent.anchorRect || null;
    }

    return intent?.anchorRect || null;
};

const positionFeedback = (intent = feedbackState.visibleIntent) => {
    const { host } = feedbackUi();
    const rect = currentRect(intent);
    if (!host || host.hidden || !rect) return;

    // Let the browser calculate the badge dimensions before choosing a side.
    const previousVisibility = host.style.visibility;
    host.style.visibility = 'hidden';
    host.style.left = '0px';
    host.style.top = '0px';

    const box = host.getBoundingClientRect();
    const badgeWidth = Math.max(74, box.width || 0);
    const badgeHeight = Math.max(28, box.height || 0);
    const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
    const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
    const mainRect = document.querySelector('.main')?.getBoundingClientRect?.();
    const minLeft = Math.max(VIEWPORT_EDGE, (mainRect?.left || 0) + VIEWPORT_EDGE);
    const maxLeft = Math.max(minLeft, viewportWidth - badgeWidth - VIEWPORT_EDGE);
    const maxTop = Math.max(VIEWPORT_EDGE, viewportHeight - badgeHeight - VIEWPORT_EDGE);

    const spaceRight = viewportWidth - rect.right - VIEWPORT_EDGE;
    const spaceLeft = rect.left - minLeft;
    const spaceBelow = viewportHeight - rect.bottom - VIEWPORT_EDGE;
    const spaceAbove = rect.top - VIEWPORT_EDGE;

    let left;
    let top;
    let placement;

    // Prefer beside compact controls (task status, small buttons, etc.).
    if (rect.width <= 360 && spaceRight >= badgeWidth + VIEWPORT_GAP) {
        left = rect.right + VIEWPORT_GAP;
        top = rect.top + ((rect.height - badgeHeight) / 2);
        placement = 'right';
    } else if (rect.width <= 360 && spaceLeft >= badgeWidth + VIEWPORT_GAP) {
        left = rect.left - badgeWidth - VIEWPORT_GAP;
        top = rect.top + ((rect.height - badgeHeight) / 2);
        placement = 'left';
    } else if (spaceBelow >= badgeHeight + VIEWPORT_GAP || spaceBelow >= spaceAbove) {
        left = rect.right - badgeWidth;
        top = rect.bottom + VIEWPORT_GAP;
        placement = 'below';
    } else {
        left = rect.right - badgeWidth;
        top = rect.top - badgeHeight - VIEWPORT_GAP;
        placement = 'above';
    }

    host.style.left = `${Math.round(Math.min(maxLeft, Math.max(minLeft, left)))}px`;
    host.style.top = `${Math.round(Math.min(maxTop, Math.max(VIEWPORT_EDGE, top)))}px`;
    host.style.visibility = previousVisibility || '';
    host.dataset.placement = placement;
};

const schedulePosition = () => {
    if (feedbackState.positionFrame) return;

    feedbackState.positionFrame = window.requestAnimationFrame(() => {
        feedbackState.positionFrame = null;
        positionFeedback();
    });
};

const render = (status, text, intent = feedbackState.visibleIntent) => {
    const { host, label } = feedbackUi();
    if (!host || !label) return;

    clearHideTimer();
    feedbackState.visibleIntent = intent || feedbackState.visibleIntent;
    host.hidden = false;
    host.dataset.status = status;
    label.textContent = text;
    feedbackState.lastShownAt = Date.now();
    positionFeedback(feedbackState.visibleIntent);
};

const hide = () => {
    const { host } = feedbackUi();
    if (!host) return;

    host.hidden = true;
    host.dataset.status = 'idle';
    host.dataset.placement = '';
    host.style.removeProperty('left');
    host.style.removeProperty('top');
    host.style.removeProperty('visibility');
    feedbackState.visibleIntent = null;
};

const scheduleHide = (delay = MIN_VISIBLE) => {
    clearHideTimer();
    const elapsed = Date.now() - feedbackState.lastShownAt;
    const wait = Math.max(delay, MIN_VISIBLE - elapsed);
    feedbackState.hideTimer = window.setTimeout(hide, wait);
};

const startFeedback = (intent) => {
    clearShowTimer();
    clearHideTimer();
    feedbackState.visibleIntent = intent;

    feedbackState.showTimer = window.setTimeout(() => {
        render(intent.kind, intent.kind === 'saving' ? 'Saving…' : 'Loading…', intent);
    }, SHOW_DELAY);
};

const finishFeedback = (intent, ok) => {
    clearShowTimer();
    feedbackState.visibleIntent = intent;

    if (!ok) {
        render('error', intent.kind === 'saving' ? 'Couldn’t save' : 'Couldn’t load', intent);
        scheduleHide(ERROR_VISIBLE);
        return;
    }

    if (intent.kind === 'saving') {
        render('saved', 'Saved', intent);
        scheduleHide(SAVED_VISIBLE);
        return;
    }

    scheduleHide(MIN_VISIBLE);
};

const markIntent = (event) => {
    const trigger = closestWireTrigger(event.target);
    if (!trigger) return;

    if (isFormFeedbackSilent(trigger)) {
        feedbackState.pendingIntent = null;

        // If a badge from an earlier non-form action is still visible, remove
        // it as soon as the user starts interacting with a form.
        if (feedbackState.visibleIntent || !feedbackUi().host?.hidden) hide();
        return;
    }

    if (trigger.closest('.ft-inline-edit-shell')) {
        feedbackState.pendingIntent = null;
        return;
    }

    if (hasLocalLoadingCopy(trigger) && trigger.dataset.ftFeedback !== 'global') {
        feedbackState.pendingIntent = null;
        return;
    }

    if (trigger.closest('[data-ft-feedback="off"]') || trigger.dataset.ftFeedback === 'off') {
        feedbackState.pendingIntent = null;
        return;
    }

    const kind = inferKind(trigger, event.type);
    if (!kind) {
        feedbackState.pendingIntent = null;
        return;
    }

    feedbackState.pendingIntent = {
        kind,
        trigger,
        anchorRect: captureRect(trigger),
        createdAt: Date.now(),
    };
};

const consumeIntent = () => {
    const intent = feedbackState.pendingIntent;
    feedbackState.pendingIntent = null;

    if (!intent || Date.now() - intent.createdAt > INTENT_TTL) return null;
    if (intent.trigger?.closest?.('#sidebar')) return null;
    if (!intent.trigger?.closest?.('.main')) return null;
    if (isFormFeedbackSilent(intent.trigger)) return null;

    return intent;
};

const bindUserIntent = () => {
    if (feedbackState.intentBound) return;
    feedbackState.intentBound = true;

    document.addEventListener('click', markIntent, true);
    document.addEventListener('change', markIntent, true);
    document.addEventListener('input', markIntent, true);
    document.addEventListener('submit', markIntent, true);
};

const bindViewportPositioning = () => {
    if (feedbackState.viewportBound) return;
    feedbackState.viewportBound = true;

    window.addEventListener('resize', schedulePosition, { passive: true });
    window.addEventListener('scroll', schedulePosition, { passive: true, capture: true });
};

const bindLivewireRequests = () => {
    if (feedbackState.livewireBound || !window.Livewire?.hook) return;
    feedbackState.livewireBound = true;

    window.Livewire.hook('request', ({ succeed, fail }) => {
        const intent = consumeIntent();
        if (!intent) return;

        const id = ++feedbackState.sequence;
        feedbackState.active.set(id, intent);
        intent.trigger?.setAttribute?.('aria-busy', 'true');
        intent.trigger?.setAttribute?.('data-ft-request-pending', 'true');
        startFeedback(intent);

        const settle = (ok) => {
            const activeIntent = feedbackState.active.get(id);
            if (!activeIntent) return;

            feedbackState.active.delete(id);
            activeIntent.trigger?.removeAttribute?.('aria-busy');
            activeIntent.trigger?.removeAttribute?.('data-ft-request-pending');

            if (feedbackState.active.size > 0) return;
            finishFeedback(activeIntent, ok);
        };

        succeed(() => settle(true));
        fail(() => settle(false));
    });
};

export const createAsyncState = (config = {}) => ({
    status: '',
    feedbackTimer: null,
    feedbackSavedDuration: Number(config.savedDuration || 1600),
    feedbackErrorDuration: Number(config.errorDuration || 2600),

    feedbackClear() {
        if (this.feedbackTimer) window.clearTimeout(this.feedbackTimer);
        this.feedbackTimer = null;
        this.status = '';
    },

    feedbackSaving() {
        if (this.feedbackTimer) window.clearTimeout(this.feedbackTimer);
        this.feedbackTimer = null;
        this.status = 'saving';
    },

    feedbackSaved() {
        if (this.feedbackTimer) window.clearTimeout(this.feedbackTimer);
        this.status = 'saved';
        this.feedbackTimer = window.setTimeout(() => {
            if (this.status === 'saved') this.status = '';
        }, this.feedbackSavedDuration);
    },

    feedbackError() {
        if (this.feedbackTimer) window.clearTimeout(this.feedbackTimer);
        this.status = 'error';
        this.feedbackTimer = window.setTimeout(() => {
            if (this.status === 'error') this.status = '';
        }, this.feedbackErrorDuration);
    },
});

export const resetAsyncFeedback = () => {
    feedbackState.pendingIntent = null;
    feedbackState.active.clear();
    clearShowTimer();
    clearHideTimer();
    hide();
};

export const bootAsyncFeedback = () => {
    bindUserIntent();
    bindViewportPositioning();
    bindLivewireRequests();
};
