import { CONNECTION_STATES } from '../core/events.js';
import { metaContent } from '../core/meta.js';

const state = {
    client: null,
    connectedOnce: false,
    lastSent: new Map(),
};

const send = (event) => {
    const endpoint = metaContent('flowtrack-realtime-telemetry-url');
    const csrf = metaContent('csrf-token');
    if (!endpoint || !csrf) return;

    const now = Date.now();
    const last = state.lastSent.get(event) || 0;
    if (now - last < 15000) return;
    state.lastSent.set(event, now);

    fetch(endpoint, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        body: JSON.stringify({ event }),
    }).catch(() => {});
};

export const bootRealtimeTelemetry = (client) => {
    if (!client?.connection || state.client === client) return;
    state.client = client;

    client.connection.bind('state_change', ({ current } = {}) => {
        if (current === CONNECTION_STATES.CONNECTED) {
            send(state.connectedOnce ? 'reconnect' : 'connected');
            state.connectedOnce = true;
        } else if (current === CONNECTION_STATES.DISCONNECTED && state.connectedOnce) {
            send('disconnected');
        }
    });
    client.connection.bind(CONNECTION_STATES.ERROR, () => send('error'));
    client.connection.bind(CONNECTION_STATES.UNAVAILABLE, () => send('unavailable'));
};
