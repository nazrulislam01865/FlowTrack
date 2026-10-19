@props(['row', 'presentation'])

@php
    $task = $row['task'];
    $canEdit = (bool) ($row['can_edit'] ?? false);
@endphp

<div class="ft-ms-table-wrap">
    <table class="ft-ms-table ft-ms-table--dispatch">
        <thead>
            <tr>
                <th>Shipment</th>
                <th>Courier</th>
                <th>Status</th>
                <th>Dispatched On</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse(($presentation['shipments'] ?? []) as $shipment)
                <tr wire:key="shipment-dispatch-row-{{ $shipment['id'] }}">
                    <td><div class="ft-ms-shipment-number"><b>{{ $shipment['sequence'] }}</b>@if($shipment['is_primary'])<span class="ft-ms-primary">Primary</span>@endif</div></td>
                    <td data-label="Courier">
                        <div class="ft-ms-courier-value">
                            <strong>{{ $shipment['courier_name'] ?: 'Not selected' }}</strong>
                        </div>
                    </td>
                    <td>
                        <span class="ft-ms-status {{ $shipment['dispatched'] ? 'is-dispatched' : 'is-pending' }}">{{ $shipment['dispatched'] ? 'Dispatched' : 'Pending' }}</span>
                    </td>
                    <td>{{ $shipment['dispatched_on'] }}</td>
                    <td>
                        <div class="ft-ms-actions">
                            <button
                                type="button"
                                class="ft-ms-outline-btn"
                                @disabled($shipment['dispatched'] || !$canEdit || $row['mode'] !== 'active' || !$shipment['courier_id'] || !$shipment['tracking_number'])
                                wire:click="dispatchOrderShipment({{ $task->id }}, {{ $shipment['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="dispatchOrderShipment"
                            >
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="m6.5 10 2.2 2.2 4.8-4.8"/></svg>
                                Mark as dispatched
                            </button>
                            <button type="button" class="ft-ms-outline-btn" wire:click="openOrderShipmentDetails({{ $task->id }}, {{ $shipment['id'] }})">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2.5 10s2.8-5 7.5-5 7.5 5 7.5 5-2.8 5-7.5 5-7.5-5-7.5-5Z"/><circle cx="10" cy="10" r="2"/></svg>
                                View details
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="ft-ms-empty">No shipments are available.</td></tr>
            @endforelse
        </tbody>
    </table>
    @error('shipmentDispatch')<p class="validation-error ft-ms-error">{{ $message }}</p>@enderror
</div>
