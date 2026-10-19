@props([
    'index',
    'shipment' => [],
    'shipmentCount' => 1,
    'mode' => 'multiple_shipments',
    'countries' => collect(),
    'statesByCountry' => collect(),
    'phoneCodes' => collect(),
    'referenceNumber' => '',
    'hasSavedAddresses' => false,
])

@php
    $shipmentNumber = (int) $index + 1;
    $sameAddressLocked = $mode === \App\Services\OrderShipmentService::MODE_SAME_ADDRESS && (int) $index > 0;

    $sharedContact = collect([
        trim((string) ($shipment['contact_name'] ?? '')),
        trim((string) ($shipment['phone_country_code'] ?? '')).' '.trim((string) ($shipment['phone'] ?? '')),
    ])->map(fn ($value) => trim((string) $value))->filter()->implode(' · ');
    $sharedAddress = collect([
        trim((string) ($shipment['address'] ?? '')),
        trim((string) ($shipment['postal_code'] ?? '')),
    ])->filter()->implode(' · ');
@endphp

<article
    class="ft-create-shipment-card {{ $sameAddressLocked ? 'is-shared-address' : '' }}"
    wire:key="create-shipment-row-{{ $index }}"
    data-ft-ui-component="create-order-shipment-card"
>
    <header class="ft-create-shipment-card-head">
        <div class="ft-create-shipment-card-title">
            <div>
                <strong>Shipment {{ $shipmentNumber }}</strong>
                <small>{{ $sameAddressLocked ? 'Uses Shipment 1 delivery details' : 'Enter delivery details' }}</small>
            </div>
        </div>

        <div class="ft-create-shipment-card-actions">
            @if((int) $index === 0)
                <span class="ft-create-shipment-primary-badge">Primary</span>
            @endif
            <button
                type="button"
                class="ft-create-shipment-remove {{ (int) $index === 0 ? 'is-disabled' : '' }}"
                @if((int) $index > 0)
                    wire:click="removeCreateShipment({{ $index }})"
                    wire:loading.attr="disabled"
                    wire:target="removeCreateShipment"
                @else
                    disabled
                @endif
                aria-label="{{ (int) $index === 0 ? 'Primary shipment cannot be removed' : 'Remove shipment '.$shipmentNumber }}"
                title="{{ (int) $index === 0 ? 'Primary shipment cannot be removed' : 'Remove shipment' }}"
            >
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path d="M4.5 6h11M8 3.5h4M6.5 6l.6 10h5.8l.6-10M8.5 8.5v5M11.5 8.5v5"/>
                </svg>
            </button>
        </div>
    </header>

    @if($sameAddressLocked)
        <div class="ft-create-shipment-shared-address" aria-label="Shipment {{ $shipmentNumber }} uses the same delivery address as Shipment 1">
            <span class="ft-create-shipment-shared-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4.5 8.5 10 4l5.5 4.5V16H4.5V8.5Z"/><path d="M7.5 16v-4h5v4"/></svg>
            </span>
            <span class="ft-create-shipment-shared-copy">
                <strong>Same delivery address as Shipment 1</strong>
                <span>{{ $sharedContact !== '' ? $sharedContact : 'Contact details come from Shipment 1.' }}</span>
                <span>{{ $sharedAddress !== '' ? $sharedAddress : 'Complete Shipment 1 delivery address above.' }}</span>
            </span>
        </div>
    @else
        <div class="ft-create-shipment-body">
            <div class="ft-create-shipment-primary-grid">
                <label class="ft-create-field ft-create-shipment-field">
                    <b>Contact person <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                    <input
                        type="text"
                        wire:model.blur="createShipments.{{ $index }}.contact_name"
                        maxlength="255"
                        autocomplete="name"
                        placeholder="e.g. John Smith"
                    >
                    @error("createShipments.$index.contact_name")<small class="validation-error">{{ $message }}</small>@enderror
                </label>

                <div class="ft-create-field ft-create-shipment-field">
                    <b>Phone <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                    <div class="ft-create-shipment-phone-row">
                        <div class="ft-create-shipment-phone-control ft-create-shipment-phone-code">
                            <x-ui.search-select
                                class="ft-create-shipment-phone-code-select"
                                label="Phone country code for shipment {{ $shipmentNumber }}"
                                property="createShipments.{{ $index }}.phone_country_code"
                                :value="$shipment['phone_country_code'] ?? ''"
                                :options="$phoneCodes"
                                placeholder="+Code"
                                :selected-label="($shipment['phone_country_code'] ?? '') ?: null"
                                :clearable="false"
                                :hide-label="true"
                                :fixed-menu="true"
                                :menu-width="300"
                                search-placeholder="Search code or country…"
                                wire:key="create-shipment-phone-code-{{ $index }}-{{ $shipment['phone_country_code'] ?? 'none' }}"
                            />
                            @error("createShipments.$index.phone_country_code")<small class="validation-error">{{ $message }}</small>@enderror
                        </div>
                        <div class="ft-create-shipment-phone-control ft-create-shipment-phone-number">
                            <input
                                type="text"
                                wire:model.blur="createShipments.{{ $index }}.phone"
                                maxlength="60"
                                inputmode="tel"
                                autocomplete="tel"
                                placeholder="e.g. 555-123-4567"
                                aria-label="Phone for shipment {{ $shipmentNumber }}"
                            >
                            @error("createShipments.$index.phone")<small class="validation-error">{{ $message }}</small>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="ft-create-shipment-address-block">
                <div class="ft-create-field ft-create-shipment-field ft-create-shipment-address-field">
                    <b>Shipping address <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                    <button type="button" class="ft-create-shipment-saved-address" wire:click="openSavedShippingAddressPickerForShipment({{ $index }})">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5.5 3.5h9v13l-4.5-2.6-4.5 2.6v-13Z"/></svg>
                        Use saved address
                    </button>
                    <input
                        class="ft-create-shipment-address-input"
                        type="text"
                        wire:model.blur="createShipments.{{ $index }}.address"
                        maxlength="2000"
                        autocomplete="street-address"
                        placeholder="Street address, suite, building, etc."
                        aria-label="Shipping address for shipment {{ $shipmentNumber }}"
                    >
                    @error("createShipments.$index.address")<small class="validation-error ft-create-shipment-address-error">{{ $message }}</small>@enderror
                </div>

                <label class="ft-create-field ft-create-shipment-field ft-create-shipment-postal-field">
                    <b>Postal code <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                    <input
                        type="text"
                        wire:model.blur="createShipments.{{ $index }}.postal_code"
                        maxlength="30"
                        autocomplete="postal-code"
                        placeholder="e.g. 27510-2461"
                    >
                    @error("createShipments.$index.postal_code")<small class="validation-error">{{ $message }}</small>@enderror
                </label>
            </div>
        </div>
    @endif
</article>
