<script setup lang="ts">
defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="ft-overlay" @click="emit('close')"></div>
        <aside v-if="open" class="ft-drawer"><slot /></aside>
    </Teleport>
</template>
