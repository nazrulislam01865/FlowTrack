<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ value?: string | null; health?: boolean }>();

const normalized = computed(() => (props.value || 'Unknown').replaceAll('_', ' '));
const tone = computed(() => {
    const value = normalized.value.toLowerCase();
    if (/completed|approved|paid|delivered|on track|active/.test(value)) return 'green';
    if (/blocked|critical|overdue|revision|delayed|at risk/.test(value)) return 'red';
    if (/waiting|negotiation|partial|attention/.test(value)) return 'amber';
    if (/in progress|submitted|ready|transit|artwork|production|shipment/.test(value)) return 'blue';
    if (/admin|manager|approval/.test(value)) return 'purple';
    return 'gray';
});
</script>

<template>
    <span class="ft-badge" :class="[tone, { 'ft-health': health }]">{{ normalized }}</span>
</template>
