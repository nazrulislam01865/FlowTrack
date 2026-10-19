@props([
    'shipments' => [],
    'mode' => 'multiple_shipments',
    'countries' => collect(),
    'statesByCountry' => collect(),
    'phoneCodes' => collect(),
    'savedShippingAddresses' => collect(),
    'showSavedShippingAddressPicker' => false,
    'savedShippingAddressShipmentIndex' => null,
    'referenceNumber' => '',
])

@php
    $rows = collect($shipments)->values();
    $shipmentCount = max(1, $rows->count());
    $addressKeys = $rows->map(function ($shipment) {
        $parts = collect([
            data_get($shipment, 'address'),
            data_get($shipment, 'postal_code'),
            data_get($shipment, 'city'),
            data_get($shipment, 'state'),
            data_get($shipment, 'country'),
        ])->map(fn ($value) => trim((string) $value));

        $hasEnteredAddress = trim((string) data_get($shipment, 'address')) !== ''
            || trim((string) data_get($shipment, 'postal_code')) !== '';

        return $hasEnteredAddress ? mb_strtolower($parts->implode('|')) : null;
    })->filter()->unique();
    $deliveryAddressCount = $addressKeys->count();
    $hasSavedAddresses = collect($savedShippingAddresses)->isNotEmpty();
    $targetIndex = $savedShippingAddressShipmentIndex ?? 0;
    $targetSourceAddressId = data_get($rows->get($targetIndex, []), 'shipping_source_address_id');

    $isMultipleAddressMode = $mode === \App\Services\OrderShipmentService::MODE_MULTIPLE_ADDRESS;
    $modeNote = $isMultipleAddressMode
        ? 'Each shipment keeps its own delivery address.'
        : 'One shipment address is configured for this Order.';
@endphp

