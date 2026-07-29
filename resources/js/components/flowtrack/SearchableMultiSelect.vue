<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type SearchOption = { value: number; label: string; disabled?: boolean };
const props = withDefaults(defineProps<{ modelValue: number[]; options: SearchOption[]; placeholder?: string; searchPlaceholder?: string }>(), {
    placeholder: 'Select members',
    searchPlaceholder: 'Search members…',
});
const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>();
const root = ref<HTMLElement | null>(null);
const open = ref(false);
const query = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
const selectedLabels = computed(() => props.options.filter((option) => props.modelValue.includes(option.value)).map((option) => option.label));
const triggerLabel = computed(() => selectedLabels.value.length ? (selectedLabels.value.length <= 2 ? selectedLabels.value.join(', ') : `${selectedLabels.value.length} members selected`) : props.placeholder);
const filtered = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return props.options.filter((option) => !needle || option.label.toLocaleLowerCase().includes(needle));
});
function toggle(): void { open.value = !open.value; if (open.value) { query.value = ''; requestAnimationFrame(() => searchInput.value?.focus()); } }
function toggleOption(value: number): void {
    const values = props.modelValue.includes(value) ? props.modelValue.filter((item) => item !== value) : [...props.modelValue, value];
    emit('update:modelValue', values);
}
function closeOutside(event: MouseEvent): void { if (root.value && !root.value.contains(event.target as Node)) open.value = false; }
function closeOnEscape(event: KeyboardEvent): void { if (event.key === 'Escape') open.value = false; }
onMounted(() => { document.addEventListener('click', closeOutside); document.addEventListener('keydown', closeOnEscape); });
onBeforeUnmount(() => { document.removeEventListener('click', closeOutside); document.removeEventListener('keydown', closeOnEscape); });
</script>

<template>
    <div ref="root" class="ft-searchable" :class="{ open }">
        <button type="button" class="ft-searchable-trigger" :aria-expanded="open" aria-haspopup="listbox" @click="toggle">
            <span :class="{ 'ft-searchable-placeholder': !selectedLabels.length }">{{ triggerLabel }}</span><span class="ft-searchable-arrow" aria-hidden="true"></span>
        </button>
        <div v-if="open" class="ft-searchable-panel">
            <div class="ft-searchable-search-wrap"><span class="ft-searchable-search-icon" aria-hidden="true">⌕</span><input ref="searchInput" v-model="query" class="ft-searchable-search" type="search" :placeholder="searchPlaceholder" autocomplete="off" /></div>
            <div class="ft-searchable-options" role="listbox" aria-multiselectable="true">
                <button v-for="option in filtered" :key="option.value" type="button" class="ft-searchable-option" :class="{ selected: modelValue.includes(option.value) }" :disabled="option.disabled" role="option" :aria-selected="modelValue.includes(option.value)" @click="toggleOption(option.value)">
                    <span>{{ option.label }}</span><b v-if="modelValue.includes(option.value)">✓</b>
                </button>
                <div v-if="!filtered.length" class="ft-searchable-empty">No matching result found</div>
            </div>
            <div class="ft-searchable-actions"><button type="button" class="ft-ghost" @click="emit('update:modelValue', [])">Clear</button><button type="button" class="ft-secondary" @click="open = false">Done</button></div>
        </div>
    </div>
</template>
