<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ meta: any }>();
</script>

<template>
    <div v-if="meta?.links" class="ft-pagination">
        <span class="ft-muted ft-small">Showing {{ meta.from || 0 }}–{{ meta.to || 0 }} of {{ meta.total || 0 }}</span>
        <div class="ft-pagination-links">
            <template v-for="link in meta.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" preserve-state preserve-scroll :class="{ active: link.active }" v-html="link.label" />
                <span v-else v-html="link.label" />
            </template>
        </div>
    </div>
</template>
