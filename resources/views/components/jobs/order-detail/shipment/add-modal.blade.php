@props([
    'job',
    'task',
    'presentation' => [],
    'editingId' => null,
    'mode' => 'same_address',
    'form' => [],
    'showSavedAddressPicker' => false,
])

@php
    $isEditing = filled($editingId);
    $isSameAddress = $mode === \App\Services\OrderShipmentService::MODE_SAME_ADDRESS;
    $primary = $presentation['primary_shipment'] ?? null;
    $editingRow = $isEditing
        ? collect($presentation['shipments'] ?? [])->firstWhere('id', (int) $editingId)
        : null;
    $isPrimaryEdit = (bool) data_get($editingRow, 'is_primary', false);
    $sequence = $isEditing
        ? data_get($editingRow, 'sequence', '')
        : ($presentation['next_sequence'] ?? 2);
    $phoneCodes = collect($presentation['phone_country_codes'] ?? [])->values();
    $savedAddresses = collect($presentation['saved_shipping_addresses'] ?? [])->values();
    $shipmentMethods = collect($presentation['shipment_methods'] ?? [])->values();
    $shipmentUrgencies = collect($presentation['shipment_urgencies'] ?? [])->values();
    $selectedShipmentMethodId = filled($form['shipment_method_id'] ?? null)
        ? (int) $form['shipment_method_id']
        : null;
    $selectedShipmentUrgencyId = filled($form['shipment_urgency_id'] ?? null)
        ? (int) $form['shipment_urgency_id']
        : null;
    $selectedShipmentMethod = $selectedShipmentMethodId
        ? \App\Support\CreateOrderShippingMethodPresenter::selectedCard(
            $shipmentMethods,
            $shipmentUrgencies,
            [$selectedShipmentMethodId],
            $selectedShipmentUrgencyId ? [$selectedShipmentUrgencyId] : [],
        )
        : null;

    if (! $selectedShipmentMethod
        && $isEditing
        && $selectedShipmentMethodId
        && (int) data_get($editingRow, 'shipment_method_id', 0) === $selectedShipmentMethodId) {
        $selectedShipmentMethod = data_get($editingRow, 'method_card');
    }
@endphp

