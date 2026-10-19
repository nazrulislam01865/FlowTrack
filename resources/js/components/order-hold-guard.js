const READ_ONLY_WIRE_METHODS = new Set([
    'setDetailTab',
    'setJobActivityTab',
    'setJobActivityPage',
    'selectOverviewPhase',
    'toggleJobPhase',
    'expandAllJobPhases',
    'collapseAllJobPhases',
    'openTask',
    'viewTask',
    'closeTask',
    'setTaskActivityTab',
    'setTaskActivityPage',
    'openOrderShipmentDetails',
    'closeOrderShipmentDetails',
    'openInvoiceAndPayment',
    'openLinkedRedoOrder',
]);

const wireMethodName = (element) => {
    const expression = element?.getAttribute?.('wire:click') || '';
    const match = String(expression).trim().match(/^\$?([A-Za-z_][A-Za-z0-9_]*)/);
    return match?.[1] || '';
};

const closestInteractive = (target) => target?.closest?.(
    'button, input, textarea, select, [contenteditable="true"], label, [role="button"], [wire\\:click]'
) || null;

const isTextEntry = (element) => {
    if (!element) return false;
    if (element.matches?.('textarea, select, [contenteditable="true"]')) return true;
    if (!element.matches?.('input')) return false;

    const type = String(element.getAttribute('type') || 'text').toLowerCase();
    return !['hidden'].includes(type);
};

const isLocalReadOnlyControl = (element) => {
    if (!element) return true;
    if (element.closest?.('[data-order-hold-allowed]')) return true;
    if (element.closest?.('.section-toggle, .ft-order-compact-toggle')) return true;
    if (element.closest?.('[data-order-hold-view-control]')) return true;

    const method = wireMethodName(element.closest?.('[wire\\:click]') || element);
    if (method) {
        if (method.startsWith('close')) return true;
        return READ_ONLY_WIRE_METHODS.has(method);
    }

    // Edit/form launchers are mutations even when Alpine only opens the editor
    // and the actual Livewire write happens later. Blocking at the first click
    // gives the user the hold explanation immediately instead of after submit.
    const editTrigger = element.closest?.(
        '.inline-edit, .ft-order-inline-name-trigger, .ft-detail-edit-button, .ft-property-edit-button, .ft-card-edit, [title^="Edit"], [aria-label^="Edit"], [data-rich-text-submit]'
    );
    if (editTrigger) return false;

    // A small set of local close/cancel controls is safe. This matters if a
    // previously opened disclosure/modal remains visible while hold state is
    // refreshed by Livewire.
    const controlText = String(element.innerText || element.value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (['close', 'cancel', 'keep order', 'hide activity', 'show activity', 'view details', 'hide details'].includes(controlText)) {
        return true;
    }
    if (element.matches?.('.close, [aria-label^="Close"]')) return true;

    return false;
};

const attemptedActionLabel = (element) => {
    const explicit = element?.closest?.('[data-order-hold-action-label]')?.getAttribute?.('data-order-hold-action-label');
    if (explicit) return explicit;

    const text = String(element?.innerText || element?.value || '').replace(/\s+/g, ' ').trim();
    if (text && text.length <= 70) return text;

    const aria = element?.getAttribute?.('aria-label') || element?.getAttribute?.('title');
    return String(aria || '').trim();
};

export const createOrderHoldGuard = (config = {}) => ({
    held: Boolean(config.held),
    holdBlockedOpen: false,
    blockedAction: '',

    showHoldBlocked(action = '') {
        if (!this.held) return;
        this.blockedAction = String(action || '').trim();
        this.holdBlockedOpen = true;
        this.$nextTick?.(() => this.$refs?.orderHoldBlockedClose?.focus?.());
    },

    closeHoldBlocked() {
        this.holdBlockedOpen = false;
        this.blockedAction = '';
    },

    guardInteraction(event) {
        if (!this.held || this.holdBlockedOpen) return;

        const interactive = closestInteractive(event.target);
        if (!interactive || interactive.closest?.('[data-order-hold-allowed]')) return;

        const eventType = String(event.type || '');
        const form = event.target?.closest?.('form');
        const shouldBlock = eventType === 'submit'
            || isTextEntry(interactive)
            || (!isLocalReadOnlyControl(interactive) && (
                interactive.matches?.('button, [role="button"], [wire\\:click]')
                || interactive.closest?.('[wire\\:click]')
                || form
            ));

        if (!shouldBlock) return;

        event.preventDefault?.();
        event.stopPropagation?.();
        event.stopImmediatePropagation?.();
        interactive.blur?.();
        this.showHoldBlocked(attemptedActionLabel(interactive));
    },
});
