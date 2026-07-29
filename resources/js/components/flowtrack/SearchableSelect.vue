<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type OptionValue = any;
type SearchOption = { value: OptionValue; label: string; disabled?: boolean };

const props = withDefaults(defineProps<{
    modelValue: OptionValue;
    options: SearchOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    disabled?: boolean;
}>(), {
    placeholder: 'Select an option',
    searchPlaceholder: 'Search options…',
    disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: OptionValue]; change: [value: OptionValue] }>();
const root = ref<HTMLElement | null>(null);
const open = ref(false);
const query = ref('');
const searchInput = ref<HTMLInputElement | null>(null);

const sameValue = (left: OptionValue, right: OptionValue) => String(left ?? '') === String(right ?? '');
const selected = computed(() => props.options.find((option) => sameValue(option.value, props.modelValue)) || null);
const filtered = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return props.options.filter((option) => !needle || `${option.label} ${option.value ?? ''}`.toLocaleLowerCase().includes(needle));
});

function toggle(): void {
    if (props.disabled) return;
    open.value = !open.value;
    if (open.value) {
        query.value = '';
        requestAnimationFrame(() => searchInput.value?.focus());
    }
}

function choose(option: SearchOption): void {
    if (option.disabled) return;
    emit('update:modelValue', option.value);
    emit('change', option.value);
    open.value = false;
}

function closeOutside(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

function closeOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    document.addEventListener('click', closeOutside);
    document.addEventListener('keydown', closeOnEscape);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeOutside);
    document.removeEventListener('keydown', closeOnEscape);
});
</script>

<template>
    <div ref="root" class="ft-searchable" :class="{ open, disabled }">
        <button type="button" class="ft-searchable-trigger" :disabled="disabled" :aria-expanded="open" aria-haspopup="listbox" @click="toggle">
            <span :class="{ 'ft-searchable-placeholder': !selected }">{{ selected?.label || placeholder }}</span>
            <span class="ft-searchable-arrow" aria-hidden="true"></span>
        </button>
        <div v-if="open" class="ft-searchable-panel">
            <div class="ft-searchable-search-wrap">
                <span class="ft-searchable-search-icon" aria-hidden="true">⌕</span>
                <input ref="searchInput" v-model="query" class="ft-searchable-search" type="search" :placeholder="searchPlaceholder" autocomplete="off" />
            </div>
            <div class="ft-searchable-options" role="listbox">
                <button
                    v-for="option in filtered"
                    :key="String(option.value)"
                    type="button"
                    class="ft-searchable-option"
                    :class="{ selected: sameValue(option.value, modelValue) }"
                    :disabled="option.disabled"
                    role="option"
                    :aria-selected="sameValue(option.value, modelValue)"
                    @click="choose(option)"
                >
                    <span>{{ option.label }}</span><b v-if="sameValue(option.value, modelValue)">✓</b>
                </button>
                <div v-if="!filtered.length" class="ft-searchable-empty">No matching result found</div>
            </div>
        </div>
    </div>
</template>
