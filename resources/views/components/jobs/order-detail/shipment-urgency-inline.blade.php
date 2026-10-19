@props([
    'job',
    'canEditJob' => false,
    'shipmentUrgencyOptions' => collect(),
    'context' => [],
    'variant' => 'planning',
])

@php
    // Planning & ownership now edits the complete shipping selection, not only
    // the Express urgency sub-field. Direct methods (Sea/Air/Road) and Express
    // levels share one selector while persistence stays backward compatible.
    $shippingOptions = collect($context['shipmentShippingOptions'] ?? [])
        ->map(fn ($option) => [
            'value' => (string) data_get($option, 'value', ''),
            'name' => (string) data_get($option, 'name', ''),
            'tone' => (string) data_get($option, 'tone', 'normal'),
        ])
        ->filter(fn ($option) => $option['value'] !== '' && $option['name'] !== '')
        ->values();
    $usesCombinedShippingSelection = $shippingOptions->isNotEmpty();

    // Backward-compatible fallback for any old partial render that does not yet
    // provide shipmentShippingOptions in its context.
    $legacyUrgencyId = (string) ($context['shipmentUrgencyId'] ?? '');
    $legacyUrgencyName = (string) ($context['shipmentUrgencyName'] ?? 'Normal Service');
    $legacyTone = (string) ($context['shipmentUrgencyTone'] ?? 'normal');

    $selectionValue = $usesCombinedShippingSelection
        ? (string) ($context['shipmentShippingValue'] ?? '')
        : $legacyUrgencyId;
    $selectionName = $usesCombinedShippingSelection
        ? (string) ($context['shipmentShippingName'] ?? 'Normal Service')
        : $legacyUrgencyName;
    $selectionTone = $usesCombinedShippingSelection
        ? (string) ($context['shipmentShippingTone'] ?? 'normal')
        : $legacyTone;

    $options = $usesCombinedShippingSelection
        ? $shippingOptions
        : collect($shipmentUrgencyOptions)
            ->map(fn ($option) => [
                'value' => (string) ((int) data_get($option, 'id')),
                'name' => (string) data_get($option, 'name'),
                'tone' => 'normal',
            ])
            ->filter(fn ($option) => $option['value'] !== '0' && $option['name'] !== '')
            ->values();

    $keySuffix = $variant === 'header' ? 'header-shipment-urgency' : 'planning-shipment-urgency';
    $wireSelectionKey = md5($selectionValue.'|'.$selectionName);
@endphp

