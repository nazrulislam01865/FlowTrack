@props([
    'rows' => [],
    'rowsProperty' => 'createProductRows',
    'removeMethod' => 'removeCreateProductRow',
    'required' => false,
    'activeProductCount' => 0,
    'productSearchResults' => collect(),
    'productSearchSuppliers' => collect(),
    'productResultTotal' => 0,
    'createProductSearch' => '',
    'createProductShowAllResults' => false,
    'selectedProductDetails' => collect(),
    'selectedProductSuppliers' => collect(),
    'supplierSkippedProductIds' => [],
    'canCreateCatalogProduct' => false,
    'wireKey' => 'shared-create-products',
    'rowKeyPrefix' => 'shared-selected-product',
    'requiredErrorField' => null,
    'step' => 2,
    'supplierRequired' => false,
    'context' => 'order',
    'clientId' => null,
    'canViewSupplierPrices' => false,
])

@php
    $selectedIds = collect($rows)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
    $resultCount = $productSearchResults->count();
    $productSearchValue = trim((string) $createProductSearch);
    $totalUnits = collect($rows)->sum(fn ($item) => (int) ($item['quantity'] ?? 0));
    $sectionError = $requiredErrorField ? $errors->first($requiredErrorField) : null;
    $showCreateProductSuggestion = $productSearchValue !== '' && (int) $productResultTotal === 0;
@endphp

<section
    class="section ft-inquiry-create-section ft-create-section ft-order-products-prototype ft-inquiry-prototype ft-product-quantity-prototype-match ft-product-quantity-prototype-exact"
    wire:key="{{ $wireKey }}"
    x-data="{
        resultsOpen: @js($productSearchValue !== ''),
        openProductResults() { this.resultsOpen = true },
        closeProductResults() { if (!@js($productSearchValue !== '')) this.resultsOpen = false }
    }"
    x-on:create-order-product-selected.window="resultsOpen = true"
    x-on:focus-create-order-product-search.window="$nextTick(() => { openProductResults(); $refs.productSearch?.focus(); })"
    x-on:open-create-order-product-results.window="$nextTick(() => openProductResults())"
