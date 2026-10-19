@props([
    'wireKey',
    'variant' => 'order',
    'recordLabel' => 'Order',
    'searchModel',
    'searchValue' => '',
    'searchResults' => collect(),
    'searchSuppliers' => collect(),
    'resultTotal' => 0,
    'showAllResults' => false,
    'showAllMethod',
    'selectMethod',
    'selectedProduct' => null,
    'selectedSupplier' => null,
    'categoryValue' => '',
    'quantityModel',
    'quantityValue' => 1,
    'unitPriceValue' => '0.00',
    'notesModel',
    'notesValue' => '',
    'supplierModel' => null,
    'supplierValue' => null,
    'supplierLabel' => '',
    'supplierEditable' => false,
    'supplierAction' => 'updateEditOrderProductSupplierFromSelector',
    'currencySymbol' => '$',
    'closeMethod',
    'saveMethod',
    'selectedErrorKey',
    'quantityErrorKey',
    'unitPriceErrorKey',
    'notesErrorKey',
    'supplierErrorKey' => null,
])

@php
    $searchValue = trim((string) $searchValue);
    $selectedImage = $selectedProduct?->productImageUrl();
    $selectedCode = (string) ($selectedProduct?->productDisplayCode() ?? '');
    $selectedReference = (string) ($selectedProduct?->productReferenceCode() ?? '');
    $selectedName = (string) ($selectedProduct?->name ?? '');
    $selectedSupplierName = trim((string) ($supplierLabel ?: $selectedSupplier?->supplierShortCode() ?: ''));
    $selectedSupplierExists = filled($selectedSupplierName) || filled($supplierValue);
@endphp

<x-catalog.detail-product-inline-editor
    :variant="$variant"
    title="Edit product"
    :product-name="$selectedName"
    meta="Search a product first. Select one to load its category, supplier and quantity-based base price from Product Master."
    class="ft-detail-product-inline-editor--product-first"
    wire:key="{{ $wireKey }}"
