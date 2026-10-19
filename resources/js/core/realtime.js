import { BROWSER_EVENTS, CONNECTION_STATES } from './events.js';
import { metaContent } from './meta.js';

class EventEmitter {
    constructor() {
        this.handlers = new Map();
    }

    bind(event, callback) {
        if (typeof callback !== 'function') return this;
        const callbacks = this.handlers.get(event) || [];
        if (!callbacks.includes(callback)) callbacks.push(callback);
        this.handlers.set(event, callbacks);
        return this;
    }

    unbind(event, callback) {
        if (!this.handlers.has(event)) return this;
        if (!callback) {
            this.handlers.delete(event);
            return this;
        }
        this.handlers.set(event, (this.handlers.get(event) || []).filter((item) => item !== callback));
        return this;
    }

    emit(event, payload) {
        (this.handlers.get(event) || []).forEach((callback) => {
            try { callback(payload); } catch (_) {}
        });
    }
}

class ReverbChannel extends EventEmitter {
    constructor(name) {
        super();
        this.name = name;
        this.subscribed = false;
        this.authenticating = false;
        this.retryTimer = null;
    }
}

class FlowTrackReverbClient {
    constructor(config) {
        this.__flowtrackReverbClient = true;
        this.config = config;
        this.socket = null;
        this.socketId = null;
        this.channels = new Map();
        this.retryTimer = null;
        this.retryAttempt = 0;
        this.closedIntentionally = false;
        this.lastActivityAt = Date.now();
        this.activityTimeoutMs = 120000;
        this.activityTimer = null;
        this.connection = new EventEmitter();
        this.connection.state = CONNECTION_STATES.INITIALIZED;
    }

    subscribe(name) {
        if (!name) return new ReverbChannel('');
        let channel = this.channels.get(name);
        if (!channel) {
            channel = new ReverbChannel(name);
            this.channels.set(name, channel);
        }
        if (this.connection.state === CONNECTION_STATES.CONNECTED) this.authorizeAndSubscribe(channel);
        return channel;
    }

    connect() {
        if (this.socket && [WebSocket.OPEN, WebSocket.CONNECTING].includes(this.socket.readyState)) return;

        this.closedIntentionally = false;
        this.setConnectionState(CONNECTION_STATES.CONNECTING);

        const { scheme, key, host, port } = this.config;
        const protocol = scheme === 'https' ? 'wss' : 'ws';
        const path = `/app/${encodeURIComponent(key)}`;
        const query = new URLSearchParams({
            protocol: '7',
            client: 'js',
            version: '8.5.0',
            flash: 'false',
        });
        const url = `${protocol}://${host}:${port}${path}?${query.toString()}`;

        try {
            this.socket = new WebSocket(url);
        } catch (error) {
            this.connection.emit(CONNECTION_STATES.ERROR, error);
            this.scheduleReconnect();
            return;
        }

        this.socket.addEventListener('open', () => {
            this.lastActivityAt = Date.now();
        });
        this.socket.addEventListener('message', (message) => {
            this.lastActivityAt = Date.now();
            this.handleMessage(message.data);
        });
        this.socket.addEventListener('error', (error) => {
            this.connection.emit(CONNECTION_STATES.ERROR, error);
        });
        this.socket.addEventListener('close', () => {
            this.stopActivityTimer();
            this.socketId = null;
            this.channels.forEach((channel) => {
                channel.subscribed = false;
                channel.authenticating = false;
                if (channel.retryTimer) window.clearTimeout(channel.retryTimer);
                channel.retryTimer = null;
            });
            this.setConnectionState(CONNECTION_STATES.DISCONNECTED);
            if (!this.closedIntentionally) this.scheduleReconnect();
        });
    }

    disconnect() {
        this.closedIntentionally = true;
        this.clearRetry();
        this.stopActivityTimer();
        this.socket?.close();
        this.setConnectionState(CONNECTION_STATES.DISCONNECTED);
    }

    setConnectionState(state) {
        if (this.connection.state === state) return;
        this.connection.state = state;
        this.connection.emit(state);
        this.connection.emit('state_change', { current: state });
        window.dispatchEvent(new CustomEvent(BROWSER_EVENTS.REALTIME_STATE, { detail: { state } }));
    }

