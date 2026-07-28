<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Briefcase,
    BarChart3,
    CheckSquare,
    Database,
    FileText,
    Columns3,
    LayoutDashboard,
    Settings,
    SlidersHorizontal,
    Users,
    Workflow,
} from '@lucide/vue';
import { computed } from 'vue';
import FlowBrand from './FlowBrand.vue';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
const page = usePage<any>();
const permissions = computed<string[]>(() => page.props.auth?.permissions || []);
const user = computed(() => page.props.auth?.user || {});
const membership = computed(() => page.props.auth?.membership || {});
const unread = computed(() => page.props.auth?.unreadNotifications || 0);

const nav = [
    { label: 'Dashboard', href: '/flowtrack/dashboard', icon: LayoutDashboard },
    { label: 'My Work', href: '/flowtrack/my-work', icon: CheckSquare },
    { label: 'Jobs', href: '/flowtrack/jobs', icon: Briefcase },
    { label: 'Clients', href: '/flowtrack/clients', icon: Users },
    { label: 'Board', href: '/flowtrack/board', icon: Columns3 },
    { label: 'Documents', href: '/flowtrack/documents', icon: FileText },
    { label: 'Reports', href: '/flowtrack/reports', icon: BarChart3, permission: 'reports.view' },
];
const admin = [
    { label: 'Notifications', href: '/flowtrack/notifications', icon: Bell, badge: unread },
    { label: 'Workflow Setup', href: '/flowtrack/workflow-setup', icon: Workflow, permission: 'workflows.manage' },
    { label: 'Master Data', href: '/flowtrack/master-data', icon: Database, permission: 'master-data.manage' },
    { label: 'Administration', href: '/flowtrack/administration', icon: SlidersHorizontal, permission: 'administration.manage' },
    { label: 'Profile Settings', href: '/settings/profile', icon: Settings },
];

const visibleNav = computed(() => nav.filter((item) => !item.permission || permissions.value.includes(item.permission)));
const visibleAdmin = computed(() => admin.filter((item) => !item.permission || permissions.value.includes(item.permission)));
const active = (href: string) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);
</script>

<template>
    <aside class="ft-sidebar" :class="{ open: props.open }">
        <FlowBrand />
        <div class="ft-sidebar-section">Workspace</div>
        <Link v-for="item in visibleNav" :key="item.href" :href="item.href" class="ft-nav-btn" :class="{ active: active(item.href) }" @click="emit('close')">
            <component :is="item.icon" />
            <span>{{ item.label }}</span>
        </Link>

        <div class="ft-sidebar-section">Administration</div>
        <Link v-for="item in visibleAdmin" :key="item.href" :href="item.href" class="ft-nav-btn" :class="{ active: active(item.href) }" @click="emit('close')">
            <component :is="item.icon" />
            <span>{{ item.label }}</span>
            <span v-if="item.badge?.value" class="ft-nav-badge">{{ item.badge.value }}</span>
        </Link>

        <div class="ft-sidebar-footer">
            <div class="ft-user-mini">
                <span class="ft-avatar dark">{{ user.initials || user.name?.slice(0, 2).toUpperCase() }}</span>
                <div style="min-width: 0">
                    <div class="ft-truncate" style="color: #fff; font-size: 12px; font-weight: 650">{{ user.name }}</div>
                    <div class="ft-truncate" style="font-size: 10px; color: #8397ae">{{ membership.job_title || membership.role?.name }}</div>
                </div>
            </div>
        </div>
    </aside>
</template>
