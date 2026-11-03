import assert from 'node:assert/strict';
import { CONNECTION_STATES, LIVEWIRE_EVENTS, REALTIME_EVENTS } from '../../resources/js/core/events.js';
import { bootNotifications } from '../../resources/js/features/notifications.js';
import { subscribeWorkspace, syncWorkspaceState } from '../../resources/js/features/workspace-refresh.js';

const events = [];
const channels = new Map();
const lifecycle = new Map();
let serverVersion = 'v1';
let notificationId = 1;
let fetchCount = 0;
const loaded = new Set(['workflow', 'activity']);
let currentOrderId = 77;
let targeted = true;

globalThis.document = {
    hidden: false,
    querySelector(selector) {
        if (selector === 'meta[name="flowtrack-notification-count-url"]') return { content: '/notifications/unread-count' };
        if (selector === 'meta[name="flowtrack-reverb-channel"]') return { content: 'private-user-channel' };
        if (selector === 'meta[name="flowtrack-reverb-workspace-channel"]') return { content: 'workspace-channel' };
        if (selector.includes('data-flowtrack-order-targeted=')) {
            return targeted ? {
                getAttribute: (key) => key === 'data-flowtrack-order-id' ? String(currentOrderId) : '1',
            } : null;
        }
        const match = selector.match(/data-flowtrack-order-section="([^"]+)"/);
        return match && loaded.has(match[1]) ? {} : null;
    },
    querySelectorAll() { return []; },
    getElementById() { return null; },
    createElement() { return { appendChild() {}, remove() {} }; },
};
globalThis.window = {
    Livewire: {
        on() {},
        dispatch(event, payload = {}) { events.push({ kind: 'broad', event, payload }); },
        dispatchTo(component, event, payload) { events.push({ kind: 'target', component, event, payload }); },
    },
    dispatchEvent() {},
    setInterval: () => 1,
    clearInterval() {},
    setTimeout: () => 1,
    clearTimeout() {},
};
globalThis.fetch = async () => {
    fetchCount++;
    return {
        ok: true,
        async json() {
            return {
                count: 2,
                my_work_count: 0,
                cancelled_order_count: 0,
                latest: { id: notificationId },
                data_version: serverVersion,
            };
        },
    };
};

const realtime = {
    connection: {
        state: CONNECTION_STATES.CONNECTED,
        bind(name, listener) { lifecycle.set(name, listener); },
    },
    subscribe(name) {
        if (!channels.has(name)) channels.set(name, new Map());
        return { bind(event, listener) { channels.get(name).set(event, listener); } };
    },
};
const send = (channel, event, payload) => {
    const handler = channels.get(channel)?.get(event);
    assert.ok(handler, `missing ${event} handler`);
    handler(payload);
};
const flush = () => new Promise((resolve) => setImmediate(resolve));
const clear = () => { events.length = 0; };

bootNotifications(realtime);
subscribeWorkspace(realtime);
await syncWorkspaceState({ force: true });
clear();

send('private-user-channel', REALTIME_EVENTS.NOTIFICATION_STATE, { unread_count: 7 });
assert.deepEqual(events, [], 'read/unread state change must not load Order Details components');

send('private-user-channel', REALTIME_EVENTS.NOTIFICATION, { id: 2, job_id: 22, workspace_id: 1, unread_count: 8 });
assert.deepEqual(events, [], 'different Order notification must not hit Livewire');

send('private-user-channel', REALTIME_EVENTS.NOTIFICATION, { id: 3, job_id: 77, workspace_id: 1, unread_count: 9 });
assert.deepEqual(events.map((call) => call.component), [
    'jobs.index', 'jobs.order-workflow-section', 'jobs.order-activity-section',
]);
clear();

serverVersion = 'v2';
send('workspace-channel', REALTIME_EVENTS.WORKSPACE_REFRESH, {
    version: 'v2', workspace_id: 1, order_id: 88, reason: 'Task:updated',
});
await flush();
assert.deepEqual(events, [], 'unrelated workspace event + current version must cause zero Livewire updates');
clear();

serverVersion = 'v3';
send('workspace-channel', REALTIME_EVENTS.WORKSPACE_REFRESH, {
    version: 'v3', workspace_id: 1, order_id: 77, reason: 'Activity:created',
});
await flush();
assert.deepEqual(events.map((event) => event.component), ['jobs.order-activity-section']);
clear();

serverVersion = 'v5';
send('workspace-channel', REALTIME_EVENTS.WORKSPACE_REFRESH, {
    version: 'v4', workspace_id: 1, order_id: 99, reason: 'Task:updated',
});
await flush();
assert.equal(events.filter((call) => call.kind === 'broad' && call.event === LIVEWIRE_EVENTS.WORKSPACE_REFRESH).length, 1,
    'a skipped coalesced workspace update requires one conservative broad reconciliation');
clear();

serverVersion = 'v6';
await syncWorkspaceState({ force: true });
assert.equal(events.filter((call) => call.kind === 'broad' && call.event === LIVEWIRE_EVENTS.WORKSPACE_REFRESH).length, 1,
    'disconnect/reconnect version drift must trigger the existing global recovery');
clear();

targeted = false;
send('private-user-channel', REALTIME_EVENTS.NOTIFICATION, { id: 7, job_id: 99, unread_count: 10 });
assert.equal(events.length, 1);
assert.equal(events[0].event, LIVEWIRE_EVENTS.NOTIFICATION,
    'flag off and other pages retain the previous generic notification behavior');
assert.ok(fetchCount > 0);
console.log('Phase 3 realtime transport and reconnect PASS');
