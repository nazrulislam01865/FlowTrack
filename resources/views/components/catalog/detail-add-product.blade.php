@props([
    'wireKey',
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
    'unitPriceModel',
    'unitPriceValue' => '0.00',
    'supplierModel' => null,
    'supplierValue' => null,
    'supplierLabel' => '',
    'supplierLocked' => false,
    'supplierSkipped' => false,
    'supplierRequired' => false,
    'currencySymbol' => '$',
    'closeMethod',
    'saveMethod',
    'selectedErrorKey',
    'quantityErrorKey',
    'unitPriceErrorKey',
    'supplierErrorKey' => null,
    'missingSupplierMethod' => null,
    'showHeader' => true,
    'recordLabel' => 'Order',
])

@php
    $searchValue = trim((string) $searchValue);
    $selectedImage = $selectedProduct?->productImageUrl();
    $selectedCode = (string) ($selectedProduct?->productDisplayCode() ?? '');
    $selectedName = (string) ($selectedProduct?->name ?? 'Product');
    $selectedSupplierName = $supplierRequired
        ? trim((string) $supplierLabel)
        : trim((string) ($selectedSupplier?->supplierShortCode() ?? ''));
    $selectedSupplierExists = filled($selectedSupplierName) || filled($supplierValue);
    $supplierSkipped = (bool) $supplierSkipped;
    $supplierResolved = $selectedSupplierExists || $supplierSkipped;
    $selectedSupplierIsDefault = $supplierRequired
        && $selectedSupplier
        && (int) $supplierValue === (int) $selectedSupplier->id;
@endphp

<div
    class="ft-detail-add-product ft-detail-add-product--create-parity ft-product-quantity-prototype-match ft-product-quantity-prototype-exact"
    wire:key="{{ $wireKey }}"
    x-data="{ resultsOpen: false, changingSupplier: false }"
