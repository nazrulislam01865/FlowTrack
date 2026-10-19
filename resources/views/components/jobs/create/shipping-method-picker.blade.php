@props([
    'shipmentMethods' => collect(),
    'shipmentUrgencies' => collect(),
    'selectedMethodIds' => [],
    'selectedUrgencyIds' => [],
    'shipmentIndex' => null,
    'selectedMethodId' => null,
    'selectedUrgencyId' => null,
    'compact' => false,
    'required' => false,
    'helpText' => null,
])

@php
    $shippingPresenter = \App\Support\CreateOrderShippingMethodPresenter::class;
    $methods = collect($shipmentMethods)->values();
    $urgencies = collect($shipmentUrgencies)->values();
    $directMethods = $shippingPresenter::directMethods($methods);
    $expressMethod = $shippingPresenter::expressMethod($methods);
    $expressUrgencies = $shippingPresenter::expressUrgencies($urgencies);
    $methodIds = $shipmentIndex !== null
        ? (filled($selectedMethodId) ? [(int) $selectedMethodId] : [])
        : (array) $selectedMethodIds;
    $urgencyIds = $shipmentIndex !== null
        ? (filled($selectedUrgencyId) ? [(int) $selectedUrgencyId] : [])
        : (array) $selectedUrgencyIds;
    $selectedCard = $shippingPresenter::selectedCard($methods, $urgencies, $methodIds, $urgencyIds);
    $hasOptions = $directMethods->isNotEmpty() || $expressMethod;
    $validationPrefix = $shipmentIndex !== null ? 'createShipments.'.$shipmentIndex : null;
@endphp

<div
    class="ft-create-field ft-create-shipping-method-field {{ $compact ? 'ft-create-shipping-method-field--compact' : '' }}"
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
>
    @unless($compact)<b>Shipping method @if($required)<span class="ft-order-required-star" aria-hidden="true">*</span>@endif</b>@endunless

    @if($hasOptions)
        <div class="ft-create-shipping-picker">
            @if($selectedCard)
                <button
                    type="button"
                    class="ft-create-shipping-selected-card"
                    :class="open ? 'is-open' : ''"
                    x-on:click="open = !open"
                    :aria-expanded="open.toString()"
                    aria-haspopup="listbox"
                    aria-label="Change {{ $selectedCard['title'] }}"
                >
                    <span class="ft-create-shipping-option-icon">
                        <x-jobs.create.shipping-method-icon :type="$selectedCard['kind']" />
                    </span>
                    <span class="ft-create-shipping-selected-copy"><strong>{{ $selectedCard['title'] }}</strong></span>
                    <svg class="ft-create-shipping-chevron" :class="open ? 'is-open' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                </button>
            @else
                <button
                    type="button"
                    class="ft-create-shipping-trigger"
                    :class="open ? 'is-open' : ''"
                    x-on:click="open = !open"
                    :aria-expanded="open.toString()"
                    aria-haspopup="listbox"
                >
                    <span>Select shipping method</span>
                    <svg class="ft-create-shipping-chevron" :class="open ? 'is-open' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                </button>
            @endif

            <div class="ft-create-shipping-menu" x-cloak x-show="open" x-transition.opacity.duration.120ms role="listbox" aria-label="Shipping methods">
                @foreach($directMethods as $method)
                    @php
                        $kind = $shippingPresenter::methodKind($method);
                        $label = $shippingPresenter::methodLabel($method);
                    @endphp
                    <button
                        type="button"
                        class="ft-create-shipping-option"
                        role="option"
                        @if($shipmentIndex !== null)
                            wire:click="selectCreateShipmentMethod({{ (int) $shipmentIndex }}, {{ (int) $method->id }}, null)"
                        @else
                            wire:click="selectCreateShippingMethod({{ (int) $method->id }}, null)"
                        @endif
                        x-on:click="open = false"
                        aria-selected="{{ (int) ($selectedCard['method_id'] ?? 0) === (int) $method->id ? 'true' : 'false' }}"
                    >
                        <span class="ft-create-shipping-option-icon"><x-jobs.create.shipping-method-icon :type="$kind" /></span>
                        <span class="ft-create-shipping-option-copy"><strong>{{ $label }}</strong></span>
                    </button>
                @endforeach

                @if($expressMethod)
                    <div class="ft-create-shipping-express-group">
                        <div class="ft-create-shipping-express-heading" role="presentation">
                            <span>STANDARD EXPRESS SHIPPING</span>
                            <span class="ft-create-shipping-info" title="Choose a service level for Standard Express Shipping." aria-label="Standard express shipping information">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10" cy="10" r="7.2"/><path d="M10 8.7v4.4M10 6.2h.01"/></svg>
                            </span>
                        </div>

                        @foreach($expressUrgencies as $expressUrgency)
                            <button
                                type="button"
                                class="ft-create-shipping-option ft-create-shipping-option--express"
                                role="option"
                                @if($shipmentIndex !== null)
                                    wire:click="selectCreateShipmentMethod({{ (int) $shipmentIndex }}, {{ (int) $expressMethod->id }}, {{ $expressUrgency['id'] === null ? 'null' : (int) $expressUrgency['id'] }})"
                                @else
                                    wire:click="selectCreateShippingMethod({{ (int) $expressMethod->id }}, {{ $expressUrgency['id'] === null ? 'null' : (int) $expressUrgency['id'] }})"
                                @endif
                                x-on:click="open = false"
                                aria-selected="{{ (int) ($selectedCard['method_id'] ?? 0) === (int) $expressMethod->id && ($selectedCard['urgency_id'] ?? null) === $expressUrgency['id'] ? 'true' : 'false' }}"
                            >
                                <span class="ft-create-shipping-option-icon"><x-jobs.create.shipping-method-icon type="express" /></span>
                                <span class="ft-create-shipping-option-copy ft-create-shipping-option-copy--inline"><strong>{{ $expressUrgency['name'] }}</strong></span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @else
        <small>No active Shipment Methods are available in Master Data.</small>
    @endif

    @if(filled($helpText))
        <small>{{ $helpText }}</small>
    @endif

    @if($validationPrefix)
        @error($validationPrefix.'.shipment_method_id')<small class="validation-error">{{ $message }}</small>@enderror
        @error($validationPrefix.'.shipment_urgency_id')<small class="validation-error">{{ $message }}</small>@enderror
    @else
        @error('shipmentMethodIds')<small class="validation-error">{{ $message }}</small>@enderror
        @error('shipmentMethodIds.*')<small class="validation-error">{{ $message }}</small>@enderror
        @error('shipmentUrgencyIds')<small class="validation-error">{{ $message }}</small>@enderror
        @error('shipmentUrgencyIds.*')<small class="validation-error">{{ $message }}</small>@enderror
    @endif
</div>
