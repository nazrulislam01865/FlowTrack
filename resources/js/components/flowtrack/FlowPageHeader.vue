<script setup lang="ts">
defineProps<{ title: string; subtitle: string }>();
</script>

<template>
    <header class="ft-page-head">
        <div>
            <h1>{{ title }}</h1>
            <p>{{ subtitle }}</p>
        </div>
        <div class="ft-actions"><slot /></div>
    </header>
</template>
