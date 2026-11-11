import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/flowtrack-dashboard-realtime.js', import.meta.url), 'utf8');
let mounted = true;
const visible = new Set(['overview', 'priority', 'activity', 'catalogue', 'mentions']);
const deliveries = [];
const timers = [];
const window = {
    FlowTrack: {},
    Livewire: { dispatchTo: (name, event) => deliveries.push([name, event]) },
    setTimeout: fn => { timers.push(fn); return timers.length; },
};
const document = {
    querySelector(selector) {
        if (selector === '[data-dashboard-progressive="1"]') return mounted ? {} : null;
        const match = selector.match(/^\[data-dashboard-panel="([^"]+)"\]$/);
        return match && visible.has(match[1]) ? {} : null;
    },
};
vm.runInNewContext(source, { window, document });
const coordinator = window.FlowTrack.dashboard;
const flush = () => { while (timers.length) timers.shift()(); };
const clear = () => { deliveries.length = 0; };
const names = () => deliveries.map(([name]) => name);

assert.equal(coordinator.routeWorkspace('Activity:created', { event_id: '1' }), true);
flush();
assert.deepEqual(names(), ['dashboard.activity-panel'], 'Activity-only change must refresh only activity');
clear();
coordinator.routeWorkspace('Task:updated|Activity:created', { event_id: '2' });
coordinator.routeWorkspace('Task:updated|Activity:created', { event_id: '2' });
flush();
assert.deepEqual(names(), ['dashboard.overview-panel', 'dashboard.priority-panel', 'dashboard.activity-panel'], 'coalesce and omit unmounted panels');
clear();
coordinator.routeWorkspace('MasterRecord:updated', { event_id: '3' });
flush();
assert.deepEqual(names(), ['dashboard.overview-panel', 'dashboard.catalogue-panel'], 'catalogue also affects the active-products KPI');
clear();
coordinator.routeNotification({ id: 17, type: 'mention' });
flush();
assert.deepEqual(names(), ['dashboard.activity-panel', 'dashboard.tagged-comments']);
clear();
coordinator.routeNotification({ id: 18, type: 'update' });
flush();
assert.equal(deliveries.length, 0, 'regular notifications are handled by workspace refresh, not duplicated');
clear();
mounted = false;
assert.equal(coordinator.routeWorkspace('Task:updated', { event_id: '4' }), false, 'legacy pages must retain existing refresh path');
assert.equal(coordinator.routeNotification({ id: 19, type: 'mention' }), false);
flush();
assert.equal(deliveries.length, 0);
console.log('PASS: Release C dashboard scoped realtime routing, dedupe, lazy isolation and fallback');