<section class="ft-create-section ft-create-shipping-setup" wire:key="create-order-shipping-setup">
    <div class="ft-create-shipping-setup-heading">
        <div class="ft-create-section-title">
            <span>2</span>
            <h2>Shipment address</h2>
        </div>
        <p>Configure one or more shipment addresses. Shipping method is selected in Schedule &amp; owner and applied to every shipment.</p>
    </div>

    <div class="ft-create-shipping-modes" role="radiogroup" aria-label="Shipment address mode">
        <label class="ft-create-shipping-mode {{ ! $isMultipleAddressMode ? 'is-selected' : '' }}">
            <input
                type="radio"
                name="create-shipment-mode"
                value="multiple_shipments"
                @checked(! $isMultipleAddressMode)
                wire:click="setCreateShipmentMode('multiple_shipments')"
            >
            <span class="ft-create-shipping-mode-copy">
                <strong>Address</strong>
                <small>Start from the first address and change any shipment if needed.</small>
            </span>
        </label>

        <label class="ft-create-shipping-mode {{ $isMultipleAddressMode ? 'is-selected' : '' }}">
            <input
                type="radio"
                name="create-shipment-mode"
                value="multiple_address"
                @checked($isMultipleAddressMode)
                wire:click="setCreateShipmentMode('multiple_address')"
            >
            <span class="ft-create-shipping-mode-copy">
                <strong>Multiple address</strong>
                <small>Configure a separate delivery address for each shipment.</small>
            </span>
        </label>
    </div>
    @error('createShipmentMode')<small class="validation-error ft-create-shipping-mode-error">{{ $message }}</small>@enderror

    <div class="ft-create-shipping-summary">
        <div>
            <span class="ft-create-shipping-summary-item">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3.5 5.5h9.3v8.2H3.5z"/><path d="M12.8 8h2.2l1.8 2v3.7h-4z"/><circle cx="6" cy="15" r="1.2"/><circle cx="14.8" cy="15" r="1.2"/></svg>
                <strong>{{ $shipmentCount }}</strong> {{ \Illuminate\Support\Str::plural('shipment', $shipmentCount) }} configured
            </span>
            <span class="ft-create-shipping-summary-divider" aria-hidden="true"></span>
            <span class="ft-create-shipping-summary-item">
                <i></i><strong>{{ $deliveryAddressCount }}</strong> {{ \Illuminate\Support\Str::plural('delivery address', $deliveryAddressCount) }} entered
            </span>
        </div>
        <span class="ft-create-shipping-summary-note">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 9v4M10 6.5h.01"/></svg>
            {{ $modeNote }}
        </span>
    </div>

    <div class="ft-create-shipment-workspace">
        <div class="ft-create-shipment-rows">
            @foreach($rows as $index => $shipment)
                <x-jobs.create.shipping-row
                    :index="$index"
                    :shipment="$shipment"
                    :shipment-count="$shipmentCount"
                    :mode="$mode"
                    :countries="$countries"
                    :states-by-country="$statesByCountry"
                    :phone-codes="$phoneCodes"
                    :reference-number="$referenceNumber"
                    :has-saved-addresses="$hasSavedAddresses"
                />
            @endforeach
        </div>
    </div>

    @if($isMultipleAddressMode)
        <div class="ft-create-shipment-add-row">
            <button
                type="button"
                class="ft-create-shipment-add"
                wire:click="addCreateShipment"
                wire:loading.attr="disabled"
                wire:target="addCreateShipment"
                @disabled($rows->count() >= 20)
            >
                <span aria-hidden="true">+</span> Add shipment
            </button>
            <span class="ft-create-shipment-add-help">Add another package and configure its delivery address.</span>
        </div>
    @endif

    @error('createShipments')<small class="validation-error ft-create-shipping-table-error">{{ $message }}</small>@enderror

    @if($showSavedShippingAddressPicker)
        <div class="overlay livewire-overlay ft-order-saved-address-overlay" wire:click.self="closeSavedShippingAddressPicker"></div>
        <section
            class="modal livewire-modal ft-order-saved-address-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ft-saved-address-title"
            x-data
            x-on:keydown.escape.window="$wire.closeSavedShippingAddressPicker()"
        >
            <div class="ft-order-saved-address-modal-head">
                <div>
                    <h3 id="ft-saved-address-title">Saved shipping addresses</h3>
                    <p>Choose a saved delivery address for Shipment {{ $targetIndex + 1 }}.</p>
                </div>
                <button type="button" wire:click="closeSavedShippingAddressPicker" aria-label="Close saved address picker">&times;</button>
            </div>
            <div class="ft-order-saved-address-list">
                @forelse($savedShippingAddresses as $savedAddress)
                    <button
                        type="button"
                        class="ft-order-saved-address-card {{ (int) $targetSourceAddressId === (int) $savedAddress->id ? 'is-selected' : '' }}"
                        wire:click="applySavedShippingAddressToCreateShipment({{ $savedAddress->id }})"
                        wire:key="create-order-saved-address-{{ $targetIndex }}-{{ $savedAddress->id }}"
                    >
                        <span class="ft-order-saved-address-card-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6.5 4.5h11v15l-5.5-3.3-5.5 3.3v-15Z"/></svg>
                        </span>
                        <span class="ft-order-saved-address-copy">
                            <span class="ft-order-saved-address-label">
                                <strong>{{ $savedAddress->label ?: 'Shipping address' }}</strong>
                                @if($savedAddress->is_default)<em>Default</em>@endif
                            </span>
                            @if($savedAddress->recipient)<span>{{ $savedAddress->recipient }}</span>@endif
                            <span>{{ $savedAddress->address_line1 }}@if($savedAddress->suite), {{ $savedAddress->suite }}@endif</span>
                            <span>{{ collect([$savedAddress->city, $savedAddress->state, $savedAddress->zip])->filter()->implode(', ') }}</span>
                            <span>{{ $savedAddress->country }}</span>
                        </span>
                    </button>
                @empty
                    <div class="ft-empty-state">No saved shipping addresses are available for this client.</div>
                @endforelse
            </div>
        </section>
    @endif
</section>
