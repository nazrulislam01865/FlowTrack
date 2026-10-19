@props([
    'item' => [],
    'detail' => null,
    'supplier' => null,
    'supplierSkipped' => false,
    'index' => 0,
    'rowsProperty' => 'jobItems',
    'removeMethod' => 'removeProductRow',
    'rowKeyPrefix' => 'selected-order-product',
])

@php
    $itemImage = $detail?->productImageUrl();
    $itemCode = (string) ($detail?->code ?? '');
    $itemCategory = (string) ($detail?->parent?->name ?? ($item['category'] ?? ''));
    $itemName = (string) ($detail?->name ?? ($item['product'] ?? 'Product'));
    $quantityError = $errors->first("{$rowsProperty}.{$index}.quantity");
    $supplierError = $errors->first("{$rowsProperty}.{$index}.supplier_id");
    $supplierSkipped = (bool) $supplierSkipped;
    $unitPriceError = $errors->first("{$rowsProperty}.{$index}.unit_price");
    $notesError = $errors->first("{$rowsProperty}.{$index}.notes");
    $productError = $errors->first("{$rowsProperty}.{$index}.product");
@endphp

<article class="ft-order-selected-product-card" wire:key="{{ $rowKeyPrefix }}-{{ $item['product_id'] ?? $index }}-{{ $index }}">
    <header class="ft-order-selected-product-card-head">
        <div class="ft-order-selected-product-info ft-order-selected-product-card-info">
            <span class="ft-order-product-thumb">
                @if($itemImage)
                    <img src="{{ $itemImage }}" alt="">
                @else
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                @endif
            </span>
            <span>
                <strong title="{{ $itemName }}">{{ $itemName }}</strong>
                <small>SKU: {{ $itemCode ?: 'N/A' }} <i>&bull;</i> {{ $itemCategory ?: 'Uncategorized' }}</small>
                @if($productError)<small class="validation-error">{{ $productError }}</small>@endif
            </span>
        </div>

        <button type="button" class="ft-order-selected-product-card-remove" wire:click="{{ $removeMethod }}({{ $index }})" aria-label="Remove {{ $itemName }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M8 10v7M12 10v7M16 10v7M6 7l1 14h10l1-14"/></svg>
            <span>Remove</span>
        </button>
    </header>

    <div class="ft-order-selected-product-card-fields">
        <div class="ft-order-product-card-field is-supplier">
            <span class="ft-order-product-card-label">Supplier</span>
            @if($supplier)
                <div class="ft-order-product-supplier-readonly" aria-label="Supplier from product">
                    <span class="ft-order-product-supplier-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h10v10H4zM14 10h3l3 3v4h-6z"/><circle cx="8" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/></svg>
                    </span>
                    <span class="ft-order-product-supplier-copy">
                        <strong>{{ $supplier->supplierShortCode() }}</strong>
                        <small>From product</small>
                    </span>
                </div>
            @else
                <div class="ft-order-product-supplier-missing {{ $supplierSkipped ? 'is-skipped' : '' }}" role="{{ $supplierSkipped ? 'status' : 'alert' }}">
                    <span class="ft-order-product-supplier-missing-icon" aria-hidden="true">!</span>
                    <span>
                        @if($supplierSkipped)
                            <strong>Supplier skipped for now</strong>
                            <small>You can assign a supplier later from Order Details.</small>
                        @else
                            <strong>Supplier is not linked</strong>
                            <small>Link a supplier or choose the temporary skip option.</small>
                        @endif
                    </span>
                </div>
            @endif
            @if($supplierError)<small class="validation-error">{{ $supplierError }}</small>@endif
        </div>

        <div class="ft-order-product-card-field is-quantity">
            <span class="ft-order-product-card-label">Quantity</span>
            <div class="ft-order-product-card-stepper">
                <button type="button" wire:click="decrementCreateProductQuantity({{ $index }})" aria-label="Decrease quantity">&minus;</button>
                <input type="number" min="1" max="999999999" wire:model.live.debounce.300ms="{{ $rowsProperty }}.{{ $index }}.quantity" aria-label="Quantity for {{ $itemName }}">
                <button type="button" wire:click="incrementCreateProductQuantity({{ $index }})" aria-label="Increase quantity">+</button>
            </div>
            @if($quantityError)<small class="validation-error">{{ $quantityError }}</small>@endif
        </div>

        <label class="ft-order-product-card-field is-price">
            <span class="ft-order-product-card-label">Unit price <em>Optional</em></span>
            <input type="number" min="0" max="999999999999.99" step="0.01" wire:model.blur="{{ $rowsProperty }}.{{ $index }}.unit_price" placeholder="0.00" aria-label="Unit price for {{ $itemName }}">
            @if($unitPriceError)<small class="validation-error">{{ $unitPriceError }}</small>@endif
        </label>

        <label class="ft-order-product-card-field is-notes">
            <span class="ft-order-product-card-label">Notes <em>Optional</em></span>
            <input type="text" maxlength="2000" wire:model.blur="{{ $rowsProperty }}.{{ $index }}.notes" placeholder="Add notes for this product" aria-label="Notes for {{ $itemName }}">
            @if($notesError)<small class="validation-error">{{ $notesError }}</small>@endif
        </label>
    </div>
</article>
