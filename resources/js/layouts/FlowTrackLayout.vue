<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Briefcase, CheckSquare, Columns3, FileText, LayoutDashboard } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FlowSidebar from '@/components/flowtrack/FlowSidebar.vue';
import FlowTopbar from '@/components/flowtrack/FlowTopbar.vue';

const sidebarOpen = ref(false);
const page = usePage<any>();
const flash = computed(() => page.props.flash || {});
const toast = ref('');
let toastTimer: ReturnType<typeof setTimeout> | null = null;

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

function openCreateJob() {
    window.dispatchEvent(new CustomEvent('flowtrack:create-job'));
    if (!page.url.startsWith('/flowtrack/jobs')) {
        window.location.href = '/flowtrack/jobs?create=1';
    }
}

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
            <FlowTopbar @menu="sidebarOpen = true" @create="openCreateJob" />
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
