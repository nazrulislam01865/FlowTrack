<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Briefcase, CheckSquare, Columns3, FileText, LayoutDashboard } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FlowSidebar from '@/components/flowtrack/FlowSidebar.vue';
import FlowTopbar from '@/components/flowtrack/FlowTopbar.vue';

const sidebarOpen = ref(false);
const page = usePage<any>();
const flash = computed(() => page.props.flash || {});
const toast = ref('');
let toastTimer: ReturnType<typeof setTimeout> | null = null;
let sessionExpiryTimer: ReturnType<typeof setTimeout> | null = null;
let sessionStatusTimer: ReturnType<typeof setInterval> | null = null;
let notificationStatusTimer: ReturnType<typeof setInterval> | null = null;
let notificationSignature: string | null | undefined = undefined;
let notificationUnread = Number(page.props.auth?.unreadNotifications || 0);

watch(
    () => [flash.value.success, flash.value.error],
    ([success, error]) => {
        const message = (success || error) as string | undefined;
        if (!message) return;
        toast.value = message;
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => (toast.value = ''), 2800);
    },
    { immediate: true },
);


function redirectSession(reason: string) {
    if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
    if (sessionStatusTimer) clearInterval(sessionStatusTimer);
    window.location.replace(`/login?session=${reason === 'replaced' ? 'replaced' : 'expired'}`);
}

function scheduleSessionGuard() {
    const session = page.props.session || {};
    if (session.expiresAt) {
        const delay = Math.max(0, new Date(session.expiresAt).getTime() - Date.now());
        sessionExpiryTimer = setTimeout(() => {
            window.location.replace(session.expiredEndpoint || '/flowtrack/session-expired');
        }, delay + 250);
    }
    if (!session.statusEndpoint) return;
    const check = async () => {
        try {
            const response = await fetch(session.statusEndpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            if (response.status !== 401) return;
            const payload = await response.json().catch(() => ({}));
            redirectSession(payload.reason || 'expired');
        } catch (_) {
            // Temporary connectivity issues do not invalidate a local session.
        }
    };
    sessionStatusTimer = setInterval(check, 10000);
}

function showRealtimeNotification(event: Event) {
    const payload = (event as CustomEvent<Record<string, unknown>>).detail || {};
    const title = String(payload.title || 'New notification');
    const text = String(payload.text || '');
    toast.value = text ? `${title}: ${text}` : title;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = ''), 4200);
}

async function pollNotificationStatus() {
    const endpoint = page.props.auth?.notificationStatusEndpoint;
    if (!endpoint || document.hidden) return;

    try {
        const response = await fetch(endpoint, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        });
        if (!response.ok) return;

        const payload = await response.json();
        const nextSignature = payload.latest_signature ?? null;
        const nextUnread = Number(payload.unread_count || 0);

        if (notificationSignature === undefined) {
            const unreadChanged = nextUnread !== notificationUnread;
            notificationSignature = nextSignature;
            notificationUnread = nextUnread;
            if (!unreadChanged) return;

            if (page.url.startsWith('/flowtrack/notifications')) {
                router.reload({ preserveScroll: true, preserveState: true });
            } else {
                router.reload({ only: ['auth'], preserveScroll: true, preserveState: true });
            }
            return;
        }

        if (nextSignature === notificationSignature && nextUnread === notificationUnread) return;

        notificationSignature = nextSignature;
        notificationUnread = nextUnread;
        if (page.url.startsWith('/flowtrack/notifications')) {
            router.reload({ preserveScroll: true, preserveState: true });
        } else {
            router.reload({ only: ['auth'], preserveScroll: true, preserveState: true });
        }
    } catch (_) {
        // Database polling is a fallback for unavailable realtime transport.
    }
}

function startNotificationPolling() {
    void pollNotificationStatus();
    notificationStatusTimer = setInterval(() => void pollNotificationStatus(), 20000);
}

onMounted(() => {
    window.addEventListener('flowtrack:realtime-notification', showRealtimeNotification);
    scheduleSessionGuard();
    startNotificationPolling();
});
onBeforeUnmount(() => {
    window.removeEventListener('flowtrack:realtime-notification', showRealtimeNotification);
    if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
    if (sessionStatusTimer) clearInterval(sessionStatusTimer);
    if (notificationStatusTimer) clearInterval(notificationStatusTimer);
});

const mobileNav = [
    { label: 'Home', href: '/flowtrack/dashboard', icon: LayoutDashboard },
    { label: 'My Work', href: '/flowtrack/my-work', icon: CheckSquare },
    { label: 'Jobs', href: '/flowtrack/jobs', icon: Briefcase },
    { label: 'Board', href: '/flowtrack/board', icon: Columns3 },
    { label: 'Files', href: '/flowtrack/documents', icon: FileText },
];
const active = (href: string) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);
</script>

<template>
    <div class="ft-app">
        <FlowSidebar :open="sidebarOpen" @close="sidebarOpen = false" />
        <div class="ft-sidebar-shade" :class="{ open: sidebarOpen }" @click="sidebarOpen = false"></div>
        <main class="ft-main">
            <FlowTopbar @menu="sidebarOpen = true" />
            <div class="ft-content"><slot /></div>
        </main>
        <nav class="ft-mobile-bottom">
            <Link v-for="item in mobileNav" :key="item.href" :href="item.href" :class="{ active: active(item.href) }">
                <component :is="item.icon" />
                {{ item.label }}
            </Link>
        </nav>
        <div v-if="toast" class="ft-toast">✓ {{ toast }}</div>
    </div>
</template>
