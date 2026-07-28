<script setup lang="ts">
defineProps<{ open: boolean; title: string; subtitle?: string }>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="ft-overlay" @click.self="emit('close')"></div>
        <section v-if="open" class="ft-modal" role="dialog" aria-modal="true">
            <header class="ft-modal-head">
                <div>
                    <h2 style="font-size: 18px; margin: 0">{{ title }}</h2>
                    <p v-if="subtitle" class="ft-muted ft-small" style="margin: 5px 0 0">{{ subtitle }}</p>
                </div>
                <button class="ft-close-btn" type="button" @click="emit('close')">×</button>
            </header>
            <div class="ft-modal-body"><slot /></div>
            <footer v-if="$slots.footer" class="ft-modal-foot"><slot name="footer" /></footer>
        </section>
    </Teleport>
</template>
