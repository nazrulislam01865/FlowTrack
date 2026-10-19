import { metaContent } from './meta.js';
import { setCancelledOrderCount, setMyWorkCount, setNotificationUnreadCount } from '../components/sidebar-counters.js';

const state = {
    request: null,
    lastData: null,
    lastRequestAt: 0,
};

const cooldownMs = 1500;

const applyCounters = (data = {}) => {
    setNotificationUnreadCount(data?.count ?? 0);
    setMyWorkCount(data?.my_work_count ?? 0);
    setCancelledOrderCount(data?.cancelled_order_count ?? 0);
};

export const syncPersistedCounters = async ({ force = false } = {}) => {
    const url = metaContent('flowtrack-notification-count-url');
    if (!url || document.hidden) return state.lastData;

    if (state.request) return state.request;

    const now = Date.now();
    if (!force && state.lastRequestAt && (now - state.lastRequestAt) < cooldownMs) {
        if (state.lastData) applyCounters(state.lastData);
        return state.lastData;
    }

    state.lastRequestAt = now;
    state.request = (async () => {
        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-FlowTrack-Background': '1',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok) return state.lastData;

            const data = await response.json();
            state.lastData = data;
            applyCounters(data);
            return data;
        } catch (_) {
            return state.lastData;
        } finally {
            state.request = null;
        }
    })();

    return state.request;
};

export const latestPersistedCounters = () => state.lastData;
