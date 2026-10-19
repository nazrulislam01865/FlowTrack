@props([
    'task' => null,
    'details' => [],
    'canEdit' => false,
])
@php
    $supplierDeliveryDate = trim((string) ($details['supplierDeliveryDate'] ?? ''));
    $productionIssueNote = trim((string) ($details['productionIssueNote'] ?? ''));
    $displayDate = '—';
    if ($supplierDeliveryDate !== '') {
        try {
            $displayDate = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $supplierDeliveryDate)->format('d/m/Y');
        } catch (\Throwable $exception) {
            $displayDate = $supplierDeliveryDate;
        }
    }
    $canInlineEdit = (bool) $canEdit && $task;
@endphp

<div class="ft-production-monitor-summary" aria-label="Saved production monitor details">
    <div
        class="ft-production-monitor-summary-date ft-inline-edit-shell"
        x-data="{
            ...window.FlowTrack.ui.inlineEdit({
                key: @js('production-monitor-'.($task?->id ?? 'saved').'-supplier-date'),
                label: 'supplier delivery date',
                value: @js($supplierDeliveryDate),
                display: @js($displayDate)
            }),
            formatDmy(value) {
                if (!value) return '—';
                const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                return match ? `${match[3]}/${match[2]}/${match[1]}` : String(value);
            }
        }"
        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
    >
        <span class="ft-production-monitor-summary-label">Supplier Delivery Date</span>
        <div class="ft-production-monitor-summary-date-value">
            <div x-show="!editing" class="ft-production-monitor-summary-inline-display">
                <strong class="ft-production-monitor-summary-value" x-text="display">{{ $displayDate }}</strong>
                @if($canInlineEdit)
                    <button
                        type="button"
                        class="ft-inline-edit-button compact ft-production-monitor-summary-edit"
                        title="Edit supplier delivery date"
                        aria-label="Edit supplier delivery date"
                        :disabled="status === 'saving'"
                        x-on:click.stop="if (beginEdit()) $nextTick(() => { $refs.productionMonitorDate.focus({ preventScroll: true }); if (typeof $refs.productionMonitorDate.showPicker === 'function') { try { $refs.productionMonitorDate.showPicker(); } catch (e) {} } })"
                    >✎</button>
                @endif
            </div>

            @if($canInlineEdit)
                <input
                    x-ref="productionMonitorDate"
                    x-cloak
                    x-show="editing"
                    x-model="draftValue"
                    type="date"
                    class="ft-production-monitor-summary-date-input ft-prototype-clickable-date"
                    onclick="this.focus({ preventScroll: true }); if (typeof this.showPicker === 'function') { try { this.showPicker(); } catch (e) {} }"
                    x-on:keydown.escape.prevent="cancelEdit()"
                    x-on:blur="if (editing) cancelEdit()"
                    x-on:change="commit($event.target.value, formatDmy($event.target.value), () => $wire.updateCompletedProductionMonitorSupplierDate({{ (int) $task->id }}, draftValue))"
                    aria-label="Supplier Delivery Date. Click anywhere in the field to open the calendar."
                >
                <x-ui.inline-save-state compact />
            @endif
        </div>
    </div>

    <div
        class="ft-production-monitor-summary-note ft-inline-edit-shell"
        x-data="window.FlowTrack.ui.inlineEdit({
            key: @js('production-monitor-'.($task?->id ?? 'saved').'-issue-note'),
            label: 'production issue note',
            value: @js($productionIssueNote),
            display: @js($productionIssueNote !== '' ? $productionIssueNote : '—')
        })"
        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
    >
        <div class="ft-production-monitor-summary-note-head">
            <span class="ft-production-monitor-summary-label">Production Issue Note <em>(Optional)</em></span>
            @if($canInlineEdit)
                <button
                    x-show="!editing"
                    type="button"
                    class="ft-inline-edit-button compact ft-production-monitor-summary-edit"
                    title="Edit production issue note"
                    aria-label="Edit production issue note"
                    :disabled="status === 'saving'"
                    x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.productionMonitorNote.focus())"
                >✎</button>
            @endif
        </div>

        <div x-show="!editing" class="ft-production-monitor-summary-note-value" x-text="display">{{ $productionIssueNote !== '' ? $productionIssueNote : '—' }}</div>

        @if($canInlineEdit)
            <div x-cloak x-show="editing" class="ft-production-monitor-summary-note-editor">
                <textarea
                    x-ref="productionMonitorNote"
                    x-model="draftValue"
                    rows="3"
                    maxlength="10000"
                    class="ft-production-monitor-summary-note-input"
                    placeholder="Add note about the production issue, resolution, or communication with supplier..."
                    x-on:keydown.escape.prevent="cancelEdit()"
                ></textarea>
                <div class="ft-production-monitor-summary-note-actions">
                    <button type="button" class="btn small" x-on:click="cancelEdit()">Cancel</button>
                    <button
                        type="button"
                        class="btn small primary"
                        :disabled="status === 'saving'"
                        x-on:click="commit(draftValue, String(draftValue || '').trim() || '—', () => $wire.updateCompletedProductionMonitorIssueNote({{ (int) $task->id }}, draftValue))"
                    >Save</button>
                    <x-ui.inline-save-state compact />
                </div>
            </div>
        @endif
    </div>
</div>
