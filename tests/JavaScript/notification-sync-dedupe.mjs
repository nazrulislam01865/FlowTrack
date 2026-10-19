import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

let fetchCalls = 0;
let now = 1_000_000;
const realDateNow = Date.now;
Date.now = () => now;

globalThis.document = {
    hidden: false,
    querySelector(selector) {
        if (selector === 'meta[name="flowtrack-notification-count-url"]') {
            return { content: '/notifications/unread-count' };
        }
        return null;
    },
    querySelectorAll() { return []; },
    getElementById() { return null; },
    createElement() { return { remove() {}, appendChild() {} }; },
};
globalThis.window = {
    Livewire: { dispatch() {}, on() {} },
    setInterval,
    clearInterval,
    dispatchEvent() {},
};
globalThis.fetch = async () => {
    fetchCalls++;
    await new Promise((resolve) => setTimeout(resolve, 5));
    return { ok: true, async json() { return { count: 0, latest: { id: 1 } }; } };
};

try {
    const moduleUrl = pathToFileURL(new URL('../../resources/js/features/notifications.js', import.meta.url).pathname).href;
    const { syncUnreadCount } = await import(`${moduleUrl}?dedupe-test=${Date.now()}`);

    await Promise.all(Array.from({ length: 4 }, () => syncUnreadCount()));
    assert.equal(fetchCalls, 1, 'concurrent unread sync requests must be coalesced');

    await syncUnreadCount();
    assert.equal(fetchCalls, 1, 'lifecycle bursts inside the cooldown must not refetch');

    now += 1501;
    await syncUnreadCount();
    assert.equal(fetchCalls, 2, 'a later sync must still be allowed');

    console.log('Notification unread sync dedupe PASS');
} finally {
    Date.now = realDateNow;
}