    handleMessage(raw) {
        let packet;
        try { packet = JSON.parse(raw); } catch (_) { return; }

        const event = packet?.event;
        const data = this.parseData(packet?.data);

        if (event === 'pusher:connection_established') {
            this.socketId = data?.socket_id || null;
            const timeout = Number(data?.activity_timeout || 0);
            if (timeout > 0) this.activityTimeoutMs = Math.max(30000, timeout * 1000);
            this.retryAttempt = 0;
            this.clearRetry();
            this.setConnectionState(CONNECTION_STATES.CONNECTED);
            this.startActivityTimer();
            this.channels.forEach((channel) => this.authorizeAndSubscribe(channel));
            return;
        }

        if (event === 'pusher:ping') {
            this.send({ event: 'pusher:pong', data: {} });
            return;
        }

        if (event === 'pusher:error') {
            this.connection.emit(CONNECTION_STATES.ERROR, data);
            return;
        }

        const channelName = packet?.channel;
        if (!channelName) return;
        const channel = this.channels.get(channelName);
        if (!channel) return;

        if (event === 'pusher_internal:subscription_succeeded') {
            channel.subscribed = true;
            channel.authenticating = false;
            if (channel.retryTimer) window.clearTimeout(channel.retryTimer);
            channel.retryTimer = null;
            channel.emit('pusher:subscription_succeeded', data);
            return;
        }
        if (event === 'pusher_internal:subscription_count') return;
        channel.emit(event, data);
    }

    parseData(data) {
        if (typeof data !== 'string') return data ?? {};
        try { return JSON.parse(data); } catch (_) { return data; }
    }

    async authorizeAndSubscribe(channel) {
        if (!this.socketId || channel.subscribed || channel.authenticating) return;
        channel.authenticating = true;

        try {
            const body = new URLSearchParams({ socket_id: this.socketId, channel_name: channel.name });
            const response = await fetch(this.config.authEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-TOKEN': this.config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                body: body.toString(),
            });

            if (!response.ok) throw new Error(`Realtime authorization failed (${response.status}).`);
            const authorization = await response.json();
            if (!authorization?.auth) throw new Error('Realtime authorization did not return an auth signature.');

            this.send({
                event: 'pusher:subscribe',
                data: { auth: authorization.auth, channel: channel.name },
            });
        } catch (error) {
            channel.authenticating = false;
            channel.emit('pusher:subscription_error', error);
            this.connection.emit(CONNECTION_STATES.ERROR, error);
            this.connection.emit(CONNECTION_STATES.UNAVAILABLE, error);
            if (!channel.retryTimer) {
                channel.retryTimer = window.setTimeout(() => {
                    channel.retryTimer = null;
                    if (this.connection.state === CONNECTION_STATES.CONNECTED) this.authorizeAndSubscribe(channel);
                }, 5000);
            }
        }
    }

    send(packet) {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) return false;
        try {
            this.socket.send(JSON.stringify(packet));
            return true;
        } catch (_) {
            return false;
        }
    }

    startActivityTimer() {
        this.stopActivityTimer();
        const interval = Math.max(15000, Math.floor(this.activityTimeoutMs / 2));
        this.activityTimer = window.setInterval(() => {
            if (this.connection.state !== CONNECTION_STATES.CONNECTED) return;
            if (Date.now() - this.lastActivityAt >= this.activityTimeoutMs) {
                this.send({ event: 'pusher:ping', data: {} });
                this.lastActivityAt = Date.now();
            }
        }, interval);
    }

    stopActivityTimer() {
        if (this.activityTimer) window.clearInterval(this.activityTimer);
        this.activityTimer = null;
    }

    scheduleReconnect() {
        if (this.retryTimer || this.closedIntentionally) return;
        this.retryAttempt += 1;
        // Deterministic exponential schedule: .5s, 1s, 2s, 4s, 8s, then 15s cap.
        const delay = Math.min(15000, 500 * Math.pow(2, Math.min(5, this.retryAttempt - 1)));
        this.retryTimer = window.setTimeout(() => {
            this.retryTimer = null;
            this.connect();
        }, delay);
    }

    clearRetry() {
        if (this.retryTimer) window.clearTimeout(this.retryTimer);
        this.retryTimer = null;
    }
}

let client = null;

const resolveConfig = () => {
    const key = metaContent('flowtrack-reverb-key');
    const host = metaContent('flowtrack-reverb-host');
    const port = Number.parseInt(metaContent('flowtrack-reverb-port'), 10);
    const scheme = metaContent('flowtrack-reverb-scheme', 'https');
    const authEndpoint = metaContent('flowtrack-reverb-auth');
    const csrf = metaContent('csrf-token');
    if (!key || !host || !port || !authEndpoint || !csrf) return null;
    return { key, host, port, scheme, authEndpoint, csrf };
};

export const getRealtimeClient = () => client;

export const bootRealtimeClient = () => {
    if (client) {
        if ([CONNECTION_STATES.INITIALIZED, CONNECTION_STATES.DISCONNECTED].includes(client.connection.state)) client.connect();
        return client;
    }
    const config = resolveConfig();
    if (!config) return null;
    client = new FlowTrackReverbClient(config);
    client.connect();
    return client;
};

export { EventEmitter, ReverbChannel, FlowTrackReverbClient };
