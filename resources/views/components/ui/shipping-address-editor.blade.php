@props([
    'index',
    'address' => [],
    'countries' => [],
    'countryFlags' => [],
    'states' => [],
    'canRemove' => false,
    'recipientRequired' => true,
])

@php
    $country = (string) ($address['country'] ?? '');
    $countryOptions = collect($countries)->map(fn ($countryOption) => [
        'id' => (string) $countryOption,
        'label' => (string) $countryOption,
        'meta' => (string) ($countryFlags[$countryOption] ?? ''),
    ])->values();
    $line2Visible = trim((string) ($address['suite'] ?? '')) !== '';
@endphp

<article
    class="ft-shipping-prototype-address"
    wire:key="shipping-prototype-address-{{ $index }}"
    x-data="{ showLine2: @js($line2Visible) }"
>
    @if($index > 0 || $canRemove)
        <div class="ft-shipping-prototype-address-head">
            <span>Shipping address {{ $index + 1 }}</span>
            @if($canRemove)
                <button type="button" class="ft-shipping-prototype-remove" wire:click="removeShippingAddress({{ $index }})">
                    Remove
                </button>
            @endif
        </div>
    @endif

    <div class="ft-client-grid ft-shipping-aligned-grid">
        <label class="ft-proto-field ft-shipping-recipient-field">
            <b>Recipient name @if($recipientRequired)<em>*</em>@endif</b>
            <input wire:model="shippingAddresses.{{ $index }}.recipient" placeholder="Recipient name">
            @error("shippingAddresses.$index.recipient")<small class="validation-error">{{ $message }}</small>@enderror
        </label>

        <div class="ft-proto-field ft-shipping-country-field">
            <b>Country / region <em>*</em></b>
            <x-ui.select-filter
                label="Country / region"
                :property="'shippingAddresses.'.$index.'.country'"
                :value="$country"
                placeholder="Select country / region"
                :options="$countryOptions"
                search-placeholder="Search country / region…"
                :menu-width="360"
                :fixed-menu="true"
                :hide-label="true"
                class="ft-form-search-select"
            />
            @error("shippingAddresses.$index.country")<small class="validation-error">{{ $message }}</small>@enderror
        </div>

        <label class="ft-proto-field ft-shipping-address-line1-field">
            <b>Address line 1 <em>*</em></b>
            <input wire:model="shippingAddresses.{{ $index }}.address_line1" placeholder="Street address">
            @error("shippingAddresses.$index.address_line1")<small class="validation-error">{{ $message }}</small>@enderror
        </label>

        <label class="ft-proto-field ft-shipping-address-line2-field" x-show="showLine2" x-cloak>
            <b>Address line 2 <span>(Optional)</span></b>
            <input wire:model="shippingAddresses.{{ $index }}.suite" placeholder="Apartment, suite, unit, building, floor, etc.">
            @error("shippingAddresses.$index.suite")<small class="validation-error">{{ $message }}</small>@enderror
        </label>

        <label class="ft-proto-field ft-shipping-city-field">
            <b>City <em>*</em></b>
            <input wire:model="shippingAddresses.{{ $index }}.city" placeholder="City">
            @error("shippingAddresses.$index.city")<small class="validation-error">{{ $message }}</small>@enderror
        </label>

        <div class="ft-proto-field ft-shipping-state-field">
            <b>State @if(count($states))<em>*</em>@endif</b>
            <x-ui.select-filter
                label="State"
                :property="'shippingAddresses.'.$index.'.state'"
                :value="$address['state'] ?? ''"
                :placeholder="empty($states) ? 'No states configured' : 'Select state'"
                :options="$states"
                :disabled="empty($states)"
                search-placeholder="Search state…"
                :menu-width="340"
                :fixed-menu="true"
                :hide-label="true"
                class="ft-form-search-select"
            />
            @error("shippingAddresses.$index.state")<small class="validation-error">{{ $message }}</small>@enderror
        </div>

        <label class="ft-proto-field ft-shipping-zip-field">
            <b>ZIP / postal code <em>*</em></b>
            <input wire:model="shippingAddresses.{{ $index }}.zip" placeholder="ZIP / postal code">
            @error("shippingAddresses.$index.zip")<small class="validation-error">{{ $message }}</small>@enderror
        </label>
    </div>

    <div class="ft-shipping-prototype-line2" x-show="!showLine2" x-cloak>
        <button type="button" x-on:click="showLine2 = true">
            <span aria-hidden="true">+</span> Add address line 2
        </button>
    </div>

    <div class="ft-shipping-prototype-save-row">
        <label class="ft-shipping-prototype-save">
            <input
                type="checkbox"
                @checked($address['is_default'] ?? false)
                wire:click="toggleSavedShippingAddress({{ $index }})"
                title="Use this as the client's saved default shipping address"
            >
            <span>
                <b>Save this address for this client</b>
                <small>Optional</small>
            </span>
        </label>
    </div>
</article>
