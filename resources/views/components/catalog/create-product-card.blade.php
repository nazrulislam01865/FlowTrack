@props([
    'item' => [],
    'detail' => null,
    'supplier' => null,
    'supplierSkipped' => false,
    'supplierRequired' => false,
    'context' => 'order',
    'canViewSupplierPrices' => false,
    'index' => 0,
    'rowsProperty' => 'jobItems',
    'removeMethod' => 'removeProductRow',
    'rowKeyPrefix' => 'selected-product',
])

@php
    $itemImage = $detail?->productImageUrl();
    $itemCode = (string) ($detail?->productDisplayCode() ?? ($detail?->code ?? ''));
    $itemCategory = (string) ($detail?->parent?->name ?? ($item['category'] ?? ''));
    $itemName = (string) ($detail?->name ?? ($item['product'] ?? 'Product'));
    $quantityError = $errors->first("{$rowsProperty}.{$index}.quantity");
    $supplierError = $errors->first("{$rowsProperty}.{$index}.supplier_id");
    $unitPriceError = $errors->first("{$rowsProperty}.{$index}.unit_price");
    $productError = $errors->first("{$rowsProperty}.{$index}.product");
    $isOrder = $context === 'order';
    $priceFromProductTable = in_array($context, ['order', 'inquiry'], true);
    $supplierShortCode = trim((string) ($supplier?->supplierShortCode() ?? ''));
    $supplierLabel = $supplierShortCode !== '' ? $supplierShortCode : trim((string) ($supplier?->name ?? ''));
@endphp

<article class="ft-order-selected-product-card ft-create-product-card ft-product-quantity-selected-row {{ $isOrder ? 'has-extra-prices' : '' }}" wire:key="{{ $rowKeyPrefix }}-{{ $item['product_id'] ?? $index }}-{{ $index }}">
    <div class="ft-pq-selected-product">
        <span class="ft-order-product-thumb">
            @if($itemImage)
                <img src="{{ $itemImage }}" alt="">
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
            @endif
        </span>
        <span class="ft-pq-selected-product-copy">
            <strong title="{{ $itemName }}">{{ $itemName }}</strong>
            <small>{{ $itemCode ?: 'N/A' }} <i>&middot;</i> {{ $itemCategory ?: 'Uncategorized' }}</small>
            @if($productError)<small class="validation-error">{{ $productError }}</small>@endif
        </span>
    </div>

    <label class="ft-pq-selected-field ft-pq-selected-quantity">
        <span>Quantity</span>
        <input type="number" min="1" max="999999999" wire:model.live.debounce.300ms="{{ $rowsProperty }}.{{ $index }}.quantity" aria-label="Quantity for {{ $itemName }}">
        @if($quantityError)<small class="validation-error">{{ $quantityError }}</small>@endif
    </label>

    <div class="ft-pq-selected-field ft-pq-selected-supplier" x-data="{ changingSupplier: false }" x-on:create-order-product-supplier-selected.window="changingSupplier = false">
        <span>Supplier for this order</span>
        @if($isOrder && !$supplierRequired)
            <div class="ft-pq-supplier-box"><strong>{{ $supplier ? ($supplierLabel ?: '—') : 'No supplier selected' }}</strong></div>
        @elseif($isOrder)
            <div x-show="!changingSupplier" class="ft-pq-supplier-box {{ !$supplier ? 'is-missing' : '' }}">
                <strong>{{ $supplier ? ($supplierLabel ?: '—') : ($supplierSkipped ? 'Supplier skipped for now' : 'No default supplier linked') }}</strong>
                @if($supplier)<i>&middot;</i><b>Default</b>@endif
                <button type="button" x-on:click="changingSupplier = true">Change</button>
            </div>
            <div x-cloak x-show="changingSupplier" class="ft-pq-supplier-picker">
                <x-ui.search-select
                    label="Supplier"
                    type="suppliers"
                    context="create-order-product-supplier"
                    property="create-order-item-supplier:{{ $index }}"
                    :params="['product_id' => (int) ($item['product_id'] ?? 0)]"
                    :value="$supplier?->id"
                    :selected-label="$supplierLabel !== '' ? $supplierLabel : null"
                    placeholder="Select supplier"
                    search-placeholder="Search supplier"
                    :clearable="false"
                    action="updateCreateOrderProductSupplierFromSelector"
                    :menu-width="360"
                    :hide-label="true"
                    :fixed-menu="true"
                />
                <button type="button" class="ft-pq-supplier-cancel" x-on:click="changingSupplier = false" aria-label="Cancel supplier change">&times;</button>
            </div>
        @else
            <div class="ft-pq-supplier-box {{ !$supplier ? 'is-missing' : '' }}">
                <strong>{{ $supplier ? ($supplierLabel ?: '—') : 'No default supplier linked' }}</strong>
                @if($supplier)<i>&middot;</i><b>Default</b>@endif
            </div>
        @endif
        @if($supplierError)<small class="validation-error">{{ $supplierError }}</small>@endif
    </div>

    <label class="ft-pq-selected-field ft-pq-selected-price">
        <span>Unit price</span>
        <div class="ft-pq-price-input {{ $priceFromProductTable ? 'is-readonly' : '' }}">
            <b>$</b>
            @if($priceFromProductTable)
                <input
                    type="number"
                    min="0"
                    max="999999999999.99"
                    step="0.01"
                    value="{{ filled($item['unit_price'] ?? null) ? number_format((float) $item['unit_price'], 2, '.', '') : '' }}"
                    placeholder="0.00"
                    aria-label="Unit price for {{ $itemName }}"
                    readonly
                >
            @else
                <input type="number" min="0" max="999999999999.99" step="0.01" wire:model.blur="{{ $rowsProperty }}.{{ $index }}.unit_price" placeholder="0.00" aria-label="Unit price for {{ $itemName }}">
            @endif
        </div>
        @if($unitPriceError)<small class="validation-error">{{ $unitPriceError }}</small>@endif
    </label>

    @if($isOrder)
        <label class="ft-pq-selected-field ft-pq-selected-price ft-pq-supplier-price">
            <span>Supplier price</span>
            @if($canViewSupplierPrices)
                <div class="ft-pq-price-input is-readonly"><b>$</b>
                    <input type="text" readonly value="{{ filled($item['supplier_price'] ?? null) ? number_format((float) $item['supplier_price'], 2, '.', '') : '' }}" placeholder="Not set" aria-label="Supplier price for {{ $itemName }}">
                </div>
            @else
                <div class="ft-pq-masked-price" aria-label="Supplier price restricted" title="Supplier Prices → View permission required">•••••• <small>Restricted</small></div>
            @endif
        </label>
    @endif

    <button type="button" class="ft-pq-remove-product" wire:click="{{ $removeMethod }}({{ $index }})" aria-label="Remove {{ $itemName }}">Remove</button>
</article>