>
    <x-slot:close>
        <button type="button" class="ft-detail-product-inline-editor__close" wire:click="{{ $closeMethod }}" aria-label="Cancel editing">&times;</button>
    </x-slot:close>

    <div
        class="ft-detail-product-edit-flow"
        x-data="{ resultsOpen: false, changingSupplier: false }"
        x-on:detail-product-edit-selected.window="resultsOpen = false; changingSupplier = false"
        x-on:detail-product-edit-supplier-selected.window="changingSupplier = false"
    >
        <div class="ft-detail-product-edit-flow__search">
            <label class="ft-order-product-search-label">Search product <b>*</b></label>
            <div class="ft-order-product-search-host" x-on:click.outside="resultsOpen = false">
                <div class="ft-order-product-search-input" :class="resultsOpen ? 'is-open' : ''">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input
                        type="search"
                        wire:model.live.debounce.220ms="{{ $searchModel }}"
                        x-on:focus="resultsOpen = true"
                        x-on:input="resultsOpen = true"
                        x-on:keydown.escape="resultsOpen = false"
                        placeholder="Search product name, product code or reference code"
                        autocomplete="off"
                        aria-label="Search product"
                    >
                    @if($searchValue !== '')
                        <button type="button" class="ft-order-product-search-clear" wire:click="$set('{{ $searchModel }}', '')" x-on:click="resultsOpen = true" aria-label="Clear product search">&times;</button>
                    @endif
                </div>

                <div class="ft-order-product-results ft-detail-product-edit-flow__results" x-cloak x-show="resultsOpen" x-transition.opacity.duration.120ms>
                    <div class="ft-order-product-results-head">
                        <span>Top matches <i>&middot;</i> {{ number_format((int) $searchResults->count()) }} {{ \Illuminate\Support\Str::plural('result', (int) $searchResults->count()) }}</span>
                        @if(!$showAllResults && $resultTotal > 0)
                            <button type="button" wire:click="{{ $showAllMethod }}">View all results <span>&nearr;</span></button>
                        @endif
                    </div>

                    <div class="ft-order-product-result-list">
                        @forelse($searchResults as $product)
                            @continue($product->type !== 'product')
                            @php
                                $isSelected = (int) ($selectedProduct?->id ?? 0) === (int) $product->id;
                                $resultSupplier = $searchSuppliers->get((int) $product->id);
                                $supplierWords = preg_split('/\s+/', trim((string) ($resultSupplier?->name ?? ''))) ?: [];
                                $supplierInitials = $resultSupplier?->supplierShortCode() ?: strtoupper(substr(implode('', array_map(fn ($word) => substr($word, 0, 1), array_filter($supplierWords))), 0, 2));
                                $previewPrice = $product->productPriceForQuantity(max(1, (int) $quantityValue));
                                $leadDays = (int) (data_get($product->metadata, 'lead_time_days') ?: data_get($product->metadata, 'supplier_lead_time_days') ?: 0);
                                $isPreferred = (bool) (data_get($product->metadata, 'supplier_preferred') ?: data_get($product->metadata, 'preferred_supplier'));
                                $resultReferenceCode = $product->productReferenceCode();
                                $resultDisplayCode = $product->productDisplayCode();
                                $resultImageUrl = $product->productImageUrl();
                            @endphp
                            <div class="ft-order-product-result {{ $isSelected ? 'is-selected' : '' }}">
                                <span class="ft-order-product-thumb">
                                    @if($resultImageUrl)
                                        <img src="{{ $resultImageUrl }}" alt="">
                                    @else
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                                    @endif
                                </span>
                                <span class="ft-order-product-result-copy">
                                    <strong>{{ $product->name }}</strong>
                                    <span class="ft-order-product-code-line">Product code {{ $resultDisplayCode }} <i>&middot;</i> Ref {{ $resultReferenceCode ?: '—' }}</span>
                                    <small class="ft-order-product-result-supplier">
                                        @if($resultSupplier)
                                            <b class="ft-order-product-result-supplier-badge">{{ $supplierInitials ?: 'S' }}</b>
                                            <strong>{{ $resultSupplier->supplierShortCode() }}</strong>
                                            <span>Default</span>
                                            @if($leadDays > 0)<i>&middot;</i><span>{{ number_format($leadDays) }} days</span>@endif
                                            @if($isPreferred)<i>&middot;</i><span>Preferred</span>@endif
                                            @if($previewPrice !== null)<i>&middot;</i><span>{{ $currencySymbol }}{{ number_format((float) $previewPrice, 2) }}</span>@endif
                                        @else
                                            <b class="ft-order-product-result-supplier-badge is-empty">—</b>
                                            <strong>No default supplier</strong>
                                        @endif
                                    </small>
                                </span>
                                <button type="button" class="ft-order-product-select-button {{ $isSelected ? 'is-selected' : '' }}" wire:click="{{ $selectMethod }}({{ $product->id }})" x-on:click="resultsOpen = false">{{ $isSelected ? 'Selected' : 'Select' }}</button>
                            </div>
                        @empty
                            <div class="ft-order-product-no-results">
                                <strong>No products found</strong>
                                <span>Try another product name, product code or reference code.</span>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
            @if($errors->has($selectedErrorKey))
                <div class="validation-error ft-detail-product-edit-flow__selection-error">{{ $errors->first($selectedErrorKey) }}</div>
            @endif
        </div>

        @if($selectedProduct)
            <div class="ft-detail-product-edit-flow__selected">
                <div class="ft-detail-product-edit-flow__selected-head">
                    <span class="ft-order-product-thumb">
                        @if($selectedImage)
                            <img src="{{ $selectedImage }}" alt="">
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                        @endif
                    </span>
                    <span class="ft-detail-product-edit-flow__selected-copy">
                        <small>Selected product</small>
                        <strong>{{ $selectedName }}</strong>
                        <span>Product code {{ $selectedCode ?: '—' }} <i>&middot;</i> Ref {{ $selectedReference ?: '—' }}</span>
                    </span>
                </div>

                <div class="ft-detail-product-edit-flow__details">
                    <div class="ft-detail-product-edit-flow__readonly-field">
                        <span>Category</span>
                        <strong>{{ $categoryValue ?: 'Uncategorized' }}</strong>
                    </div>

                    <div class="ft-detail-product-edit-flow__supplier" x-data="{ changingSupplier: false }" x-on:detail-product-edit-supplier-selected.window="changingSupplier = false">
                        <span>Supplier</span>
                        @if($supplierEditable)
                            <div x-show="!changingSupplier" class="ft-pq-supplier-box {{ !$selectedSupplierExists ? 'is-missing' : '' }}">
                                <strong>{{ $selectedSupplierName ?: 'No default supplier linked' }}</strong>
                                @if($selectedSupplierExists)<i>&middot;</i><b>Selected</b>@endif
                                <button type="button" x-on:click="changingSupplier = true">Change</button>
                            </div>
                            <div x-cloak x-show="changingSupplier" class="ft-pq-supplier-picker">
                                <x-ui.search-select
                                    label="Supplier"
                                    type="suppliers"
                                    context="order-detail-product-edit"
                                    property="{{ $supplierModel }}"
                                    :value="$supplierValue"
                                    :selected-label="$supplierLabel ?: null"
                                    placeholder="Select supplier"
                                    search-placeholder="Search supplier"
                                    :clearable="false"
                                    action="{{ $supplierAction }}"
                                    :menu-width="360"
                                    :hide-label="true"
                                    :fixed-menu="true"
                                />
                                <button type="button" class="ft-pq-supplier-cancel" x-on:click="changingSupplier = false" aria-label="Cancel supplier change">&times;</button>
                            </div>
                            @if($supplierErrorKey && $errors->has($supplierErrorKey))
                                <small class="validation-error">{{ $errors->first($supplierErrorKey) }}</small>
                            @endif
                        @else
                            <div class="ft-detail-product-edit-flow__readonly-value {{ !$selectedSupplierExists ? 'is-missing' : '' }}">
                                <strong>{{ $selectedSupplierName ?: 'No default supplier linked' }}</strong>
                                @if($selectedSupplierExists)<span>Product Master default</span>@endif
                            </div>
                        @endif
                    </div>

                    <label class="ft-detail-product-edit-flow__input-field">
                        <span>Quantity <b>*</b></span>
                        <input type="number" min="1" max="999999999" step="1" wire:model.live.debounce.300ms="{{ $quantityModel }}" inputmode="numeric">
                        @if($errors->has($quantityErrorKey))<small class="validation-error">{{ $errors->first($quantityErrorKey) }}</small>@endif
                    </label>

                    <label class="ft-detail-product-edit-flow__input-field">
                        <span>Unit price</span>
                        <div class="ft-detail-product-inline-editor__price is-readonly">
                            <i>{{ $currencySymbol }}</i>
                            <input type="number" value="{{ filled($unitPriceValue) ? number_format((float) $unitPriceValue, 2, '.', '') : '0.00' }}" readonly tabindex="-1" aria-label="Unit price">
                        </div>
                        @if($errors->has($unitPriceErrorKey))<small class="validation-error">{{ $errors->first($unitPriceErrorKey) }}</small>@endif
                    </label>
                </div>

                <label class="ft-detail-product-edit-flow__notes">
                    <span>Notes</span>
                    <input type="text" maxlength="2000" wire:model="{{ $notesModel }}" placeholder="Add product notes">
                    @if($errors->has($notesErrorKey))<small class="validation-error">{{ $errors->first($notesErrorKey) }}</small>@endif
                </label>
            </div>
        @else
            <div class="ft-detail-product-edit-flow__empty">
                <strong>Select a product to continue</strong>
                <span>Category, supplier and unit price will appear automatically after you select a Product Master record.</span>
            </div>
        @endif
    </div>

    <x-slot:actions>
        <button type="button" class="ft-detail-product-inline-editor__cancel" wire:click="{{ $closeMethod }}" wire:loading.attr="disabled" wire:target="{{ $saveMethod }}">Cancel</button>
        <button
            type="button"
            class="ft-detail-product-inline-editor__save"
            wire:click="{{ $saveMethod }}"
            wire:loading.attr="disabled"
            wire:target="{{ $saveMethod }}"
            @disabled(!$selectedProduct || ($supplierEditable && !$supplierValue))
        >
            <span aria-hidden="true">&#10003;</span>
            <span wire:loading.remove wire:target="{{ $saveMethod }}">Save changes</span>
            <span wire:loading wire:target="{{ $saveMethod }}">Saving...</span>
        </button>
    </x-slot:actions>
</x-catalog.detail-product-inline-editor>
