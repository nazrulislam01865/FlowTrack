/**
 * Browser, Livewire and Reverb event contracts shared by FlowTrack.
 * Keep event names here so producers and consumers cannot silently drift.
 */
export const REALTIME_EVENTS = Object.freeze({
    WORKSPACE_REFRESH: 'flowtrack.refresh',
    NOTIFICATION: 'flowtrack.notification',
    NOTIFICATION_STATE: 'flowtrack.notification-state',
});

export const LIVEWIRE_EVENTS = Object.freeze({
    WORKSPACE_REFRESH: 'flowtrack-refresh',
    NOTIFICATION: 'flowtrack-notification',
    UNREAD_CLEARED: 'flowtrack-unread-cleared',
    UNREAD_COUNT: 'flowtrack-unread-count',
});

export const BROWSER_EVENTS = Object.freeze({
    REALTIME_STATE: 'flowtrack-realtime-state',
    REALTIME_NOTIFICATION: 'flowtrack-realtime-notification',
    REALTIME_NOTIFICATION_STATE: 'flowtrack-realtime-notification-state',
});

export const CONNECTION_STATES = Object.freeze({
    INITIALIZED: 'initialized',
    CONNECTING: 'connecting',
    CONNECTED: 'connected',
    DISCONNECTED: 'disconnected',
    UNAVAILABLE: 'unavailable',
    FAILED: 'failed',
    ERROR: 'error',
});
