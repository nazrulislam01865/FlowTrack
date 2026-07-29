<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, Menu, Search } from '@lucide/vue';
import { computed, ref } from 'vue';

const emit = defineEmits<{ menu: [] }>();
const page = usePage<any>();
const query = ref('');
const user = computed(() => page.props.auth?.user || {});
const unread = computed(() => page.props.auth?.unreadNotifications || 0);

function globalSearch() {
    if (!query.value.trim()) return;
    router.get('/flowtrack/search', { q: query.value }, { preserveState: false });
}
</script>

<template>
    <header class="ft-topbar">
        <button class="ft-mobile-menu" type="button" @click="emit('menu')"><Menu /></button>
        <form class="ft-search" @submit.prevent="globalSearch">
            <Search />
            <input v-model="query" placeholder="Search jobs, clients, tasks, documents…" />
        </form>
        <div class="ft-top-actions">
            <Link href="/flowtrack/notifications" class="ft-icon-btn" aria-label="Notifications">
                <Bell />
                <span v-if="unread" class="ft-dot"></span>
            </Link>
            <Link href="/settings/profile" class="ft-icon-btn" aria-label="Profile">
                <span class="ft-avatar" style="width: 28px; height: 28px; font-size: 10px">{{ user.initials || user.name?.slice(0, 2).toUpperCase() }}</span>
            </Link>
        </div>
    </header>
</template>
