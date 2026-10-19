@props([
    'row',
    'presentation',
])

@php
    $task = $row['task'];
    $canEdit = (bool) ($row['can_edit'] ?? false);
    $isCurrentStage = (int) $task->workflow_phase_id === (int) $task->job?->workflow_phase_id;
    $canEditPlan = $canEdit && $isCurrentStage;
    $shipments = collect($presentation['shipments'] ?? []);
    $shipmentCount = $shipments->count();
    $addressMode = (string) ($presentation['address_mode'] ?? \App\Services\OrderShipmentService::MODE_SAME_ADDRESS);
    $planLabel = $shipmentCount <= 1
        ? 'Single shipment'
        : ($addressMode === \App\Services\OrderShipmentService::MODE_MULTIPLE_ADDRESS ? 'Multiple addresses' : 'Same address');
@endphp

<div class="ft-ms-plan">
    <div class="ft-ms-plan-summary">
        <div class="ft-ms-plan-summary__copy">
            <span class="ft-ms-plan-summary__count">{{ $shipmentCount }} {{ \Illuminate\Support\Str::plural('shipment', $shipmentCount) }}</span>
            <span class="ft-ms-plan-summary__mode">{{ $planLabel }}</span>
            <span class="ft-ms-plan-summary__hint">Shipping method can be changed directly. Use Edit for the delivery address.</span>
        </div>

        @if($canEditPlan)
            <button
                type="button"
                class="ft-ms-outline-btn ft-ms-plan-summary__add"
                wire:click="openAddShipment({{ $task->id }})"
            >
                <span aria-hidden="true">＋</span>
                Add shipment
            </button>
        @endif
    </div>

    @error('shipmentSettings')<p class="validation-error ft-ms-error">{{ $message }}</p>@enderror
    @error('shipmentMethod')<p class="validation-error ft-ms-error">{{ $message }}</p>@enderror

    <div class="ft-ms-table-wrap ft-ms-table-wrap--plan">
        <table class="ft-ms-table ft-ms-table--plan">
            <thead>
                <tr>
                    <th>Shipment</th>
                    <th>Quantity</th>
                    <th>Delivery details</th>
                    <th>Shipping method</th>
                    <th>Package / Reference</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shipments as $shipment)
                    <tr wire:key="shipment-plan-row-{{ $shipment['id'] }}">
                        <td data-label="Shipment">
                            <div class="ft-ms-shipment-number">
                                <b>{{ $shipment['sequence'] }}</b>
                                @if($shipment['is_primary'])<span class="ft-ms-primary">Primary</span>@endif
                            </div>
                        </td>

                        <td data-label="Quantity">
                            <span class="ft-ms-package-reference">{{ $shipment['quantity'] ?? '—' }}</span>
                        </td>

                        <td data-label="Delivery details">
                            <div class="ft-ms-delivery">
                                <div class="ft-ms-delivery__recipient">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="10" cy="6.5" r="2.5"/><path d="M5.5 16v-2.2a4.5 4.5 0 0 1 9 0V16"/></svg>
                                    <div>
                                        <strong>{{ $shipment['recipient'] ?: 'Recipient not set' }}</strong>
                                        @if($shipment['phone'])<small>{{ $shipment['phone'] }}</small>@endif
                                    </div>
                                </div>
                                <address>{{ $shipment['address'] ?: 'Address not set' }}</address>
                                <div class="ft-ms-delivery__meta">
                                    @foreach(collect([$shipment['city'], $shipment['state'], $shipment['postal_code'], $shipment['country']])->filter() as $part)
                                        <span>{{ $part }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </td>

                        <td data-label="Shipping method" class="ft-ms-method-cell">
                            @if($canEditPlan && ! $shipment['dispatched'])
                                <x-jobs.order-detail.shipment.method-picker
                                    :selected="$shipment['method_card']"
                                    :methods="$presentation['shipment_methods'] ?? collect()"
                                    :urgencies="$presentation['shipment_urgencies'] ?? collect()"
                                    :task-id="$task->id"
                                    :shipment-id="$shipment['id']"
                                    mode="row"
                                    appearance="inline"
                                />
                            @elseif($shipment['method_card'])
                                <div class="ft-ms-method-display">
                                    <span class="ft-ms-method-label__icon"><x-jobs.create.shipping-method-icon :type="$shipment['method_card']['kind']" /></span>
                                    <span><strong>{{ $shipment['method_card']['title'] }}</strong></span>
                                </div>
                            @else
                                <span class="ft-ms-missing-value">Not selected</span>
                            @endif
                        </td>

                        <td data-label="Package / Reference">
                            <span class="ft-ms-package-reference">{{ $shipment['package_reference'] ?: '—' }}</span>
                        </td>

                        <td data-label="Actions">
                            @if($canEditPlan && ! $shipment['dispatched'])
                                <div class="ft-ms-row-actions">
                                    <button
                                        type="button"
                                        class="ft-ms-row-edit"
                                        wire:click="openEditShipment({{ $task->id }}, {{ $shipment['id'] }})"
                                        title="Edit Shipment {{ $shipment['sequence'] }} details"
                                    >
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m4 14.5-.5 2 2-.5L14 7.5 12.5 6 4 14.5Z"/><path d="m11.5 7 1.5-1.5a1.1 1.1 0 0 1 1.6 0l.4.4a1.1 1.1 0 0 1 0 1.6L13.5 9"/></svg>
                                        <span>Edit</span>
                                    </button>

                                    @unless($shipment['is_primary'])
                                        <div class="ft-ms-kebab" x-data="{ open: false }" x-on:click.outside="open = false">
                                            <button type="button" class="ft-ms-kebab__button" x-on:click="open = !open" aria-label="More actions for Shipment {{ $shipment['sequence'] }}">⋮</button>
                                            <div class="ft-ms-kebab__menu" x-cloak x-show="open">
                                                <button type="button" class="is-danger" wire:click="removeOrderShipment({{ $task->id }}, {{ $shipment['id'] }})" wire:confirm="Remove Shipment {{ $shipment['sequence'] }}?" x-on:click="open = false">Remove shipment</button>
                                            </div>
                                        </div>
                                    @endunless
                                </div>
                            @else
                                <span class="ft-ms-readonly-dash">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ft-ms-empty">No shipment details are available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($canEditPlan && ($row['mode'] === 'active' || $row['is_done']))
        <div class="ft-ms-review-panel ft-ms-review-panel--compact">
            @if($row['is_done'])
                <div>
                    <strong>Shipment details confirmed</strong>
                    <p>Shipping method can still be changed directly while the Shipment stage is active.</p>
                </div>
            @else
                <div>
                    <strong>Are the shipment details correct?</strong>
                    <p>Change the shipping method directly, or use Edit for the delivery address.</p>
                </div>
                <div class="ft-ms-review-panel__actions">
                    <button
                        type="button"
                        class="ft-ms-primary-btn"
                        wire:click="confirmShipmentPlan({{ $task->id }})"
                        wire:loading.attr="disabled"
                        wire:target="confirmShipmentPlan"
                    >
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m4.5 10.5 3.2 3.2 7.8-8"/></svg>
                        No changes — continue
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>