>
    <div class="ft-order-products-title-row">
        <div class="ft-create-section-title ft-order-products-title">
            <span>{{ $step }}</span>
            <h2>Products &amp; quantities</h2>
            @if($required)
                <em class="is-required">Required</em>
            @else
                <em>Optional</em>
            @endif
        </div>
        <p>Search all {{ number_format((int) $activeProductCount) }} products &mdash; the default supplier is shown before selection.</p>
        @if($sectionError)
            <div class="validation-error ft-order-products-section-error">{{ $sectionError }}</div>
        @endif
    </div>

    <label class="ft-order-product-search-label" for="{{ $wireKey }}-search">Search product</label>
    <div class="ft-order-product-search-host" x-on:click.outside="closeProductResults()">
        <div class="ft-order-product-search-input" :class="resultsOpen ? 'is-open' : ''">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input
                id="{{ $wireKey }}-search"
                x-ref="productSearch"
                type="search"
                wire:model.live.debounce.220ms="createProductSearch"
                x-on:focus="openProductResults()"
                x-on:input="openProductResults()"
                x-on:keydown.escape="resultsOpen = false"
                placeholder="Search product name, product code or reference code"
                autocomplete="off"
                aria-label="Search product"
            >
            @if($productSearchValue !== '')
                <button type="button" class="ft-order-product-search-clear" wire:click="$set('createProductSearch', '')" x-on:click="resultsOpen = false" aria-label="Clear product search">&times;</button>
            @endif
        </div>

        <div class="ft-order-product-results" x-cloak x-show="resultsOpen" x-transition.opacity.duration.120ms>
            <div class="ft-order-product-results-head">
                <span>Top matches <i>&middot;</i> {{ number_format((int) $resultCount) }} {{ \Illuminate\Support\Str::plural('result', (int) $resultCount) }}</span>
                @if(!$createProductShowAllResults && $productResultTotal > 0)
                    <button type="button" wire:click="showAllCreateProductResults">View all results <span>&nearr;</span></button>
                @endif
            </div>

            <div class="ft-order-product-result-list">
                @forelse($productSearchResults as $product)
                    @continue($product->type !== 'product')
                    @php
                        $resultSupplier = $productSearchSuppliers->get((int) $product->id);
                        $supplierWords = preg_split('/\s+/', trim((string) ($resultSupplier?->name ?? ''))) ?: [];
                        $supplierInitials = strtoupper(substr(implode('', array_map(fn ($word) => substr($word, 0, 1), array_filter($supplierWords))), 0, 2));
                        $supplierShortCode = trim((string) ($resultSupplier?->supplierShortCode() ?? ''));
                        $supplierLabel = $supplierShortCode !== '' ? $supplierShortCode : trim((string) ($resultSupplier?->name ?? ''));
                        $previewPrice = $product->productPriceForQuantity(1000, $clientId ? (int) $clientId : null);
                        $leadDays = (int) (data_get($product->metadata, 'lead_time_days') ?: data_get($product->metadata, 'supplier_lead_time_days') ?: 0);
                        $isPreferred = (bool) (data_get($product->metadata, 'supplier_preferred') ?: data_get($product->metadata, 'preferred_supplier'));
                        $referenceCode = $product->productReferenceCode();
                        $displayCode = $product->productDisplayCode();
                        $imageUrl = $product->productImageUrl();
                    @endphp
                    <div class="ft-order-product-result">
                        <span class="ft-order-product-thumb">
                            @if($imageUrl)
                                <img src="{{ $imageUrl }}" alt="">
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                            @endif
                        </span>
                        <span class="ft-order-product-result-copy">
                            <strong>{{ $product->name }}</strong>
                            <span class="ft-order-product-code-line">Product code {{ $displayCode }} <i>&middot;</i> Ref {{ $referenceCode ?: '—' }}</span>
                            <small class="ft-order-product-result-supplier">
                                @if($resultSupplier)
                                    <b class="ft-order-product-result-supplier-badge">{{ $supplierInitials ?: 'S' }}</b>
                                    <strong>{{ $supplierLabel ?: '—' }}</strong>
                                    <span>Default</span>
                                    @if($leadDays > 0)<i>&middot;</i><span>{{ number_format($leadDays) }} days</span>@endif
                                    @if($isPreferred)<i>&middot;</i><span>Preferred</span>@endif
                                    @if($previewPrice !== null)<i>&middot;</i><span>${{ number_format((float) $previewPrice, 2) }} client price</span>@endif
                                @else
                                    <b class="ft-order-product-result-supplier-badge is-empty">—</b>
                                    <strong>No default supplier</strong>
                                @endif
                            </small>
                        </span>
                        <button type="button" class="ft-order-product-select-button {{ in_array((int) $product->id, $selectedIds, true) ? 'is-selected' : '' }}" wire:click="selectCreateProduct({{ $product->id }})">Select</button>
                    </div>
                @empty
                    <div class="ft-order-product-no-results {{ $showCreateProductSuggestion && $canCreateCatalogProduct ? 'has-create-action' : '' }}">
                        <span class="ft-order-product-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/><path d="M11 8v6M8 11h6"/></svg>
                        </span>
                        <strong>{{ $showCreateProductSuggestion ? 'No matching product found' : 'No products found' }}</strong>
                        <span>{{ $showCreateProductSuggestion ? 'Nothing in the catalog matches “'.$productSearchValue.'”.' : 'Try another product name, product code or reference code.' }}</span>
                        @if($showCreateProductSuggestion && $canCreateCatalogProduct)
                            <button type="button" class="ft-order-product-create-cta" wire:click="openCreateOrderProductModalFromSearch">
                                <span class="ft-order-product-create-cta-icon" aria-hidden="true">+</span>
                                <span class="ft-order-product-create-cta-copy">
                                    <strong>Create “{{ $productSearchValue }}”</strong>
                                    <small>Add it to the catalog and this Order</small>
                                </span>
                                <svg class="ft-order-product-create-cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                            </button>
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    @if(count($rows))
        <div class="ft-order-selected-products ft-order-selected-products--cards">
            <div class="ft-order-selected-product-cards">
                @foreach($rows as $index => $item)
                    @php
                        $productId = (int) ($item['product_id'] ?? 0);
                        $detail = $selectedProductDetails->get($productId);
                        $supplier = $selectedProductSuppliers->get($productId);
                        $supplierSkipped = in_array($productId, array_map('intval', (array) $supplierSkippedProductIds), true);
                    @endphp
                    <x-catalog.create-product-card
                        :item="$item"
                        :detail="$detail"
                        :supplier="$supplier"
                        :supplier-skipped="$supplierSkipped"
                        :supplier-required="$supplierRequired"
                        :context="$context"
                        :can-view-supplier-prices="$canViewSupplierPrices"
                        :index="$index"
                        :rows-property="$rowsProperty"
                        :remove-method="$removeMethod"
                        :row-key-prefix="$rowKeyPrefix"
                    />
                @endforeach
            </div>
        </div>
    @endif

    <div class="ft-order-product-bottom-actions">
        <button type="button" wire:click="focusCreateProductSearch" @disabled(count($rows) >= 25)><span>+</span> Add Product</button>
    </div>

    <div class="ft-order-products-summary-row">
        <span>{{ count($rows) }} {{ \Illuminate\Support\Str::plural('product', count($rows)) }} <i>&bull;</i> {{ number_format($totalUnits) }} total units</span>
    </div>
</section>
