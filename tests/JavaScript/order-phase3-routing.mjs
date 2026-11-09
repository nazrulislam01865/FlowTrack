import assert from 'node:assert/strict';
import { LIVEWIRE_EVENTS } from '../../resources/js/core/events.js';
import { routeOrderNotification, routeOrderWorkspaceRefresh } from '../../resources/js/features/order-realtime.js';

const calls = [];
let targeted = true;
let active = true;
let loaded = new Set(['products', 'workflow', 'attachments', 'activity']);
let orderId = 77;
let workspaceId = 1;

globalThis.document = {
    querySelector(selector) {
        if (selector.includes('data-flowtrack-order-targeted=')) {
            if (!active || !targeted) return null;
            return {
                getAttribute(name) {
                    if (name === 'data-flowtrack-order-id') return String(orderId);
                    if (name === 'data-flowtrack-workspace-id') return String(workspaceId);
                    return null;
                },
            };
        }
        const match = selector.match(/data-flowtrack-order-section="([^"]+)"/);
        return match && loaded.has(match[1]) ? {} : null;
    },
};
globalThis.window = {
    Livewire: {
        dispatchTo(component, event, payload) { calls.push({ component, event, payload }); },
    },
};

const reset = () => { calls.length = 0; };

assert.equal(routeOrderNotification({ job_id: 8, workspace_id: 1 }), true);
assert.deepEqual(calls, [], 'a different Order must not trigger any Livewire update');
assert.equal(routeOrderNotification({ workspace_id: 1 }), true);
assert.deepEqual(calls, [], 'generic notification must update badge only on Order detail');
assert.equal(routeOrderNotification({ job_id: 77, workspace_id: 2 }), true);
assert.deepEqual(calls, [], 'a foreign workspace payload must not refresh this Order');

assert.equal(routeOrderNotification({ job_id: 77, workspace_id: 1 }), true);
assert.equal(calls.length, 5, 'same-Order notification refreshes current summary and loaded sections');
assert.ok(calls.every((c) => c.event === LIVEWIRE_EVENTS.ORDER_CHANGED && c.payload.orderId === 77));
reset();

loaded = new Set(['workflow']);
assert.equal(routeOrderNotification({ job_id: 77 }), true);
assert.deepEqual(calls.map((c) => c.component), ['jobs.index', 'jobs.order-workflow-section'],
    'lazy sections not yet loaded must never receive refresh POSTs');
reset();

assert.equal(routeOrderWorkspaceRefresh({ order_id: 89, workspace_id: 1, reason: 'Task:updated' }), true);
assert.equal(calls.length, 0, 'unrelated Order workspace change must not refresh');
assert.equal(routeOrderWorkspaceRefresh({ order_id: 77, workspace_id: 1, reason: 'Task:updated' }), true);
assert.deepEqual(calls.map((c) => c.component), ['jobs.index', 'jobs.order-workflow-section']);
reset();

loaded = new Set(['products', 'workflow', 'attachments', 'activity']);
assert.equal(routeOrderWorkspaceRefresh({ order_id: 77, workspace_id: 1, reason: 'Document:updated' }), true);
assert.deepEqual(calls.map((c) => c.component), [
    'jobs.index', 'jobs.order-workflow-section', 'jobs.order-attachments-section', 'jobs.order-activity-section',
]);
reset();

// Multiple model writes within one DB transaction retain the union of their
// affected sections instead of dropping earlier subjects.
assert.equal(routeOrderWorkspaceRefresh({ order_id: 77, reason: 'Task:updated|Document:created|Activity:created' }), true);
assert.deepEqual(calls.map((c) => c.component), [
    'jobs.index', 'jobs.order-workflow-section', 'jobs.order-attachments-section', 'jobs.order-activity-section',
]);
reset();

assert.equal(routeOrderNotification({ job_id: 77, type: 'mention' }), true);
assert.deepEqual(calls.map((c) => c.component), ['jobs.order-activity-section']);
reset();
assert.equal(routeOrderNotification({ job_id: 77, type: 'mention', task_id: 35 }), true);
assert.deepEqual(calls.map((c) => c.component), [
    'jobs.index', 'jobs.order-workflow-section', 'jobs.order-activity-section',
]);
reset();

// Order membership can revoke access to any ready child. Every section
// must reauthorize, rather than retaining stale data in a visible component.
assert.equal(routeOrderWorkspaceRefresh({ order_id: 77, workspace_id: 1, reason: 'FlowJobMember:updated' }), true);
assert.deepEqual(calls.map((c) => c.component), [
    'jobs.index', 'jobs.order-products-section', 'jobs.order-workflow-section',
    'jobs.order-attachments-section', 'jobs.order-activity-section',
]);
reset();

// Changes to an Order can affect fields used by the Products section (owner),
// so a scoped Order event must update every already-ready section.
assert.equal(routeOrderWorkspaceRefresh({ order_id: 77, reason: 'FlowJob:updated' }), true);
assert.equal(calls.length, 5);
reset();

assert.equal(routeOrderWorkspaceRefresh({ order_id: 99, reason: 'FlowJobMember:updated' }), true);
assert.deepEqual(calls, [], 'other-order permission changes must not touch this Order');

// No supported Order ID or subject type means we must preserve legacy routing.
assert.equal(routeOrderWorkspaceRefresh({ order_id: 77, reason: 'UnknownModel:updated' }), true);
assert.equal(calls.length, 5, 'unrecognized scoped model events must refresh all ready sections');
reset();

assert.equal(routeOrderWorkspaceRefresh({ order_id: null, reason: 'Task:updated' }), false,
    'ambiguous workspace changes require the unchanged broad refresh fallback');
assert.equal(calls.length, 0);

delete window.Livewire.dispatchTo;
assert.equal(routeOrderNotification({ job_id: 77 }), false,
    'without dispatchTo, caller must fall back to its previous global notification dispatch');
reset();

active = false;
assert.equal(routeOrderNotification({ job_id: 77 }), false, 'non-detail pages retain existing notification routing');
active = true;
targeted = false;
assert.equal(routeOrderWorkspaceRefresh({ order_id: 88 }), false, 'feature flag off restores broad routing');

targeted = true;
orderId = 0;
assert.equal(routeOrderNotification({ job_id: 77 }), false, 'invalid detail identity falls back safely');
console.log('Phase 3 Order realtime routing PASS');
