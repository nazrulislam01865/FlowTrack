import { router } from '@inertiajs/vue3';

let client: any = null;
let channel: any = null;
let refreshTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleRefresh(): void {
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
        router.reload({ preserveScroll: true, preserveState: true });
    }, 650);
}

export function initializeFlowTrackRealtime(): void {
    const config = window.FLOWTRACK_PUSHER;
    const userId = Number(config?.userId || 0);

    if (!config?.enabled || !config.key || !userId || typeof window.Pusher !== 'function' || client) {
        return;
    }

    try {
        client = new window.Pusher(config.key, {
            cluster: config.cluster || 'ap2',
            forceTLS: true,
            channelAuthorization: {
                endpoint: config.authEndpoint || '/flowtrack/pusher/auth',
                transport: 'ajax',
                headers: {
                    'X-CSRF-TOKEN': config.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        });

        channel = client.subscribe(`private-flowtrack.user.${userId}`);
        channel.bind('notification.created', (payload: Record<string, unknown>) => {
            window.dispatchEvent(new CustomEvent('flowtrack:realtime-notification', { detail: payload }));
            scheduleRefresh();
        });
        channel.bind('pusher:subscription_error', (status: unknown) => {
            console.warn('FlowTrack Pusher subscription failed.', status);
        });
    } catch (error) {
        console.warn('FlowTrack realtime notifications could not start.', error);
    }
}
