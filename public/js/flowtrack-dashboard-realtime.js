/* Release C: dashboard-only, scoped realtime reconciliation. No data or permissions
 * are taken from websocket payloads; mounted Livewire panels read authorized data.
 * Uses the application's existing realtime client and emits no new network polling.
 */
(() => {
    'use strict';
    const panelNames = Object.freeze({
        overview: 'dashboard.overview-panel',
        priority: 'dashboard.priority-panel',
        attention: 'dashboard.attention-panel',
        distribution: 'dashboard.distribution-panel',
        team: 'dashboard.team-panel',
        portfolio: 'dashboard.portfolio-panel',
        catalogue: 'dashboard.catalogue-panel',
        activity: 'dashboard.activity-panel',
        mentions: 'dashboard.tagged-comments',
    });
    const all = Object.keys(panelNames);
    const order = ['overview', 'priority', 'attention', 'distribution', 'team', 'portfolio', 'activity'];
    const task = ['overview', 'priority', 'attention', 'distribution', 'team', 'activity'];
    const inquiry = ['overview', 'priority', 'attention', 'distribution', 'portfolio', 'activity'];
    const catalogue = ['catalogue'];
    const map = Object.freeze({
        Activity: ['activity'],
        FlowJob: order, FlowJobItem: order, FlowJobMember: all,
        FlowJobPhaseHistory: order, OrderCollection: ['overview', 'priority', 'activity'],
        CollectionUpdate: ['overview', 'priority', 'activity'],
        Task: task, FlowTaskChecklistItem: task, TaskLink: task,
        FlowTaskComment: [...task, 'mentions'],
        Inquiry: inquiry, InquiryItem: inquiry, InquiryTask: inquiry,
        InquiryTaskLink: inquiry, InquiryDocument: inquiry,
        InquiryTaskComment: [...inquiry, 'mentions'],
        Document: [...task, 'portfolio', 'catalogue'],
        Invoice: ['overview', 'priority', 'activity'],
        InvoiceItem: ['overview', 'priority', 'activity'],
        Payment: ['overview', 'priority', 'activity'],
        MasterRecord: ['overview', ...catalogue], MasterValue: ['overview', ...catalogue],
        Client: all, ClientContact: ['portfolio', 'priority', 'activity'],
        ClientShippingAddress: ['portfolio', 'priority', 'activity'],
        Department: all, User: all, Role: all, Permission: all,
        RoleModuleAccess: all, Workspace: all, WorkspaceMembership: all,
        TaskPack: task, TaskPackItem: task, TaskPackTask: task,
        Workflow: order, WorkflowPhase: order, WorkflowTemplate: order,
    });
    const ready = () => document.querySelector('[data-dashboard-progressive="1"]') !== null;
    const queue = new Set();
    const seen = new Set();
    let pending = false;
    const normalizedReasons = (reason) => String(reason || '').split('|').filter(Boolean);
    const panelsFor = (reason) => {
        const subjects = normalizedReasons(reason);
        if (!subjects.length) return all;
        const requested = new Set();
        for (const part of subjects) {
            const type = part.split(':', 1)[0];
            const panels = map[type] || all; // unknown changes must not become stale
            for (const name of panels) requested.add(name);
        }
        return all.filter(name => requested.has(name));
    };
    const drain = () => {
        pending = false;
        if (!ready()) { queue.clear(); return; }
        // Only hydrated panels can refresh. Lazy panels will read the latest
        // state on first activation. Do not wake every offscreen placeholder.
        const livewire = window.Livewire;
        if (typeof livewire?.dispatchTo !== 'function') { queue.clear(); return; }
        for (const name of all) {
            if (!queue.has(name)) continue;
            const panel = document.querySelector(`[data-dashboard-panel="${name}"]`);
            if (!panel) continue;
            livewire.dispatchTo(panelNames[name], 'dashboard-scoped-refresh');
        }
        queue.clear();
    };
    const schedule = (panels, identity) => {
        if (identity && seen.has(identity)) return;
        if (identity) {
            seen.add(identity);
            if (seen.size > 120) seen.delete(seen.values().next().value);
        }
        for (const name of panels) queue.add(name);
        if (!pending) {
            pending = true;
            // Coalesce a transaction's workspace event and recipient notification
            // into one panel update; no repeated interval or network polling.
            window.setTimeout(drain, 150);
        }
    };
    const routeWorkspace = (reason, payload = {}) => {
        if (!ready()) return false;
        schedule(panelsFor(reason), payload?.event_id ? `workspace:${payload.event_id}` : null);
        return true; // suppress legacy broad Livewire dispatch on dashboard only
    };
    const routeNotification = (payload = {}) => {
        if (!ready()) return false;
        const type = String(payload?.type || '').toLowerCase();
        const panels = ['mention', 'mention_admin', 'comment'].includes(type)
            ? ['mentions', 'activity']
            : !type ? ['mentions']
            : type === 'assignment' ? ['priority', 'team']
            : []; // the matching workspace event updates record-based panels
        if (panels.length) schedule(panels, payload?.id ? `notice:${payload.id}` : null);
        return true;
    };
    const existing = window.FlowTrack && typeof window.FlowTrack === 'object' ? window.FlowTrack : {};
    existing.dashboard = { routeWorkspace, routeNotification, panelsFor };
    window.FlowTrack = existing;
})();
