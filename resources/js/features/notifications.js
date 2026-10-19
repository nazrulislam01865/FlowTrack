import { BROWSER_EVENTS, CONNECTION_STATES, LIVEWIRE_EVENTS, REALTIME_EVENTS } from '../core/events.js';
import { metaContent } from '../core/meta.js';
import { syncPersistedCounters } from '../core/persisted-counters.js';
import { setNotificationUnreadCount } from '../components/sidebar-counters.js';

const state = {
    realtime: null,
    realtimeStateBound: false,
    notificationChannel: null,
    notificationChannelName: null,
    connected: false,
    unreadTimer: null,
    listenerBound: false,
    latestNotificationId: null,
    initialNotificationSynced: false,
};
const unreadFallbackIntervalMs = 60000;

export const syncUnreadCount = async () => {
    if (!metaContent('flowtrack-notification-count-url') || document.hidden) return;

    // Notification, workspace and persisted-sidebar refreshes all share the
    // same HTTP source. The shared request layer coalesces lifecycle bursts
    // so those consumers do not issue duplicate unread-count requests.
    const data = await syncPersistedCounters();
    if (!data) return;

    const latestId = Number.parseInt(String(data?.latest?.id ?? 0), 10) || 0;
    if (!state.initialNotificationSynced) {
        state.initialNotificationSynced = true;
        state.latestNotificationId = latestId || null;
        return;
    }
    if (latestId > (state.latestNotificationId || 0)) {
        state.latestNotificationId = latestId;
        window.Livewire?.dispatch?.(LIVEWIRE_EVENTS.NOTIFICATION);
    }
};

const stopUnreadFallback = () => {
    if (state.unreadTimer) window.clearInterval(state.unreadTimer);
    state.unreadTimer = null;
};

const startUnreadFallback = () => {
    if (state.unreadTimer) return;
    syncUnreadCount();
    state.unreadTimer = window.setInterval(syncUnreadCount, unreadFallbackIntervalMs);
};

export const bootLivewireNotificationEvents = () => {
    if (state.listenerBound || !window.Livewire?.on) return;
    state.listenerBound = true;
    window.Livewire.on(LIVEWIRE_EVENTS.UNREAD_CLEARED, () => setNotificationUnreadCount(0));
    window.Livewire.on(LIVEWIRE_EVENTS.UNREAD_COUNT, (event) => setNotificationUnreadCount(event?.count ?? event?.[0]?.count ?? 0));
};

const bindRealtimeConnectionState = (realtime) => {
    if (!realtime?.connection?.bind || (state.realtime === realtime && state.realtimeStateBound)) return false;
    state.realtime = realtime;
    state.realtimeStateBound = true;

    realtime.connection.bind(CONNECTION_STATES.CONNECTED, () => {
        state.connected = true;
        stopUnreadFallback();
        syncUnreadCount();
    });
    [CONNECTION_STATES.DISCONNECTED, CONNECTION_STATES.UNAVAILABLE, CONNECTION_STATES.FAILED].forEach((connectionState) => {
        realtime.connection.bind(connectionState, () => {
            state.connected = false;
            startUnreadFallback();
        });
    });
    realtime.connection.bind(CONNECTION_STATES.ERROR, () => {
        if (!state.connected) startUnreadFallback();
    });

    if (realtime.connection.state === CONNECTION_STATES.CONNECTED) {
        state.connected = true;
        stopUnreadFallback();
        syncUnreadCount();
    } else {
        startUnreadFallback();
    }
    return true;
};

const bindNotificationChannel = (realtime) => {
    const channelName = metaContent('flowtrack-reverb-channel');
    if (!realtime || !channelName) return;
    if (state.notificationChannel && state.notificationChannelName === channelName && state.realtime === realtime) return;

    state.notificationChannelName = channelName;
    state.notificationChannel = realtime.subscribe(channelName);
    state.notificationChannel.bind(REALTIME_EVENTS.NOTIFICATION, (payload = {}) => {
        const current = Number.parseInt(document.querySelector('#sidebar .nav-btn[href*="/notifications"] .nav-badge')?.textContent || '0', 10) || 0;
        setNotificationUnreadCount(payload?.unread_count ?? current + 1);
        window.Livewire?.dispatch?.(LIVEWIRE_EVENTS.NOTIFICATION);
        window.dispatchEvent(new CustomEvent(BROWSER_EVENTS.REALTIME_NOTIFICATION, { detail: payload }));
    });
    state.notificationChannel.bind(REALTIME_EVENTS.NOTIFICATION_STATE, (payload = {}) => {
        setNotificationUnreadCount(payload?.unread_count ?? 0);
        window.Livewire?.dispatch?.(LIVEWIRE_EVENTS.NOTIFICATION);
        window.dispatchEvent(new CustomEvent(BROWSER_EVENTS.REALTIME_NOTIFICATION_STATE, { detail: payload }));
    });
};

export const bootNotifications = (realtime = null) => {
    bootLivewireNotificationEvents();
    if (realtime) {
        bindRealtimeConnectionState(realtime);
        bindNotificationChannel(realtime);
    } else {
        startUnreadFallback();
    }
};
