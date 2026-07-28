<script setup lang="ts">
defineProps<{ label: string; value: string | number; foot: string; clickable?: boolean }>();
</script>

<template>
    <article class="ft-card ft-metric" :class="{ clickable }">
        <div class="ft-metric-label">{{ label }}</div>
        <div class="ft-metric-value"><slot name="value">{{ value }}</slot></div>
        <div class="ft-metric-foot">{{ foot }}</div>
        <div class="ft-metric-icon"><slot name="icon">◎</slot></div>
    </article>
</template>
