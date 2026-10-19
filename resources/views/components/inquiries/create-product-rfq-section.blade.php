@props([
    'rows' => [],
    'rfqRows' => [],
    'activeProductCount' => 0,
    'productSearchResults' => collect(),
    'productSearchSuppliers' => collect(),
    'productResultTotal' => 0,
    'createProductSearch' => '',
    'createProductShowAllResults' => false,
    'selectedProductDetails' => collect(),
    'selectedProductSuppliers' => collect(),
    'selectedRfqSuppliers' => collect(),
    'canCreateCatalogProduct' => false,
])

@php
    $selectedIds = collect($rows)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
    $resultCount = collect($productSearchResults)->count();
    $productSearchValue = trim((string) $createProductSearch);
    $showCreateProductSuggestion = $productSearchValue !== '' && (int) $productResultTotal === 0;
    $hasProducts = count($rows) > 0;
@endphp

<section
    class="section ft-inquiry-create-section ft-inquiry-product-rfq-prototype"
    wire:key="create-inquiry-product-rfq-prototype"
    x-data="{
        resultsOpen: false,
        focusSearch(openResults = true) {
            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    const search = this.$refs.productSearch;
                    search?.focus({ preventScroll: true });
                    search?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (openResults) this.resultsOpen = true;
                });
            });
        },
        openResults() {
            this.resultsOpen = true;
        },
        closeResults() {
            this.resultsOpen = false;
        },
    }"
    x-on:focus-create-order-product-search.window="focusSearch(true)"
    x-on:open-create-order-product-results.window="openResults()"
    x-on:create-order-product-selected.window="closeResults()"
    x-on:keydown.escape.window="closeResults()"
>
    <header class="ft-ipr-section-head">
        <div class="ft-ipr-section-heading">
            <span class="ft-ipr-step">2</span>
            <div>
                <div class="ft-ipr-title-line">
                    <h2>Products, suppliers &amp; RFQ invitations</h2>
                    @if($hasProducts)
                        <span class="ft-ipr-count-badge">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('product', count($rows)) }}</span>
                    @endif
                </div>
                <p>Add one or more products, then choose suppliers and invitation settings for each product.</p>
            </div>
        </div>

    </header>

    <div class="ft-ipr-search-shell">
        <label class="ft-ipr-product-search-label" for="create-inquiry-product-search">Search product</label>

        <div class="ft-ipr-product-picker" x-on:click.outside="closeResults()">
            <div class="ft-ipr-product-search" :class="resultsOpen ? 'is-open' : ''">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input
                    id="create-inquiry-product-search"
                    x-ref="productSearch"
                    type="search"
                    wire:model.live.debounce.220ms="createProductSearch"
                    x-on:focus="openResults()"
                    x-on:input="openResults()"
                    x-on:keydown.escape.stop="closeResults()"
                    placeholder="Search product name, product code or reference code"
                    autocomplete="off"
                    aria-label="Search product"
                >
                @if($productSearchValue !== '')
                    <button
                        type="button"
                        wire:click="$set('createProductSearch', '')"
                        x-on:click="closeResults()"
                        aria-label="Clear product search"
                    >&times;</button>
                @endif
            </div>

            <div class="ft-ipr-product-results" x-cloak x-show="resultsOpen" x-transition.opacity.duration.120ms>
                <div class="ft-ipr-results-head">
                    <span>Top matches <i>&middot;</i> {{ number_format($resultCount) }} {{ \Illuminate\Support\Str::plural('result', $resultCount) }}</span>
                    @if(!$createProductShowAllResults && $productResultTotal > 0)
                        <button type="button" wire:click="showAllCreateProductResults">View all results <span>&nearr;</span></button>
                    @endif
                </div>

                @forelse($productSearchResults as $product)
                    @continue($product->type !== 'product')
                    @php
                        $resultSupplier = $productSearchSuppliers->get((int) $product->id);
                        $imageUrl = $product->productImageUrl();
                        $displayCode = $product->productDisplayCode();
                        $referenceCode = $product->productReferenceCode();
                    @endphp
                    <div class="ft-ipr-product-result">
                        <span class="ft-ipr-product-result-image">
                            @if($imageUrl)
                                <img src="{{ $imageUrl }}" alt="" loading="lazy" decoding="async" data-ft-image-fallback="icon">
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                            @endif
                        </span>
                        <span class="ft-ipr-product-result-copy">
                            <strong>{{ $product->name }}</strong>
                            <small>Product code {{ $displayCode ?: '—' }} @if($referenceCode)<i>&middot;</i> Ref {{ $referenceCode }}@endif</small>
                            <span>{{ $resultSupplier?->supplierShortCode() ?: 'No default supplier' }}</span>
                        </span>
                        <button type="button" class="{{ in_array((int) $product->id, $selectedIds, true) ? 'is-selected' : '' }}" wire:click="selectCreateProduct({{ $product->id }})">
                            {{ in_array((int) $product->id, $selectedIds, true) ? 'Selected' : 'Select' }}
                        </button>
                    </div>
                @empty
                    <div class="ft-ipr-empty-search">
                        <strong>{{ $showCreateProductSuggestion ? 'No matching product found.' : 'No products found' }}</strong>
                        <span>{{ $showCreateProductSuggestion ? 'You can create a new product from this search.' : 'Search by product name, product code or reference code.' }}</span>
                        @if($showCreateProductSuggestion && $canCreateCatalogProduct)
                            <button type="button" wire:click="openCreateOrderProductModalFromSearch">Create new product</button>
                        @endif
                    </div>
                @endforelse
            </div>
        </div>

        <div class="ft-ipr-initial-add-action">
            <button type="button" x-on:click.stop="focusSearch(true)" @disabled(count($rows) >= 25)>
                <span aria-hidden="true">+</span> Add Product
            </button>
        </div>
    </div>

    @if($hasProducts)
        <div class="ft-ipr-card-list">
            @foreach($rows as $index => $item)
                @php
                    $productId = (int) ($item['product_id'] ?? 0);
                    $detail = $selectedProductDetails->get($productId);
                    $defaultSupplier = $selectedProductSuppliers->get($productId);
                    $rfqState = is_array($rfqRows[$index] ?? null) ? $rfqRows[$index] : [];
                    $rfqSuppliers = collect($selectedRfqSuppliers->get($index, collect()));
                @endphp
                <x-inquiries.create-product-rfq-card
                    :item="$item"
                    :index="$index"
                    :detail="$detail"
                    :default-supplier="$defaultSupplier"
                    :rfq-state="$rfqState"
                    :rfq-suppliers="$rfqSuppliers"
                    wire:key="create-inquiry-product-rfq-card-{{ $item['row_key'] ?? ($productId.'-'.$index) }}"
                />
            @endforeach
        </div>

        <button type="button" class="ft-ipr-add-another" x-on:click.stop="focusSearch(true)" @disabled(count($rows) >= 25)>
            <span aria-hidden="true">+</span> Add another product
        </button>
    @endif

    <div class="ft-ipr-info-strip">
        <span aria-hidden="true">i</span>
        <p>Suppliers and RFQ responses are tracked separately for each product.</p>
    </div>
</section>
