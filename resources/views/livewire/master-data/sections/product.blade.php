        @if($showProductView && $viewProduct)
            <x-catalog.product-view
                :product="$viewProduct"
                :suppliers="$viewProductSuppliers"
                :supplier-pricing-tables="$viewProductSupplierPricingTables"
                :can-edit="$canEditMaster"
                :can-delete="$canDeleteMaster"
                :display-timezone="$displayTimezone"
                :detail-sections-ready="$productDetailSectionsReady ?? []"
            />
        @elseif($showModal)
            <x-catalog.product-form
                :edit-product="$editProduct"
                :parents="$productFormCategories"
                :all-product-categories="$parents"
                :main-categories="$productMainCategories"
                :subcategories="$productSubcategories"
                :clients="$productClients"
                :can-create-product-category="$canCreateProductCategory"
                :product-image-preview="$productImagePreview"
                :client-availability-mode="$productClientAvailabilityMode"
                :client-ids="$productClientIds"
                :product-supplier-id="$productSupplierId"
                :product-supplier-ids="$productSupplierIds"
                :certificate-upload="$productCertificateUpload"
                :template-upload="$productTemplateUpload"
                :remove-certificate="$removeProductCertificate"
                :remove-template="$removeProductTemplate"
                :category-creator="$categoryCreator"
                :selected-main-category="$productFormMainCategory"
                :selected-product-category-id="$parentId"
                :selected-subcategory="$productSubcategory"
                :price-preview="$productPricePreview"
                :customer-price-preview="$productCustomerPricePreview"
                :client-price-rows="$productClientPrices"
                :supplier-price-rows="$productSupplierPrices"
                :remote-surcharge-preview="$productRemoteSurchargePreview"
                :product-options="$productOptions"
                :product-option-uploads="$productOptionUploads"
                :shipment-urgencies="$availableProductShipmentUrgencies"
                :product-shipment-urgencies="$productShipmentUrgencies"
                :shipment-urgency-picker-open="$productShipmentUrgencyPickerOpen"
                :shipment-urgency-picker-selection="$productShipmentUrgencyPickerSelection"
                :new-product-category-main="$newProductCategoryMain"
                :new-subcategory-product-category-id="$newSubcategoryProductCategoryId"
                :taxonomy-ready="$productTaxonomyReady || (bool) $editProduct"
                :shipment-options-ready="$productShipmentOptionsReady || (bool) $editProduct"
            />
        @else
        <div class="ft-product-list-head">
            <div>
                <h1>Products</h1>
                <p>Manage the product catalog, client availability and supporting documents.</p>
            </div>
            <div class="ft-product-list-head-actions">
                <button type="button" class="ft-product-page-btn is-secondary ft-product-export-button" wire:click="exportProducts" wire:loading.attr="disabled" wire:target="exportProducts">Export Excel</button>
                @if($canEditMaster)
                    <button type="button" class="ft-product-page-btn is-secondary ft-product-supplier-filter-btn" wire:click="showProductsWithoutSupplier">Show products without supplier</button>
                    <button type="button" class="ft-product-page-btn is-primary ft-product-assign-supplier-btn" wire:click="openProductSupplierAssignment" @disabled($productSelectionCount < 1)>Assign supplier</button>
                @endif
                @if($canCreateMaster)
                    <button type="button" class="ft-product-add-button" wire:click="open">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <span>Add product</span>
                    </button>
                @endif
            </div>
        </div>

        @if(session('success'))<div class="flash success ft-master-flash">{{ session('success') }}</div>@endif
        @error('record')<div class="flash error ft-master-flash">{{ $message }}</div>@enderror

        <section class="ft-product-list-shell" x-data="{}">
            <div class="ft-product-filter-card">
                <label class="ft-product-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search product name, product code or reference code" aria-label="Search products">
                </label>

                @php
                    $productMainCategoryListOptions = collect($productMainCategoryFilterOptions)->map(fn ($mainCategory) => [
                        'id' => (string) $mainCategory->name,
                        'label' => (string) $mainCategory->name,
                        'meta' => (string) ($mainCategory->code ?? ''),
                    ])->values();
                    $productCategoryListOptions = collect($productCategories)->map(fn ($category) => [
                        'id' => (string) $category->id,
                        'label' => (string) $category->name,
                        'meta' => (string) ($category->code ?? ''),
                    ])->values();
                    $productClientAvailabilityListOptions = collect([
                        ['id' => 'all', 'label' => 'All clients'],
                        ['id' => 'specific', 'label' => 'Specific clients'],
                    ]);
                    $productStatusListOptions = collect([
                        ['id' => 'active', 'label' => 'Active'],
                        ['id' => 'inactive', 'label' => 'Inactive'],
                    ]);
                @endphp

                <x-ui.search-select
                    class="ft-product-list-filter"
                    label="Main category"
                    property="productMainCategory"
                    :value="$productMainCategory"
                    placeholder="All main categories"
                    :options="$productMainCategoryListOptions"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="300"
                    search-placeholder="Search main category…"
                    footer-message="Options shown instantly. Type to search."
                />

                <x-ui.search-select
                    class="ft-product-list-filter"
                    label="Product category"
                    property="productCategory"
                    :value="$productCategory"
                    placeholder="All product categories"
                    :options="$productCategoryListOptions"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="300"
                    search-placeholder="Search product category…"
                    footer-message="Options shown instantly. Type to search."
                />

                <x-ui.search-select
                    class="ft-product-list-filter"
                    label="Supplier"
                    property="productSupplierFilterId"
                    type="suppliers"
                    context="product-list"
                    :value="$productSupplierFilterId"
                    :initial-options="$productSupplierFilterSelectedOptions"
                    :selected-label="$productSupplierFilterSelectedOptions->first()['label'] ?? null"
                    placeholder="All suppliers"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="300"
                    :infinite-scroll="true"
                    search-placeholder="Search supplier code…"
                />

                <x-ui.search-select
                    class="ft-product-list-filter"
                    label="Client availability"
                    property="productClientAvailability"
                    :value="$productClientAvailability"
                    placeholder="All client availability"
                    :options="$productClientAvailabilityListOptions"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="280"
                    search-placeholder="Search client availability…"
                />

                <x-ui.search-select
                    class="ft-product-list-filter"
                    label="Status"
                    property="productStatus"
                    :value="$productStatus"
                    placeholder="All statuses"
                    :options="$productStatusListOptions"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="240"
                    search-placeholder="Search status…"
                />

                <button type="button" class="ft-product-clear" wire:click="clearProductFilters">Clear</button>
            </div>

            @if($productSupplierState === 'unassigned')
                <div class="ft-product-supplier-filter-state">
                    <span>Showing products without a supplier</span>
                    <button type="button" wire:click="clearProductFilters">Clear</button>
                </div>
            @endif

            @if(!$recordsReady)
                @include('livewire.shared.table-rows-placeholder', ['columns' => 9, 'rows' => 8])
            @else
                @php
                    $pageProductIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                    $selectedProductIdSet = collect($selectedProductIds)->map(fn ($id) => (int) $id);
                    $excludedProductIdSet = collect($excludedProductIds)->map(fn ($id) => (int) $id);
                    $allPageProductsSelected = count($pageProductIds) > 0 && collect($pageProductIds)->every(
                        fn ($id) => $selectAllMatchingProducts ? !$excludedProductIdSet->contains($id) : $selectedProductIdSet->contains($id)
                    );
                @endphp

                @if($productSelectionCount > 0)
                    <x-catalog.bulk-actions
                        :count="$productSelectionCount"
                        :matching-total="$rows->total()"
                        :all-matching-selected="$selectAllMatchingProducts && empty($excludedProductIds)"
                        :can-edit="$canEditMaster"
                        :can-delete="$canDeleteMaster"
                    />
                @endif

                <div class="ft-product-table-card" wire:key="product-catalog-{{ $productMainCategory }}-{{ $productCategory }}-{{ $productClientAvailability }}-{{ $productStatus }}-{{ $productSupplierState }}-{{ $productSupplierFilterId }}-{{ $productPerPage }}">
                    <div class="ft-product-table-scroll">
                        <table class="ft-product-list-table">
                            <thead>
                                <tr>
                                    <th class="ft-product-checkbox-cell">
                                        <input
                                            type="checkbox"
                                            aria-label="Select all products on this page"
                                            @checked($allPageProductsSelected)
                                            x-on:change="$wire.toggleProductPageSelection(@js($pageProductIds), $event.target.checked)"
                                        >
                                    </th>
                                    <th>Product</th>
                                    <th>Product code</th>
                                    <th>Classification</th>
                                    <th>Supplier</th>
                                    <th>Size</th>
                                    <th>Availability</th>
                                    <th>Documents</th>
                                    <th>Status</th>
                                    <th>Updated</th>
                                    <th class="ft-product-actions-heading" aria-label="Actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($rows as $r)
                                @php
                                    $updatedAt = $r->updated_at?->copy()->timezone($displayTimezone);
                                    $updatedLabel = !$updatedAt
                                        ? '—'
                                        : ($updatedAt->isToday() ? $updatedAt->diffForHumans() : ($updatedAt->isYesterday() ? '1 day ago' : $updatedAt->diffForHumans()));
                                    $documents = $r->productDocuments();
                                    $classificationPath = $r->productClassificationPath();
                                    $isProductSelected = $selectAllMatchingProducts
                                        ? !$excludedProductIdSet->contains((int) $r->id)
                                        : $selectedProductIdSet->contains((int) $r->id);
                                @endphp
                                <tr wire:key="product-list-row-{{ $r->id }}" @class(['is-selected' => $isProductSelected])>
                                    <td class="ft-product-checkbox-cell"><input type="checkbox" value="{{ $r->id }}" aria-label="Select {{ $r->name }}" @checked($isProductSelected) wire:change="toggleProductSelection({{ $r->id }})"></td>
                                    <td>
                                        <div class="ft-product-name-cell">
                                            <div class="ft-product-list-thumb">
                                                @if($r->productImageUrl())
                                                    <img src="{{ $r->productImageUrl() }}" alt="{{ $r->name }}">
                                                @else
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                                                @endif
                                            </div>
                                            <a
                                                class="ft-product-name-link"
                                                href="{{ route('master-data', ['group' => 'product', 'open' => $r->id]) }}"
                                                wire:navigate
                                                title="Open {{ $r->name }} details"
                                            >{{ $r->name }}</a>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="ft-product-code-cell">
                                            <strong>{{ $r->productDisplayCode() }}</strong>
                                            <span>Ref: {{ $r->productReferenceCode() ?: '—' }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="ft-product-classification">
                                            <strong>{{ $r->productMainCategory() ?: '—' }}</strong>
                                            <span>{{ $classificationPath ?: '—' }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <x-suppliers.product-supplier-links
                                            :suppliers="$productSuppliersByProduct->get((int) $r->id, collect())"
                                            :default-supplier-id="$r->productSupplierId()"
                                        />
                                    </td>
                                    <td><x-catalog.product-size :value="$r->productSize()" /></td>
                                    <td><x-catalog.availability :labels="$r->productAvailabilityLabels()" /></td>
                                    <td>
                                        <div class="ft-product-documents">
                                            @if(count($documents))
                                                <div class="ft-product-document-count">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5M8.5 13h7M8.5 17h5"/></svg>
                                                    <span>{{ count($documents) }} {{ \Illuminate\Support\Str::plural('file', count($documents)) }}</span>
                                                </div>
                                                <small title="{{ $documents[0]['label'] }}">{{ \Illuminate\Support\Str::limit($documents[0]['label'], 18) }}</small>
                                            @else
                                                <span class="ft-product-documents-empty">—</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td><x-catalog.status :active="$r->status === 'active'" /></td>
                                    <td><span class="ft-product-updated" title="{{ $updatedAt?->format('M j, Y g:i A') }} {{ $displayTimezone }}">{{ $updatedLabel }}</span></td>
                                    <td class="ft-product-actions-cell">
                                        <x-catalog.action-menu
                                            :product-id="$r->id"
                                            :is-active="$r->status === 'active'"
                                            :can-edit="$canEditMaster"
                                            :can-delete="$canDeleteMaster"
                                        />
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="11"><div class="empty-state">No products found.</div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @php
                        $lastPage = max(1, $rows->lastPage());
                        $currentPage = $rows->currentPage();
                        $pageStart = max(1, min($currentPage - 1, max(1, $lastPage - 2)));
                        $pageEnd = min($lastPage, $pageStart + 2);
                    @endphp
                    <div class="ft-product-pagination">
                        <div class="ft-product-pagination-left">
                            <div class="ft-product-result-count ft-product-result-count-footer">
                                Showing {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of {{ number_format($rows->total()) }} products
                            </div>
                            <div class="ft-product-rows-per-page">
                                <span>Rows per page</span>
                                <x-catalog.filter-select model="productPerPage" label="Rows per page">
                                    <option value="10">10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </x-catalog.filter-select>
                            </div>
                        </div>
                        <div class="ft-product-page-position">Page {{ $currentPage }} of {{ $lastPage }}</div>
                        <div class="ft-product-page-buttons">
                            <button type="button" wire:click="previousPage('masterPage')" @disabled($rows->onFirstPage())>Previous</button>
                            @for($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++)
                                <button
                                    type="button"
                                    @class(['is-current' => $pageNumber === $currentPage])
                                    wire:click="gotoPage({{ $pageNumber }}, 'masterPage')"
                                    aria-label="Go to page {{ $pageNumber }}"
                                    @if($pageNumber === $currentPage) aria-current="page" @endif
                                >{{ $pageNumber }}</button>
                            @endfor
                            <button type="button" wire:click="nextPage('masterPage')" @disabled(!$rows->hasMorePages())>Next</button>
                        </div>
                    </div>
                </div>

                @if($bulkProductPanel === 'supplier')
                    <x-suppliers.assign-products-modal
                        :suppliers="$bulkProductSupplierOptions"
                        :product-counts="$bulkProductSupplierProductCounts"
                        :selected-supplier-ids="$bulkProductSupplierIds"
                        :selection-count="$productSelectionCount"
                    />
                @elseif($bulkProductPanel === 'clients')
                    <x-catalog.bulk-modal
                        title="Assign clients"
                        subtitle="Choose who can find and use the selected products."
                        save-label="Assign clients"
                        save-action="applyBulkProductClients"
                    >
                        <div class="ft-product-bulk-radio-row">
                            <label><input type="radio" wire:model.live="bulkProductClientMode" value="all"> <span>All clients</span></label>
                            <label><input type="radio" wire:model.live="bulkProductClientMode" value="specific"> <span>Selected clients</span></label>
                        </div>
                        @if($bulkProductClientMode === 'specific')
                            <div class="ft-product-bulk-client-picker" x-data="{ q: '' }">
                                <label>Available clients</label>
                                <input type="search" x-model="q" placeholder="Search clients…" autocomplete="off">
                                <div class="ft-product-bulk-client-list">
                                    @foreach($productClients as $client)
                                        @php $bulkClientSelected = in_array((int)$client->id, collect($bulkProductClientIds)->map(fn($v)=>(int)$v)->all(), true); @endphp
                                        <button type="button"
                                            x-show="!q || @js(mb_strtolower($client->name.' '.$client->code)).includes(q.toLowerCase())"
                                            wire:click="toggleBulkProductClient({{ $client->id }})"
                                            @class(['is-selected' => $bulkClientSelected])>
                                            <span>{{ $client->name }}</span><small>{{ $client->code }}</small><b>{{ $bulkClientSelected ? '✓' : '' }}</b>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            @error('bulkProductClientIds')<b class="validation-error">{{ $message }}</b>@enderror
                        @else
                            <div class="ft-product-bulk-note">All clients will be able to find and use these products.</div>
                        @endif
                    </x-catalog.bulk-modal>
                @elseif($bulkProductPanel === 'category')
                    <x-catalog.bulk-modal
                        title="Change category"
                        subtitle="Move the selected products to a new category hierarchy."
                        save-label="Change category"
                        save-action="applyBulkProductCategory"
                    >
                        <div class="ft-product-bulk-category-grid">
                            <x-ui.search-select
                                label="Main category"
                                property="bulkProductMainCategory"
                                :value="$bulkProductMainCategory"
                                placeholder="Select main category"
                                :options="$productMainCategories"
                                :clearable="false"
                                :required="true"
                                :fixed-menu="true"
                                :menu-width="360"
                                search-placeholder="Search main category…"
                            />
                            <x-ui.search-select
                                label="Product category"
                                property="bulkProductCategoryId"
                                :value="$bulkProductCategoryId"
                                :placeholder="trim($bulkProductMainCategory) === '' ? 'Select main category first' : 'Select product category'"
                                :options="$bulkProductCategories"
                                :disabled="trim($bulkProductMainCategory) === ''"
                                :clearable="false"
                                :required="true"
                                :fixed-menu="true"
                                :menu-width="380"
                                search-placeholder="Search product category…"
                            />
                            <x-ui.search-select
                                label="Subcategory"
                                property="bulkProductSubcategory"
                                :value="$bulkProductSubcategory"
                                :placeholder="$bulkProductCategoryId ? 'No subcategory' : 'Select product category first'"
                                :options="$bulkProductSubcategories"
                                :disabled="!$bulkProductCategoryId"
                                :clearable="true"
                                :optional="true"
                                :fixed-menu="true"
                                :menu-width="380"
                                search-placeholder="Search subcategory…"
                            />
                        </div>
                        @error('bulkProductMainCategory')<b class="validation-error">{{ $message }}</b>@enderror
                        @error('bulkProductCategoryId')<b class="validation-error">{{ $message }}</b>@enderror
                    </x-catalog.bulk-modal>
                @endif
            @endif
        </section>

        @endif
