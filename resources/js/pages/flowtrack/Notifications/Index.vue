<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import FlowPagination from '@/components/flowtrack/FlowPagination.vue';

const props = defineProps<{ notifications: any }>();

function openNotification(notification: any) {
    router.patch(`/flowtrack/notifications/${notification.id}/read`, {}, {
        preserveScroll: true,
        onSuccess: () => notification.data?.job ? router.get(`/flowtrack/jobs?search=${notification.data.job}`) : undefined,
    });
}
</script>

<template>
    <Head title="Notifications" />
    <FlowPageHeader title="Notifications" subtitle="Grouped reminders, task updates and risk alerts"><button class="ft-ghost" @click="router.patch('/flowtrack/notifications/read-all')">Mark All Read</button></FlowPageHeader>
    <article class="ft-card ft-section-card"><div class="ft-attention-list"><div v-for="notification in notifications.data" :key="notification.id" class="ft-attention-item" :style="{ opacity: notification.read_at ? 0.62 : 1 }" @click="openNotification(notification)"><span class="ft-signal" :class="notification.data?.type === 'risk' ? 'red' : notification.data?.type === 'blocked' ? '' : 'purple'"></span><div><div class="ft-item-title">{{ notification.data?.title }} <span v-if="!notification.read_at" class="ft-badge blue">New</span></div><div class="ft-item-meta">{{ notification.data?.text }} · {{ new Date(notification.created_at).toLocaleString() }}</div></div><button class="ft-mini-btn">Open</button></div><div v-if="!notifications.data.length" class="ft-empty">You are all caught up.</div></div><FlowPagination :meta="notifications" /></article>
</template>
