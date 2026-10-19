@props(['row', 'presentation'])

@php
    $task = $row['task'];
    $canEdit = (bool) ($row['can_edit'] ?? false);
    $actionable = $row['mode'] === 'active' || $row['is_done'];
    $editable = $canEdit && $actionable;
    $couriers = $presentation['couriers'] ?? [];
@endphp

<div class="ft-ms-table-wrap ft-ms-table-wrap--tracking">
    <table class="ft-ms-table ft-ms-table--tracking">
        <thead>
            <tr>
                <th>Shipment</th>
                <th>Courier</th>
                <th>Tracking Number</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse(($presentation['shipments'] ?? []) as $shipment)
                @php
                    $hasCourier = ! empty($shipment['courier_id']);
                    $hasTracking = trim((string) ($shipment['tracking_number'] ?? '')) !== '';
                    $initialEntry = ! $hasCourier || ! $hasTracking;
                    // As soon as Task 5.2 becomes active, incomplete shipment rows
                    // open automatically. Initial entry is intentionally NOT auto-saved:
                    // the user reviews both values and clicks Save for that shipment.
                    // After the first save, each field can still be edited inline with
                    // the pencil control and those later edits save immediately.
                    $autoOpen = $editable && $row['mode'] === 'active' && $initialEntry;
                @endphp
                <tr
                    wire:key="shipment-tracking-row-{{ $shipment['id'] }}-{{ $row['mode'] }}-{{ (int) $autoOpen }}"
                    x-data="{
                        courierId: @js($shipment['courier_id'] ? (string) $shipment['courier_id'] : ''),
                        originalCourierId: @js($shipment['courier_id'] ? (string) $shipment['courier_id'] : ''),
                        courierName: @js($shipment['courier_name'] ?: 'Not selected'),
                        originalCourierName: @js($shipment['courier_name'] ?: 'Not selected'),
                        tracking: @js($shipment['tracking_number']),
                        originalTracking: @js($shipment['tracking_number']),
                        courierEditing: @js($autoOpen),
                        trackingEditing: @js($autoOpen),
                        initialEntry: @js($initialEntry),
                        saving: false,
                        resetCourier() {
                            this.courierId = this.originalCourierId;
                            this.courierName = this.originalCourierName;
                            this.courierEditing = false;
                        },
                        resetTracking() {
                            this.tracking = this.originalTracking;
                            this.trackingEditing = false;
                        },
                        canSave() {
                            return String(this.courierId || '').trim() !== '' && String(this.tracking || '').trim() !== '';
                        },
                        persist() {
                            const courierId = String(this.courierId || '').trim();
                            const tracking = String(this.tracking || '').trim();
                            if (this.saving || !courierId || !tracking) return;

                            this.saving = true;
                            this.tracking = tracking;
                            $wire.saveOrderShipmentTracking({{ $task->id }}, {{ $shipment['id'] }}, Number(courierId), tracking)
                                .then(() => {
                                    this.originalCourierId = courierId;
                                    this.originalCourierName = this.courierName;
                                    this.originalTracking = tracking;
                                    this.initialEntry = false;
                                    this.courierEditing = false;
                                    this.trackingEditing = false;
                                })
                                .finally(() => { this.saving = false; });
                        }
                    }"
                    :class="{ 'is-inline-saving': saving }"
                >
                    <td data-label="Shipment">
                        <div class="ft-ms-shipment-number"><b>{{ $shipment['sequence'] }}</b>@if($shipment['is_primary'])<span class="ft-ms-primary">Primary</span>@endif</div>
                    </td>

                    <td data-label="Courier">
                        <div class="ft-ms-inline-field">
                            <div class="ft-ms-courier-value" x-show="!courierEditing">
                                <strong x-text="courierName">{{ $shipment['courier_name'] ?: 'Not selected' }}</strong>
                                @if($editable)
                                    <button
                                        type="button"
                                        class="ft-ms-cell-edit-button"
                                        title="Edit courier"
                                        aria-label="Edit courier for Shipment {{ $shipment['sequence'] }}"
                                        x-on:click.stop="courierEditing = true; $nextTick(() => $refs.courierSelect?.focus())"
                                    >
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m4 14.5-.5 2 2-.5L14 7.5 12.5 6 4 14.5Z"/><path d="m11.5 7 1.5-1.5a1.1 1.1 0 0 1 1.6 0l.4.4a1.1 1.1 0 0 1 0 1.6L13.5 9"/></svg>
                                    </button>
                                @endif
                            </div>

                            @if($editable)
                                <x-jobs.order-detail.shipment.courier-select
                                    x-ref="courierSelect"
                                    x-cloak
                                    x-show="courierEditing"
                                    x-model="courierId"
                                    x-bind:disabled="saving"
                                    x-on:change="courierName = $event.target.options[$event.target.selectedIndex]?.text || 'Not selected'; if (!initialEntry) persist()"
                                    x-on:keydown.escape.prevent="if (!initialEntry) resetCourier()"
                                    :couriers="$couriers"
                                    aria-label="Courier for Shipment {{ $shipment['sequence'] }}"
                                />
                            @endif
                        </div>
                    </td>

                    <td data-label="Tracking Number">
                        <div class="ft-ms-inline-field">
                            <div class="ft-ms-tracking-value" x-show="!trackingEditing">
                                <span x-text="tracking || 'Not added'" :class="tracking ? '' : 'is-empty'">{{ $shipment['tracking_number'] ?: 'Not added' }}</span>
                                @if($editable)
                                    <button
                                        type="button"
                                        class="ft-ms-cell-edit-button"
                                        title="Edit tracking number"
                                        aria-label="Edit tracking number for Shipment {{ $shipment['sequence'] }}"
                                        x-on:click.stop="trackingEditing = true; $nextTick(() => { $refs.trackingInput?.focus(); $refs.trackingInput?.select(); })"
                                    >
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m4 14.5-.5 2 2-.5L14 7.5 12.5 6 4 14.5Z"/><path d="m11.5 7 1.5-1.5a1.1 1.1 0 0 1 1.6 0l.4.4a1.1 1.1 0 0 1 0 1.6L13.5 9"/></svg>
                                    </button>
                                @endif
                            </div>

                            @if($editable)
                                <input
                                    x-ref="trackingInput"
                                    x-cloak
                                    x-show="trackingEditing"
                                    x-model.trim="tracking"
                                    x-bind:disabled="saving"
                                    class="ft-ms-tracking-input"
                                    type="text"
                                    maxlength="255"
                                    placeholder="Enter tracking number"
                                    x-on:keydown.escape.prevent="if (!initialEntry) resetTracking()"
                                    x-on:keydown.enter.prevent="if (!initialEntry) persist()"
                                    x-on:blur="if (!initialEntry) { if (String(tracking || '').trim() === String(originalTracking || '').trim()) { trackingEditing = false } else { persist() } }"
                                >
                            @endif
                        </div>
                    </td>

                    <td data-label="Actions">
                        @if($editable && $initialEntry)
                            <div class="ft-ms-actions" x-show="initialEntry">
                                <button
                                    type="button"
                                    class="ft-ms-primary-btn"
                                    x-on:click="persist()"
                                    x-bind:disabled="saving || !canSave()"
                                >
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m4.5 10.5 3.2 3.2 7.8-8"/></svg>
                                    <span x-text="saving ? 'Saving...' : 'Save'">Save</span>
                                </button>
                            </div>
                        @else
                            <span class="ft-ms-row-action-empty">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="ft-ms-empty">No shipments are available.</td></tr>
            @endforelse
        </tbody>
    </table>
    @error('shipmentTracking')<p class="validation-error ft-ms-error">{{ $message }}</p>@enderror
</div>