>
    @if($showHeader)
        <div class="ft-detail-add-product__parity-head">
            <div>
                <strong>Add product</strong>
                <span>Search the Product master and add the product using the same layout as Create {{ $recordLabel }}.</span>
            </div>
            <button type="button" class="ft-detail-add-product__parity-close" wire:click="{{ $closeMethod }}" aria-label="Close add product">&times;</button>
        </div>
    @endif

    <label class="ft-order-product-search-label">Search product</label>
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
                <button type="button" class="ft-order-product-search-clear" wire:click="$set('{{ $searchModel }}', '')" x-on:click="resultsOpen = false" aria-label="Clear product search">&times;</button>
            @endif
        </div>

        <div class="ft-order-product-results" x-cloak x-show="resultsOpen" x-transition.opacity.duration.120ms>
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
                        $previewPrice = $product->productPriceForQuantity(1000);
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
                                    @if($previewPrice !== null)<i>&middot;</i><span>${{ number_format((float) $previewPrice, 2) }} last price</span>@endif
                                @else
                                    <b class="ft-order-product-result-supplier-badge is-empty">—</b>
                                    <strong>No default supplier</strong>
                                @endif
                            </small>
                        </span>
                        <button type="button" class="ft-order-product-select-button {{ $isSelected ? 'is-selected' : '' }}" wire:click="{{ $selectMethod }}({{ $product->id }})" x-on:click="resultsOpen = false">Select</button>
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

    @if($selectedProduct)
        <div class="ft-order-selected-products ft-order-selected-products--cards">
            <div class="ft-order-selected-product-cards">
                <article class="ft-order-selected-product-card ft-create-product-card ft-product-quantity-selected-row">
                    <div class="ft-pq-selected-product">
                        <span class="ft-order-product-thumb">
                            @if($selectedImage)
                                <img src="{{ $selectedImage }}" alt="">
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                            @endif
                        </span>
                        <span class="ft-pq-selected-product-copy">
                            <strong title="{{ $selectedName }}">{{ $selectedName }}</strong>
                            <small>{{ $selectedCode ?: 'N/A' }} <i>&middot;</i> {{ $categoryValue ?: 'Uncategorized' }}</small>
                            @if($errors->has($selectedErrorKey))<small class="validation-error">{{ $errors->first($selectedErrorKey) }}</small>@endif
                        </span>
                    </div>

                    <label class="ft-pq-selected-field ft-pq-selected-quantity">
                        <span>Quantity</span>
                        <input type="number" min="1" max="999999999" step="1" wire:model.live.debounce.300ms="{{ $quantityModel }}" aria-label="Quantity for {{ $selectedName }}">
                        @if($errors->has($quantityErrorKey))<small class="validation-error">{{ $errors->first($quantityErrorKey) }}</small>@endif
                    </label>

                    <div class="ft-pq-selected-field ft-pq-selected-supplier" x-on:create-order-product-supplier-selected.window="changingSupplier = false">
                        <span>Supplier for this {{ strtolower($recordLabel) }}</span>
                        @if($supplierRequired)
                            <div x-show="!changingSupplier" class="ft-pq-supplier-box {{ !$supplierResolved ? 'is-missing' : '' }}">
                                <strong>{{ $selectedSupplierName ?: ($supplierSkipped ? 'Supplier skipped for now' : 'No default supplier linked') }}</strong>
                                @if($selectedSupplierExists)<i>&middot;</i><b>{{ $selectedSupplierIsDefault ? 'Default' : 'Selected' }}</b>@endif
                                @if($supplierSkipped)<i>&middot;</i><b>Assign later</b>@endif
                                <span class="ft-pq-supplier-actions">
                                    @if(!$selectedSupplierExists && $missingSupplierMethod)
                                        <button type="button" wire:click="{{ $missingSupplierMethod }}">Create supplier</button>
                                    @endif
                                    <button type="button" x-on:click="changingSupplier = true">Change</button>
                                </span>
                            </div>
                            <div x-cloak x-show="changingSupplier" class="ft-pq-supplier-picker">
                                <x-ui.search-select
                                    label="Supplier"
                                    type="suppliers"
                                    context="job-detail"
                                    property="{{ $supplierModel }}"
                                    :value="$supplierValue"
                                    :selected-label="$supplierLabel ?: null"
                                    placeholder="Select supplier"
                                    search-placeholder="Search supplier"
                                    :clearable="false"
                                    action="updateAddJobProductSupplierFromSelector"
                                    :menu-width="360"
                                    :hide-label="true"
                                    :fixed-menu="true"
                                />
                                <button type="button" class="ft-pq-supplier-cancel" x-on:click="changingSupplier = false" aria-label="Cancel supplier change">&times;</button>
                            </div>
                            @if($supplierErrorKey && $errors->has($supplierErrorKey))<small class="validation-error">{{ $errors->first($supplierErrorKey) }}</small>@endif
                        @else
                            <div class="ft-pq-supplier-box {{ !$selectedSupplierExists ? 'is-missing' : '' }}">
                                <strong>{{ $selectedSupplierName ?: 'No default supplier linked' }}</strong>
                                @if($selectedSupplierExists)<i>&middot;</i><b>Default</b>@endif
                                @if(!$selectedSupplierExists && $missingSupplierMethod)
                                    <span class="ft-pq-supplier-actions">
                                        <button type="button" wire:click="{{ $missingSupplierMethod }}">Create supplier</button>
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>

                    <label class="ft-pq-selected-field ft-pq-selected-price">
                        <span>Unit price</span>
                        <div class="ft-pq-price-input is-readonly">
                            <b>{{ $currencySymbol }}</b>
                            <input
                                type="number"
                                min="0"
                                max="999999999999.99"
                                step="0.01"
                                value="{{ filled($unitPriceValue) ? number_format((float) $unitPriceValue, 2, '.', '') : '' }}"
                                placeholder="0.00"
                                aria-label="Unit price for {{ $selectedName }}"
                                readonly
                            >
                        </div>
                        @if($errors->has($unitPriceErrorKey))<small class="validation-error">{{ $errors->first($unitPriceErrorKey) }}</small>@endif
                    </label>

                    <button type="button" class="ft-pq-remove-product" wire:click="{{ $closeMethod }}">Remove</button>
                </article>
            </div>
        </div>
    @else
        @if($errors->has($selectedErrorKey))<div class="validation-error ft-detail-add-product__selection-error">{{ $errors->first($selectedErrorKey) }}</div>@endif
    @endif

    <div class="ft-detail-add-product__parity-actions">
        <button type="button" class="ft-detail-add-product__cancel" wire:click="{{ $closeMethod }}">Cancel</button>
        <button type="button" class="ft-detail-add-product__submit" wire:click="{{ $saveMethod }}" wire:loading.attr="disabled" wire:target="{{ $saveMethod }}" @disabled(!$selectedProduct || ($supplierRequired && !$supplierResolved))>
            <span wire:loading.remove wire:target="{{ $saveMethod }}">Add product</span>
            <span wire:loading wire:target="{{ $saveMethod }}">Adding…</span>
        </button>
    </div>
</div>
