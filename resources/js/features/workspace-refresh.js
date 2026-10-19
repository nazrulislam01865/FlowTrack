import { CONNECTION_STATES, LIVEWIRE_EVENTS, REALTIME_EVENTS } from '../core/events.js';
import { metaContent } from '../core/meta.js';
import { syncPersistedCounters } from '../core/persisted-counters.js';

const state = {
    version: null,
    channel: null,
    channelName: null,
    realtime: null,
    retryTimer: null,
    retryCount: 0,
    pollTimer: null,
    pollInterval: null,
    bound: false,
    connectionBound: false,
};

const workspaceChannelName = () => metaContent('flowtrack-reverb-workspace-channel') || null;
const dispatchRefresh = (reason = null) => window.Livewire?.dispatch?.(LIVEWIRE_EVENTS.WORKSPACE_REFRESH, { reason });

export const syncWorkspaceState = async ({ dispatchOnVersionChange = true } = {}) => {
    if (!metaContent('flowtrack-notification-count-url') || document.hidden) return;

    const data = await syncPersistedCounters();
    if (!data) return;

    const nextVersion = String(data?.data_version ?? '1');
    if (state.version === null) {
        state.version = nextVersion;
        return;
    }
    if (nextVersion !== state.version) {
        state.version = nextVersion;
        if (dispatchOnVersionChange) dispatchRefresh();
    }
};

const stopPolling = () => {
    if (state.pollTimer) window.clearInterval(state.pollTimer);
    state.pollTimer = null;
    state.pollInterval = null;
};

const startPolling = (intervalMs = 30000) => {
    if (state.pollTimer && state.pollInterval === intervalMs) return;
    stopPolling();
    state.pollInterval = intervalMs;
    syncWorkspaceState();
    state.pollTimer = window.setInterval(() => syncWorkspaceState(), intervalMs);
};

const clearRetry = () => {
    if (state.retryTimer) window.clearTimeout(state.retryTimer);
    state.retryTimer = null;
};

const scheduleRetry = (realtime) => {
    if (state.retryTimer || state.retryCount >= 15) return;
    state.retryCount += 1;
    const delay = Math.min(5000, 400 * state.retryCount);
    state.retryTimer = window.setTimeout(() => {
        state.retryTimer = null;
        subscribeWorkspace(realtime);
    }, delay);
};

const bindConnectionLifecycle = (realtime) => {
    if (!realtime?.connection?.bind || (state.realtime === realtime && state.connectionBound)) return;
    state.connectionBound = true;
    realtime.connection.bind(CONNECTION_STATES.CONNECTED, () => {
        startPolling(60000);
        syncWorkspaceState();
    });
    [CONNECTION_STATES.DISCONNECTED, CONNECTION_STATES.UNAVAILABLE, CONNECTION_STATES.FAILED].forEach((connectionState) => {
        realtime.connection.bind(connectionState, () => startPolling(30000));
    });
    realtime.connection.bind(CONNECTION_STATES.ERROR, () => {
        if (realtime.connection?.state !== CONNECTION_STATES.CONNECTED) startPolling(30000);
    });
};

export const subscribeWorkspace = (realtime = null) => {
    const channelName = workspaceChannelName();
    if (!channelName) {
        startPolling();
        return;
    }
    if (!realtime) {
        scheduleRetry(realtime);
        startPolling();
        return;
    }

    if (state.realtime === realtime && state.channel && state.channelName === channelName) {
        startPolling(realtime.connection?.state === CONNECTION_STATES.CONNECTED ? 60000 : 30000);
        return;
    }

    clearRetry();
    state.retryCount = 0;
    state.realtime = realtime;
    state.connectionBound = false;
    state.channelName = channelName;
    state.channel = realtime.subscribe(channelName);
    state.channel.bind(REALTIME_EVENTS.WORKSPACE_REFRESH, (payload = {}) => {
        const incomingVersion = payload?.version == null ? null : String(payload.version);
        if (incomingVersion) state.version = incomingVersion;
        dispatchRefresh(payload?.reason == null ? null : String(payload.reason));
        syncWorkspaceState({ dispatchOnVersionChange: false });
    });
    bindConnectionLifecycle(realtime);

    if (realtime.connection?.state === CONNECTION_STATES.CONNECTED) {
        startPolling(60000);
        syncWorkspaceState();
    } else {
        startPolling(30000);
    }
};

export const bootWorkspaceRefresh = (realtime = null) => {
    syncWorkspaceState();
    subscribeWorkspace(realtime);
    if (state.bound) return;
    state.bound = true;
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) syncWorkspaceState();
    });
    window.addEventListener('focus', () => syncWorkspaceState());
};
