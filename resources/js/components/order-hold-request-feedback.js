const HOLD_BLOCK_MESSAGE = 'This Order is on hold. Release the hold before performing any activity.';

const state = { bound: false, modal: null, lastTrigger: null };

const ensureModal = () => {
    if (state.modal?.isConnected) return state.modal;

    const backdrop = document.createElement('div');
    backdrop.className = 'ft-global-order-hold-backdrop';
    backdrop.hidden = true;
    backdrop.innerHTML = `
        <section class="ft-global-order-hold-modal" role="dialog" aria-modal="true" aria-labelledby="ft-global-order-hold-title">
            <button type="button" class="ft-global-order-hold-close" aria-label="Close">×</button>
            <div class="ft-global-order-hold-icon" aria-hidden="true"><span><i></i><i></i></span></div>
            <span class="ft-global-order-hold-eyebrow">ORDER ON HOLD</span>
            <h2 id="ft-global-order-hold-title">This activity is currently locked</h2>
            <p>The order is on hold, so no order or task activity can be performed right now. Unhold the order from Order Details before continuing.</p>
            <div class="ft-global-order-hold-note"><strong>What to do</strong><span>Open the order, review the hold information, then use <b>Unhold</b> if you are authorized.</span></div>
            <div class="ft-global-order-hold-actions"><button type="button" class="ft-global-order-hold-dismiss">Close</button></div>
        </section>
    `;

    const close = () => {
        backdrop.hidden = true;
        document.body.classList.remove('ft-global-order-hold-open');
        state.lastTrigger?.focus?.();
        state.lastTrigger = null;
    };

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop || event.target.closest('.ft-global-order-hold-close, .ft-global-order-hold-dismiss')) close();
    });
    document.addEventListener('keydown', (event) => {
        if (!backdrop.hidden && event.key === 'Escape') close();
    });

    document.body.appendChild(backdrop);
    state.modal = backdrop;
    return backdrop;
};

const show = () => {
    const modal = ensureModal();
    state.lastTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    modal.hidden = false;
    document.body.classList.add('ft-global-order-hold-open');
    window.requestAnimationFrame(() => modal.querySelector('.ft-global-order-hold-close')?.focus?.());
};

const failureContainsHoldMessage = (failure = {}) => {
    if (Number(failure.status || 0) !== 422) return false;

    const candidates = [
        failure.content,
        failure.response?.body,
        failure.response?.data,
        failure.body,
        failure.message,
    ];

    return candidates.some((value) => {
        if (value == null) return false;
        try {
            const text = typeof value === 'string' ? value : JSON.stringify(value);
            return String(text || '').includes(HOLD_BLOCK_MESSAGE);
        } catch (_) {
            return false;
        }
    });
};

export const bootOrderHoldRequestFeedback = () => {
    if (state.bound || !window.Livewire?.hook) return;
    state.bound = true;

    window.Livewire.hook('request', ({ fail }) => {
        fail((failure = {}) => {
            if (!failureContainsHoldMessage(failure)) return;

            failure.preventDefault?.();
            show();
        });
    });

    window.addEventListener('flowtrack:order-hold-request-blocked', show);
    window.addEventListener('flowtrack:order-held-blocked', show);
};
