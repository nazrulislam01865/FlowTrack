<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import FlowPagination from '@/components/flowtrack/FlowPagination.vue';

const props = defineProps<{ notifications: any; authorityView: boolean }>();
const hasUnread = computed(() => props.notifications.data.some((notification: any) => !notification.read_at));

function audienceLabel(notification: any): string {
    if (!props.authorityView) return '';

    const names = (notification.data?.audience || [])
        .map((recipient: any) => recipient?.name)
        .filter(Boolean);

    return names.length ? `For: ${[...new Set(names)].join(', ')}` : '';
}

function openNotification(notification: any) {
    router.patch(`/flowtrack/notifications/${notification.id}/read`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (notification.data?.url) router.visit(notification.data.url);
            else if (notification.data?.job) router.get(`/flowtrack/jobs?search=${notification.data.job}`);
        },
    });
}

function markAllRead() {
    if (!hasUnread.value) return;
    router.patch('/flowtrack/notifications/read-all', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />
    <FlowPageHeader
        title="Notifications"
        :subtitle="authorityView
            ? 'Workspace-wide assignment, Task, Workflow, document and health updates'
            : 'Your assignment, Task, Workflow, document and health updates'"
    >
        <button class="ft-ghost" :disabled="!hasUnread" @click="markAllRead">Mark All Read</button>
    </FlowPageHeader>

    <article class="ft-card ft-section-card">
        <div class="ft-attention-list">
            <div
                v-for="notification in notifications.data"
                :key="notification.id"
                class="ft-attention-item"
                :style="{ opacity: notification.read_at ? 0.62 : 1 }"
                @click="openNotification(notification)"
            >
                <span
                    class="ft-signal"
                    :class="notification.data?.type === 'risk' || notification.data?.severity === 'warning'
                        ? 'red'
                        : notification.data?.severity === 'success'
                            ? 'green'
                            : 'purple'"
                ></span>
                <div>
                    <div class="ft-item-title">
                        {{ notification.data?.title || 'Notification' }}
                        <span v-if="!notification.read_at" class="ft-badge blue">New</span>
                    </div>
                    <div class="ft-item-meta">
                        {{ notification.data?.text }}
                        <template v-if="notification.data?.phase"> · {{ notification.data.phase }}</template>
                        · {{ new Date(notification.created_at).toLocaleString() }}
                    </div>
                    <div v-if="audienceLabel(notification)" class="ft-item-meta" style="margin-top: 4px">
                        {{ audienceLabel(notification) }}
                    </div>
                </div>
                <button class="ft-mini-btn" type="button">Open</button>
            </div>
            <div v-if="!notifications.data.length" class="ft-empty">You are all caught up.</div>
        </div>
        <FlowPagination :meta="notifications" />
    </article>
</template>
