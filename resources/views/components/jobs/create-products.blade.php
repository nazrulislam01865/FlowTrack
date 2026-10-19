@php
    $categorySearchValue = trim((string) $newProductCategorySearch);
    $categorySuggestions = $categorySearchValue === '' ? $productCategories->take(6) : $newProductCategoryMatches;
    $hasDuplicateCode = (bool) $duplicateProduct;
    $hasSimilarProductName = $newProductSimilarProducts->isNotEmpty();
    $manualProductCode = trim((string) $newProductCode);
    $productCodeFormatValid = $manualProductCode === ''
        || (mb_strlen($manualProductCode) <= 40
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $manualProductCode) === 1);
    $productCodeReady = $productCodeFormatValid && !$hasDuplicateCode;
    $productCategoryReady = $productCodeReady && (bool) $newProductSelectedCategory;
    $productNameReady = $productCategoryReady && trim((string) $newProductName) !== '';
    $productSupplierReady = $productNameReady;
@endphp

@if($catalogReady && $canUseOrderProductSelector)
    <x-catalog.create-product-quantity
        context="order"
        :rows="$jobItems"
        :client-id="$clientId"
        :can-view-supplier-prices="app(\App\Services\Orders\SupplierCostVisibility::class)->allowed()"
        rows-property="jobItems"
        remove-method="removeProductRow"
        :required="true"
        :step="3"
        required-error-field="jobItems"
        :active-product-count="$activeProductCount"
        :product-search-results="$productSearchResults"
        :product-search-suppliers="$productSearchSuppliers"
        :product-result-total="$productResultTotal"
        :create-product-search="$createProductSearch"
        :create-product-show-all-results="$createProductShowAllResults"
        :selected-product-details="$selectedProductDetails"
        :selected-product-suppliers="$selectedProductSuppliers"
        :supplier-skipped-product-ids="$createOrderSupplierSkipProductIds"
        :supplier-required="true"
        :can-create-catalog-product="$canCreateCatalogProduct"
        wire-key="create-order-products-ready"
        row-key-prefix="selected-order-product"
    />
@elseif(!$catalogReady)
    <x-jobs.create-section-placeholder number="3" title="Products & quantities" section="catalog" :rows="3" />
@else
    <section class="ft-create-section ft-order-products-prototype" wire:key="create-catalog-restricted">
        <div class="ft-order-products-title-row">
            <div class="ft-create-section-title ft-order-products-title">
                <span>3</span><h2>Products &amp; quantities</h2>
            </div>
        </div>
        <div class="ft-create-note">Products are hidden because this role does not have <b>Products → View</b> permission. Product access on Create Order is controlled only by the Products role.</div>
    </section>
@endif

@include('components.jobs.create.product-modal')