<div class="ft-ms-modal-backdrop" role="presentation" wire:key="shipment-modal-{{ $task->id }}-{{ $editingId ?: 'new' }}">
    <section
        class="ft-ms-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="shipment-modal-title"
        x-data
        x-on:keydown.escape.window="$wire.closeShipmentModal()"
    >
        <header class="ft-ms-modal__head">
            <div>
                <h2 id="shipment-modal-title">{{ $isEditing ? 'Edit Shipment '.$sequence : 'Add Shipment' }}</h2>
                <p>
                    {{ $isEditing
                        ? 'Update this shipment\'s delivery address.'
                        : 'Add another shipment delivery address.' }}
                </p>
            </div>
            <button type="button" class="ft-ms-modal__close" wire:click="closeShipmentModal" aria-label="Close">×</button>
        </header>

        <div class="ft-ms-modal__body">
            @if($isSameAddress && $primary && ! $isPrimaryEdit)
                <div class="ft-ms-same-address ft-ms-same-address--compact">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="10" cy="6.5" r="2.5"/><path d="M5.5 16v-2.2a4.5 4.5 0 0 1 9 0V16"/></svg>
                    <div class="ft-ms-same-address__copy">
                        <strong>Uses Shipment 1 delivery address</strong>
                        <p>{{ $primary['recipient'] ?: 'Contact person not set' }}@if($primary['phone']) <span>•</span> {{ $primary['phone'] }}@endif</p>
                        <small>
                            {{ $primary['address'] ?: 'Address not set' }}
                            @if($primary['postal_code']) · {{ $primary['postal_code'] }} @endif
                        </small>
                    </div>
                    <button type="button" class="ft-ms-use-different-address" wire:click="setShipmentModalAddressMode('multiple_address')">
                        Use different address
                    </button>
                </div>
            @else
                <div class="ft-ms-shipment-address-form ft-form-standard ft-form-standard--order" data-ft-ui-component="shipment-address-fields">
                    <div class="ft-ms-shipment-address-primary-grid ft-create-shipment-primary-grid">
                        <label class="ft-create-field ft-ms-shipment-address-field ft-create-shipment-field">
                            <b>Contact person <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                            <input
                                type="text"
                                wire:model.blur="shipmentForm.recipient"
                                maxlength="255"
                                autocomplete="name"
                                placeholder="e.g. John Smith"
                                aria-required="true"
                            >
                            @error('shipmentForm.recipient')<small class="validation-error">{{ $message }}</small>@enderror
                        </label>

                        <div class="ft-create-field ft-ms-shipment-address-field ft-create-shipment-field">
                            <b>Phone <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                            <div class="ft-ms-shipment-phone-row ft-create-shipment-phone-row">
                                <div class="ft-ms-shipment-phone-control ft-ms-shipment-phone-code ft-create-shipment-phone-control ft-create-shipment-phone-code">
                                    <x-ui.search-select
                                        class="ft-ms-shipment-phone-code-select ft-create-shipment-phone-code-select"
                                        label="Phone country code for shipment {{ $sequence }}"
                                        property="shipmentForm.phone_country_code"
                                        :value="$form['phone_country_code'] ?? ''"
                                        :options="$phoneCodes"
                                        placeholder="+Code"
                                        :selected-label="($form['phone_country_code'] ?? '') ?: null"
                                        :clearable="false"
                                        :hide-label="true"
                                        :fixed-menu="true"
                                        :menu-width="300"
                                        search-placeholder="Search code or country…"
                                        wire:key="shipment-modal-phone-code-{{ $task->id }}-{{ $editingId ?: 'new' }}-{{ $form['phone_country_code'] ?? 'none' }}"
                                    />
                                    @error('shipmentForm.phone_country_code')<small class="validation-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="ft-ms-shipment-phone-control ft-ms-shipment-phone-number ft-create-shipment-phone-control ft-create-shipment-phone-number">
                                    <input
                                        type="text"
                                        wire:model.blur="shipmentForm.phone"
                                        maxlength="80"
                                        inputmode="tel"
                                        autocomplete="tel"
                                        placeholder="e.g. 555-123-4567"
                                        aria-label="Phone for shipment {{ $sequence }}"
                                        aria-required="true"
                                    >
                                    @error('shipmentForm.phone')<small class="validation-error">{{ $message }}</small>@enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ft-ms-shipment-address-block ft-create-shipment-address-block">
                        <div class="ft-create-field ft-ms-shipment-address-field ft-ms-shipment-address-input-field ft-create-shipment-field ft-create-shipment-address-field">
                            <b>Shipping address <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                            <button type="button" class="ft-ms-shipment-saved-address ft-create-shipment-saved-address" wire:click="openShipmentSavedAddressPicker">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5.5 3.5h9v13l-4.5-2.6-4.5 2.6v-13Z"/></svg>
                                Use saved address
                            </button>
                            <input
                                class="ft-ms-shipment-address-input ft-create-shipment-address-input"
                                type="text"
                                wire:model.blur="shipmentForm.address"
                                maxlength="2000"
                                autocomplete="street-address"
                                placeholder="Street address, suite, building, etc."
                                aria-label="Shipping address for shipment {{ $sequence }}"
                                aria-required="true"
                            >
                            @error('shipmentForm.address')<small class="validation-error ft-ms-shipment-address-error ft-create-shipment-address-error">{{ $message }}</small>@enderror
                        </div>

                        <label class="ft-create-field ft-ms-shipment-address-field ft-ms-shipment-postal-field ft-create-shipment-field ft-create-shipment-postal-field">
                            <b>Postal code <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                            <input
                                type="text"
                                wire:model.blur="shipmentForm.postal_code"
                                maxlength="30"
                                autocomplete="postal-code"
                                placeholder="e.g. 27510-2461"
                                aria-required="true"
                            >
                            @error('shipmentForm.postal_code')<small class="validation-error">{{ $message }}</small>@enderror
                        </label>
                    </div>
                </div>
            @endif

            <div class="ft-form-standard ft-form-standard--order ft-create-field ft-ms-shipment-address-field ft-ms-shipment-method-field ft-create-shipment-field">
                <b>Shipping method <span class="ft-order-required-star" aria-hidden="true">*</span></b>
                <x-jobs.order-detail.shipment.method-picker
                    :selected="$selectedShipmentMethod"
                    :methods="$shipmentMethods"
                    :urgencies="$shipmentUrgencies"
                    :task-id="$task->id"
                    :shipment-id="$editingId"
                    mode="modal"
                    appearance="field"
                />
                @error('shipmentMethod')<small class="validation-error">{{ $message }}</small>@enderror
            </div>
        </div>

        <footer class="ft-ms-modal__footer">
            <button type="button" class="ft-ms-outline-btn" wire:click="closeShipmentModal">Cancel</button>
            <button type="button" class="ft-ms-primary-btn" wire:click="saveShipment" wire:loading.attr="disabled" wire:target="saveShipment">
                {{ $isEditing ? 'Save changes' : 'Add shipment' }}
            </button>
        </footer>
    </section>

    @if($showSavedAddressPicker)
        <div class="overlay livewire-overlay ft-order-saved-address-overlay ft-ms-saved-address-overlay" wire:click.self="closeShipmentSavedAddressPicker"></div>
        <section
            class="ft-order-saved-address-modal ft-ms-saved-address-modal ft-form-standard ft-form-standard--order"
            role="dialog"
            aria-modal="true"
            aria-labelledby="shipment-saved-address-title"
            x-data
            x-on:keydown.escape.window="$wire.closeShipmentSavedAddressPicker()"
        >
            <div class="ft-order-saved-address-modal-head">
                <div>
                    <h3 id="shipment-saved-address-title">Use saved address</h3>
                    <p>Select a saved client shipping address for Shipment {{ $sequence }}.</p>
                </div>
                <button type="button" wire:click="closeShipmentSavedAddressPicker" aria-label="Close saved address picker">&times;</button>
            </div>

            <div class="ft-order-saved-address-list">
                @forelse($savedAddresses as $savedAddress)
                    <button
                        type="button"
                        class="ft-order-saved-address-card {{ (int) data_get($form, 'shipping_source_address_id', 0) === (int) data_get($savedAddress, 'id', 0) ? 'is-selected' : '' }}"
                        wire:click="applyShipmentSavedAddress({{ (int) data_get($savedAddress, 'id') }})"
                        wire:key="shipment-modal-saved-address-{{ $task->id }}-{{ (int) data_get($savedAddress, 'id') }}"
                    >
                        <span class="ft-order-saved-address-card-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6.5 4.5h11v15l-5.5-3.3-5.5 3.3v-15Z"/></svg>
                        </span>
                        <span class="ft-order-saved-address-copy">
                            <span class="ft-order-saved-address-label">
                                <strong>{{ data_get($savedAddress, 'label') ?: 'Shipping address' }}</strong>
                                @if(data_get($savedAddress, 'is_default'))<em>Default</em>@endif
                            </span>
                            @if(data_get($savedAddress, 'recipient'))<span>{{ data_get($savedAddress, 'recipient') }}</span>@endif
                            <span>{{ data_get($savedAddress, 'address_line1') }}@if(data_get($savedAddress, 'suite')), {{ data_get($savedAddress, 'suite') }}@endif</span>
                            <span>{{ collect([data_get($savedAddress, 'city'), data_get($savedAddress, 'state'), data_get($savedAddress, 'zip')])->filter()->implode(', ') }}</span>
                            @if(data_get($savedAddress, 'country'))<span>{{ data_get($savedAddress, 'country') }}</span>@endif
                        </span>
                        <span class="ft-order-saved-address-use">Use address</span>
                    </button>
                @empty
                    <div class="ft-order-saved-address-empty">No saved shipping addresses are available for this client.</div>
                @endforelse
            </div>
        </section>
    @endif
</div>