<div
    {{ $attributes->class([
        'ft-order-urgency-inline',
        'ft-order-urgency-inline--header' => $variant === 'header',
        'ft-order-urgency-inline--planning' => $variant !== 'header',
        'ft-inline-edit-shell',
    ]) }}
    x-data="{
        ...window.FlowTrack.ui.inlineEdit({
            key: @js('job-'.$job->id.'-'.$keySuffix),
            label: 'shipment method / urgency',
            value: @js($selectionValue),
            display: @js($selectionName)
        }),
        options: @js($options->all()),
        combinedSelection: @js($usesCombinedShippingSelection),
        currentTone: @js($selectionTone),
        toneFor(name) {
            const normalized = String(name || '').toLowerCase();
            if (normalized.includes('super')) return 'super-urgent';
            if (normalized.includes('urgent')) return 'urgent';
            return 'normal';
        },
        optionFor(value) {
            return this.options.find((option) => String(option.value) === String(value));
        },
        syncUrgency(detail) {
            if (!detail || Number(detail.jobId) !== Number({{ $job->id }})) return;
            const nextValue = String(detail.value ?? detail.id ?? '');
            const option = this.optionFor(nextValue);
            const nextName = String(detail.name || option?.name || 'Normal Service');
            const nextTone = String(detail.tone || option?.tone || this.toneFor(nextName));
            this.serverValue = nextValue;
            this.value = nextValue;
            this.savedValue = nextValue;
            this.draftValue = nextValue;
            this.display = nextName;
            this.savedDisplay = nextName;
            this.currentTone = nextTone;
            this.editing = false;
        },
        nameFor(value) {
            const found = this.optionFor(value);
            return found ? found.name : 'Normal Service';
        },
        toneForValue(value) {
            const found = this.optionFor(value);
            return found?.tone || this.toneFor(found?.name || '');
        },
        async saveUrgency() {
            const nextValue = String(this.draftValue || '');
            const nextName = this.nameFor(nextValue);
            const saveAction = this.combinedSelection
                ? () => $wire.updateJobShippingSelection({{ $job->id }}, nextValue)
                : () => $wire.updateJobUrgencies({{ $job->id }}, 'shipment', nextValue ? [Number(nextValue)] : []);
            const ok = await this.commit(nextValue, nextName, saveAction);

            if (ok) {
                const canonicalValue = String(this.lastResponse?.value ?? nextValue);
                const canonicalName = String(this.lastResponse?.display ?? this.nameFor(canonicalValue));
                const canonicalTone = String(this.lastResponse?.tone ?? this.toneForValue(canonicalValue));
                const detail = {
                    jobId: {{ $job->id }},
                    value: canonicalValue,
                    id: canonicalValue,
                    name: canonicalName,
                    tone: canonicalTone,
                };
                this.syncUrgency(detail);
                window.dispatchEvent(new CustomEvent('ft-shipment-urgency-updated', { detail }));
                await $wire.$refresh();
            }
        }
    }"
    :class="{
        'is-inline-saving': status === 'saving',
        'is-inline-error': status === 'error',
        'is-editing': editing
    }"
    x-on:click.outside="if (editing && status !== 'saving') cancelEdit()"
    x-on:ft-shipment-urgency-updated.window="syncUrgency($event.detail)"
    wire:key="job-{{ $job->id }}-{{ $keySuffix }}-{{ $wireSelectionKey }}"
>
    <span x-show="!editing" class="ft-order-urgency-display">
        <span
            class="urgency-badge"
            :class="{ 'su': currentTone === 'super-urgent', 'u': currentTone === 'urgent', 'n': currentTone === 'normal' }"
            x-text="display"
            title="Shipping method and urgency used for the primary shipment."
        >{{ $selectionName }}</span>

        @if($canEditJob)
            <button
                type="button"
                class="inline-edit {{ $variant === 'header' ? 'ft-order-command-icon-btn' : '' }}"
                x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.urgencySelect.focus())"
                title="Edit shipment method / urgency"
                aria-label="Edit shipment method / urgency"
            >✎</button>
        @endif
    </span>

    @if($canEditJob)
        <span x-cloak x-show="editing" class="ft-order-urgency-editor">
            <select
                x-ref="urgencySelect"
                x-model="draftValue"
                :disabled="status === 'saving'"
                x-on:keydown.escape.prevent.stop="cancelEdit()"
                x-on:keydown.enter.prevent.stop="saveUrgency()"
                aria-label="Shipment method / urgency"
            >
                @unless($usesCombinedShippingSelection)
                    <option value="">Normal Service</option>
                @endunless
                <template x-for="option in options" :key="option.value">
                    <option :value="String(option.value)" x-text="option.name"></option>
                </template>
            </select>

            <span class="ft-order-urgency-editor-actions">
                <button
                    type="button"
                    class="ft-order-inline-icon-action ft-order-inline-icon-action--confirm"
                    :disabled="status === 'saving'"
                    x-on:click.stop="saveUrgency()"
                    title="Save shipment method / urgency"
                    aria-label="Save shipment method / urgency"
                ><span x-show="status !== 'saving'">✓</span><span x-cloak x-show="status === 'saving'">…</span></button>

                <button
                    type="button"
                    class="ft-order-inline-icon-action ft-order-inline-icon-action--cancel"
                    :disabled="status === 'saving'"
                    x-on:click.stop="cancelEdit()"
                    title="Cancel shipment method / urgency edit"
                    aria-label="Cancel shipment method / urgency edit"
                >×</button>
            </span>
        </span>
    @endif
</div>
