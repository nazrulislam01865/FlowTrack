import { LIVEWIRE_EVENTS } from '../core/events.js';

const componentBySection = Object.freeze({
    summary: 'jobs.index',
    products: 'jobs.order-products-section',
    workflow: 'jobs.order-workflow-section',
    attachments: 'jobs.order-attachments-section',
    activity: 'jobs.order-activity-section',
});

const allSections = Object.freeze(Object.keys(componentBySection));

const activeOrder = () => {
    const root = document.querySelector('[data-flowtrack-order-targeted="1"][data-flowtrack-order-id]');
    if (!root) return null;

    const orderId = Number(root.getAttribute('data-flowtrack-order-id'));
    const workspaceId = Number(root.getAttribute('data-flowtrack-workspace-id'));
    if (!Number.isSafeInteger(orderId) || orderId <= 0) return null;

    return { orderId, workspaceId };
};

const refreshSections = (orderId, sections) => {
    if (typeof window.Livewire?.dispatchTo !== 'function') return false;

    for (const section of sections) {
        const component = componentBySection[section];
        if (!component) continue;
        // A lazy section has not loaded data yet and will read fresh DB state
        // when it opens. Do not cause unnecessary Livewire POSTs for loaders.
        if (section !== 'summary' && !document.querySelector(
            `[data-flowtrack-order-section="${section}"][data-flowtrack-section-ready="1"]`,
        )) continue;
        window.Livewire.dispatchTo(component, LIVEWIRE_EVENTS.ORDER_CHANGED, { orderId });
    }
    return true;
};

const workspaceSectionsForSubject = (reason) => {
    const subject = String(reason ?? '').split(':', 1)[0];
    if (subject === 'Task' || subject === 'FlowJobPhaseHistory') {
        return ['summary', 'workflow', 'activity'];
    }
    // Membership changes can revoke visibility, and parent Order fields can
    // affect child views (e.g. the Products owner). Reauthorize all ready
    // sections rather than leave previously rendered data visible/stale.
    if (subject === 'FlowJobMember' || subject === 'FlowJob') return allSections;
    if (subject === 'FlowJobItem') return ['summary', 'products', 'workflow', 'activity'];
    if (subject === 'Document') return ['summary', 'workflow', 'attachments', 'activity'];
    if (subject === 'Invoice') return ['summary', 'activity'];
    if (subject === 'Activity') return ['activity'];
    // An Order's own changed fields can affect every detail card.
    return allSections;
};

const workspaceSections = (reason) => {
    // The server merges all model subjects written in a single transaction.
    // Union their required children rather than ignoring an earlier Task or
    // Document update when the final observer event happens to be Activity.
    const subjects = String(reason ?? '').split('|').filter(Boolean);
    if (!subjects.length) return allSections;
    const required = new Set();
    for (const subject of subjects) {
        for (const section of workspaceSectionsForSubject(subject)) required.add(section);
    }
    return allSections.filter((section) => required.has(section));
};

const notificationSections = (payload) => {
    const type = String(payload?.type ?? '').toLowerCase();
    if (type === 'mention' || type === 'mention_admin') {
        // Task comment mentions can activate conditional workflow evidence.
        return Number(payload?.task_id) > 0
            ? ['summary', 'workflow', 'activity']
            : ['activity'];
    }
    // No reliable resource classification: assignments can change membership,
    // and generic notifications can indicate financial or Order-wide changes.
    return allSections;
};

const sameWorkspace = (current, payload) => {
    if (payload?.workspace_id == null) return true; // legacy payload
    return Number(payload.workspace_id) === current.workspaceId;
};

/**
 * Returns true if the notification was handled without legacy global refresh.
 * A private notification with no Order is never an Order Details invalidation.
 */
export const routeOrderNotification = (payload = {}) => {
    const current = activeOrder();
    if (!current) return false;
    if (!sameWorkspace(current, payload)) return true;

    const incomingId = Number(payload?.job_id);
    if (!Number.isSafeInteger(incomingId) || incomingId <= 0 || incomingId !== current.orderId) {
        return true;
    }

    return refreshSections(current.orderId, notificationSections(payload));
};

/**
 * Unknown workspace subjects retain the original broad refresh; never guess
 * an Order ID or rely on an unverified reason string to drop an update.
 */
export const routeOrderWorkspaceRefresh = (payload = {}) => {
    const current = activeOrder();
    if (!current) return false;
    if (!sameWorkspace(current, payload)) return true;

    const incomingId = Number(payload?.order_id);
    if (!Number.isSafeInteger(incomingId) || incomingId <= 0) return false;
    if (incomingId !== current.orderId) return true;

    return refreshSections(current.orderId, workspaceSections(payload?.reason));
};
