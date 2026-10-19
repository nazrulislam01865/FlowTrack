import assert from 'node:assert/strict';
import { BROWSER_EVENTS, CONNECTION_STATES, LIVEWIRE_EVENTS, REALTIME_EVENTS } from '../../resources/js/core/events.js';
import { EventEmitter, FlowTrackReverbClient } from '../../resources/js/core/realtime.js';
import { createInlineEdit } from '../../resources/js/components/inline-edit.js';

// Event handlers must not multiply when feature boot methods run repeatedly.
const emitter = new EventEmitter();
let calls = 0;
const listener = () => { calls += 1; };
emitter.bind('event', listener).bind('event', listener);
emitter.emit('event');
assert.equal(calls, 1, 'duplicate bind must not register the same handler twice');
emitter.unbind('event', listener);
emitter.emit('event');
assert.equal(calls, 1, 'unbind must remove the handler');

// Reconnect timing must be deterministic and capped.
const delays = [];
globalThis.window = {
    setTimeout(callback, delay) {
        delays.push(delay);
        return { callback, delay };
    },
    clearTimeout() {},
};
const client = new FlowTrackReverbClient({});
for (let attempt = 0; attempt < 7; attempt += 1) {
    client.retryTimer = null;
    client.scheduleReconnect();
}
assert.deepEqual(delays, [500, 1000, 2000, 4000, 8000, 15000, 15000]);

// A confirmed task-assignee event must replace Alpine's preserved editor state
// immediately, without waiting for a Livewire morph or a page refresh.
const assigneeEditor = createInlineEdit({
    key: 'task-42-assignee',
    value: '7',
    display: 'Previous Assignee',
    avatarUrl: '/previous.png',
});
assigneeEditor.syncConfirmed('11', 'Action User', { avatarUrl: '/action-user.png' });
assert.equal(assigneeEditor.value, '11');
assert.equal(assigneeEditor.savedValue, '11');
assert.equal(assigneeEditor.display, 'Action User');
assert.equal(assigneeEditor.avatarUrl, '/action-user.png');

assert.equal(REALTIME_EVENTS.WORKSPACE_REFRESH, 'flowtrack.refresh');
assert.equal(REALTIME_EVENTS.NOTIFICATION, 'flowtrack.notification');
assert.equal(REALTIME_EVENTS.NOTIFICATION_STATE, 'flowtrack.notification-state');
assert.equal(LIVEWIRE_EVENTS.WORKSPACE_REFRESH, 'flowtrack-refresh');
assert.equal(LIVEWIRE_EVENTS.NOTIFICATION, 'flowtrack-notification');
assert.equal(BROWSER_EVENTS.REALTIME_STATE, 'flowtrack-realtime-state');
assert.equal(CONNECTION_STATES.CONNECTED, 'connected');

console.log('Phase 13 JavaScript unit contracts PASS');
