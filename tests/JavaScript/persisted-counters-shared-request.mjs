import assert from 'node:assert/strict';

let fetchCalls = 0;
let now = 2_000_000;
const realDateNow = Date.now;
Date.now = () => now;

globalThis.document = {
    hidden: false,
    querySelector(selector) {
        if (selector === 'meta[name="flowtrack-notification-count-url"]') {
            return { content: '/notifications/unread-count' };
        }
        if (selector === 'meta[name="flowtrack-reverb-workspace-channel"]') return null;
        return null;
    },
    querySelectorAll() { return []; },
    getElementById() { return null; },
    createElement() { return { remove() {}, appendChild() {} }; },
    addEventListener() {},
};

globalThis.window = {
    Livewire: { dispatch() {}, on() {} },
    dispatchEvent() {},
    setInterval,
    clearInterval,
    setTimeout,
    clearTimeout,
    addEventListener() {},
};

globalThis.fetch = async () => {
    fetchCalls += 1;
    await new Promise((resolve) => setTimeout(resolve, 5));
    return {
        ok: true,
        async json() {
            return {
                count: 3,
                my_work_count: 4,
                cancelled_order_count: 2,
                data_version: '9',
                latest: { id: 88 },
            };
        },
    };
};

try {
    const { syncUnreadCount } = await import('../../resources/js/features/notifications.js');
    const { syncWorkspaceState } = await import('../../resources/js/features/workspace-refresh.js');

    await Promise.all([
        syncUnreadCount(),
        syncWorkspaceState(),
        syncUnreadCount(),
        syncWorkspaceState(),
    ]);
    assert.equal(fetchCalls, 1, 'notification and workspace consumers must share one HTTP request');

    await syncWorkspaceState();
    assert.equal(fetchCalls, 1, 'shared cooldown must reuse the last counter payload');

    now += 1501;
    await syncWorkspaceState();
    assert.equal(fetchCalls, 2, 'a later workspace refresh must still reach the server');

    console.log('Persisted counter shared request PASS');
} finally {
    Date.now = realDateNow;
}
