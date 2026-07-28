<script setup lang="ts">
defineProps<{ value: number }>();
</script>

<template>
    <div class="ft-progress" :aria-label="`${value}% complete`">
        <span :style="{ width: `${Math.max(0, Math.min(100, value))}%` }"></span>
    </div>
</template>
