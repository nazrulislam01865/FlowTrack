@props([
    'job', 'context' => [], 'showAddJobProductForm' => false, 'jobProductSearch' => '',
    'jobProductSearchResults' => collect(), 'jobProductSearchSuppliers' => collect(), 'jobProductResultTotal' => 0,
    'jobProductShowAllResults' => false, 'jobProductSelectedProduct' => null, 'jobProductSelectedSupplier' => null,
    'jobProductCategory' => '', 'jobProductQuantity' => '1000', 'jobProductUnitPrice' => '0.00', 'jobProductSupplierId' => null,
    'jobProductSupplierLabel' => '', 'jobProductSupplierSkipped' => false, 'jobProductSupplierLocked' => false,
    'showEditOrderProductModal' => false, 'editOrderProductItemId' => null, 'editOrderProductName' => '', 'editOrderProductCode' => '',
    'editOrderProductCategory' => '', 'editOrderProductSearch' => '', 'editOrderProductSearchResults' => collect(),
    'editOrderProductSearchSuppliers' => collect(), 'editOrderProductResultTotal' => 0, 'editOrderProductSelectedProduct' => null,
    'editOrderProductSelectedSupplier' => null, 'editOrderProductShowAllResults' => false,
    'editOrderProductSupplierId' => null, 'editOrderProductSupplierLabel' => '', 'editOrderProductQuantity' => '1',
    'editOrderProductUnitPrice' => '0.00', 'editOrderProductNotes' => '',
])

@php
    // Presentation only. OrderReadService eager-loads product, supplier and audit
    // relations before this component renders, so this view never performs N+1 queries.
    $items = \App\Support\JobDetailPresenter::products($job);
    $activeItems = $items->filter(fn ($item) => !($item->is_removed ?? false))->values();
    $canView = (bool) ($context['canViewProducts'] ?? false);
    $canEdit = (bool) ($context['canEditProducts'] ?? false);
    $canCreate = (bool) ($context['canCreateProducts'] ?? false);
    $canDelete = (bool) ($context['canDeleteProducts'] ?? false);
    $currency = strtoupper((string) ($job->currency ?: 'USD'));
    $symbol = match ($currency) { 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CNY', 'RMB' => '¥', default => $currency.' ' };
    $activeUnits = \App\Support\OrderDetailPresenter::totalActiveUnits($job);
    $canSeeSupplierCosts = app(\App\Services\Orders\SupplierCostVisibility::class)->allowed();
@endphp

<x-catalog.detail-products-card
    id="productsSection"
    variant="order"
    :count="$activeItems->count()"
    :total-units="$activeUnits"
>
    @if(!$canView)
        <tr class="ft-order-product-empty-row">
            <td colspan="8">Product details are unavailable for your role.</td>
        </tr>
    @elseif($items->isEmpty())
        <tr class="ft-order-product-empty-row">
            <td colspan="8">No products have been added to this Order yet.</td>
        </tr>
    @else
        @foreach($items as $item)
            @php
                $removed = (bool) ($item->is_removed ?? false);
                $catalog = $item->catalogProduct;
                $imageUrl = $catalog && method_exists($catalog, 'productImageUrl') ? $catalog->productImageUrl() : null;
                $displayCode = $catalog && method_exists($catalog, 'productDisplayCode') ? $catalog->productDisplayCode() : null;
                $referenceCode = $catalog && method_exists($catalog, 'productReferenceCode') ? $catalog->productReferenceCode() : null;
                // Keep Order Details category rendering in parity with Inquiry Details:
                // Main category > Product category > Subcategory. Prefer Product Master
                // classification and only fall back to the legacy stored category text.
                $classificationParts = collect([
                    $catalog && method_exists($catalog, 'productMainCategory') ? $catalog->productMainCategory() : null,
                    ...array_filter(array_map('trim', preg_split('/\s*>\s*/', (string) ($catalog && method_exists($catalog, 'productClassificationPath') ? $catalog->productClassificationPath() : '')) ?: [])),
                ])->filter()->unique()->values();
                if ($classificationParts->isEmpty() && filled($item->category_name)) {
                    $classificationParts = collect(preg_split('/\s*>\s*/', trim((string) $item->category_name)) ?: [])
                        ->map(fn ($part) => trim((string) $part))
                        ->filter()
                        ->unique()
                        ->values();
                }
                $categoryDisplay = $classificationParts->implode(' › ') ?: '—';
                $supplier = $item->relationLoaded('supplier') ? $item->supplier : null;
                $supplierName = $supplier?->name ?: \App\Support\OrderDetailPresenter::itemSupplierName($item, $job);
                $defaultSupplierId = $catalog && method_exists($catalog, 'productSupplierId') ? $catalog->productSupplierId() : null;
                $isDefaultSupplier = $supplier && $defaultSupplierId && (int) $supplier->id === (int) $defaultSupplierId;
                $leadDays = (int) (data_get($catalog?->metadata, 'lead_time_days') ?: data_get($catalog?->metadata, 'supplier_lead_time_days') ?: 0);
                $isPreferred = (bool) (data_get($catalog?->metadata, 'supplier_preferred') ?: data_get($catalog?->metadata, 'preferred_supplier'));
                $supplierMeta = collect([
                    $supplierName !== 'Not linked' ? ($isDefaultSupplier ? 'Default supplier' : 'Order supplier') : null,
                    $leadDays > 0 ? number_format($leadDays).'-day lead time' : null,
                    $isPreferred ? 'Preferred' : null,
                ])->filter()->implode(' · ');
                $quantity = max(0, (int) ($item->quantity ?? 0));
                $unitPrice = max(0, (float) ($item->unit_price ?? 0));
                $updatedBy = data_get($item, 'updatedBy.name') ?: data_get($item, 'removedBy.name') ?: $job->owner?->name ?: 'FlowTrack';
                $updatedRelative = $item->updated_at
                    ? (\App\Support\UserLocalTime::localize($item->updated_at)?->diffForHumans() ?: '—')
                    : '—';
            @endphp

            <tr
                wire:key="order-product-detail-{{ $item->id ?? 'legacy' }}"
                x-data="{ actionOpen: false }"
                @class(['is-removed removed-product-row' => $removed, 'is-editing' => !$removed && $canEdit && $showEditOrderProductModal && (int) $editOrderProductItemId === (int) $item->id])
            >
                <td data-label="Product">
                    <x-catalog.detail-product-identity
                        :image-url="$imageUrl"
                        :alt="$item->product_name ?? ''"
                        :code="$displayCode"
                        :reference="$referenceCode"
                        fallback-meta="Order product"
                    >
                        <span class="ft-order-product-name">{{ $item->product_name ?: 'Unnamed product' }}</span>
                        @if($removed)<span class="ft-detail-product-state">Removed</span>@endif
                    </x-catalog.detail-product-identity>
                </td>
                <td data-label="Category">
                    <span class="ft-order-product-category-path">{{ $categoryDisplay }}</span>
                </td>
                <td data-label="Supplier">
                    <x-catalog.detail-product-supplier
                        :supplier="$supplier"
                        :name="$supplierName !== 'Not linked' ? $supplierName : null"
                        :meta="$supplierMeta"
                        fallback="Not linked"
                    />
                </td>
                <td class="ft-order-product-quantity" data-label="Quantity">
                    <strong class="ft-order-product-static-value">{{ number_format($quantity) }} units</strong>
                </td>
                <td class="ft-order-product-price" data-label="Unit price">
                    <strong class="ft-order-product-static-value">{{ $symbol }}{{ number_format($unitPrice, 2) }}</strong>
                    @if($item->supplier_id)
                        <small style="display:block;color:var(--ft-muted,#708199)">
                            Supplier:
                            @if($canSeeSupplierCosts)
                                {{ $item->supplier_unit_price !== null ? $symbol.number_format((float) $item->supplier_unit_price, 2) : 'Not set' }}
                            @else
                                •••• restricted
                            @endif
                        </small>
                    @endif
                    @if($item->remote_area_surcharge !== null && (float) $item->remote_area_surcharge > 0)
                        <small style="display:block;color:var(--ft-muted,#708199)">Remote surcharge/unit: {{ $symbol }}{{ number_format((float) $item->remote_area_surcharge, 2) }}</small>
                    @endif
                </td>
                <td class="ft-order-product-notes" data-label="Notes">
                    <span class="ft-order-product-note-value {{ filled($item->notes) ? '' : 'is-empty' }}">{{ $item->notes ?: 'Add notes' }}</span>
                </td>
                <x-catalog.detail-product-updated
                    :primary="$updatedBy"
                    :secondary="$updatedRelative"
                />
                <td class="ft-order-product-actions-cell" data-label="Actions">
                    <x-catalog.detail-product-actions
                        :item-id="$item->id"
                        :can-edit="!$removed && $canEdit"
                        edit-method="openEditOrderProductModal"
                        :can-delete="!$removed && $canDelete"
                        remove-method="removeJobItem"
                        confirm-text="Remove this product from the active Order? It will remain available in audit history."
                        :can-restore="$removed && $canEdit"
                        restore-method="restoreJobItem"
                    />
                </td>
            </tr>

            @if(!$removed && $canEdit && $showEditOrderProductModal && (int) $editOrderProductItemId === (int) $item->id)
                <tr class="ft-detail-product-editor-row" wire:key="order-product-inline-editor-{{ $item->id }}">
                    <td colspan="8">
                        <x-catalog.detail-product-edit
                            :wire-key="'order-product-edit-'.$item->id"
                            variant="order"
                            record-label="Order"
                            search-model="editOrderProductSearch"
                            :search-value="$editOrderProductSearch"
                            :search-results="$editOrderProductSearchResults"
                            :search-suppliers="$editOrderProductSearchSuppliers"
                            :result-total="$editOrderProductResultTotal"
                            :show-all-results="$editOrderProductShowAllResults"
                            show-all-method="showAllEditOrderProductResults"
                            select-method="selectEditOrderProduct"
                            :selected-product="$editOrderProductSelectedProduct"
                            :selected-supplier="$editOrderProductSelectedSupplier"
                            :category-value="$editOrderProductCategory"
                            quantity-model="editOrderProductQuantity"
                            :quantity-value="$editOrderProductQuantity"
                            :unit-price-value="$editOrderProductUnitPrice"
                            notes-model="editOrderProductNotes"
                            :notes-value="$editOrderProductNotes"
                            supplier-model="editOrderProductSupplierId"
                            :supplier-value="$editOrderProductSupplierId"
                            :supplier-label="$editOrderProductSupplierLabel"
                            :supplier-editable="true"
                            supplier-action="updateEditOrderProductSupplierFromSelector"
                            :currency-symbol="$symbol"
                            close-method="closeEditOrderProductModal"
                            save-method="saveEditOrderProductModal"
                            selected-error-key="editOrderProductSelectedId"
                            quantity-error-key="editOrderProductQuantity"
                            unit-price-error-key="editOrderProductUnitPrice"
                            notes-error-key="editOrderProductNotes"
                            supplier-error-key="editOrderProductSupplierId"
                        />
                    </td>
                </tr>
            @endif
        @endforeach
    @endif

    <x-slot:afterTable>
        @if($showAddJobProductForm && $canCreate)
            <div class="ft-detail-products-inline-add" wire:key="order-add-product-inline-{{ $job->id }}" x-data x-on:keydown.escape.window="$wire.closeAddJobProductForm()">
                <x-catalog.detail-add-product
                    :wire-key="'job-detail-add-product-'.$job->id"
                    search-model="jobProductSearch"
                    :search-value="$jobProductSearch"
                    :search-results="$jobProductSearchResults"
                    :search-suppliers="$jobProductSearchSuppliers"
                    :result-total="$jobProductResultTotal"
                    :show-all-results="$jobProductShowAllResults"
                    show-all-method="showAllJobProductResults"
                    select-method="selectJobProduct"
                    :selected-product="$jobProductSelectedProduct"
                    :selected-supplier="$jobProductSelectedSupplier"
                    :category-value="$jobProductCategory"
                    quantity-model="jobProductQuantity"
                    :quantity-value="$jobProductQuantity"
                    unit-price-model="jobProductUnitPrice"
                    :unit-price-value="$jobProductUnitPrice"
                    supplier-model="jobProductSupplierId"
                    :supplier-value="$jobProductSupplierId"
                    :supplier-label="$jobProductSupplierLabel"
                    :supplier-locked="$jobProductSupplierLocked"
                    :supplier-skipped="$jobProductSupplierSkipped"
                    :supplier-required="true"
                    :currency-symbol="$symbol"
                    close-method="closeAddJobProductForm"
                    save-method="saveJobProduct({{ $job->id }})"
                    selected-error-key="jobProductSelectedId"
                    quantity-error-key="jobProductQuantity"
                    unit-price-error-key="jobProductUnitPrice"
                    supplier-error-key="jobProductSupplierId"
                    missing-supplier-method="openJobProductSupplierResolution"
                />
            </div>
        @endif
    </x-slot:afterTable>

    <x-slot:footer>
        <span>Product, quantity, price and supplier changes are recorded in order activity.</span>
        @if($canView && $canCreate && !$showAddJobProductForm)
            <button type="button" class="ft-order-product-add-another" wire:click="openAddJobProductForm({{ $job->id }})" wire:loading.attr="disabled" wire:target="openAddJobProductForm">
                <span aria-hidden="true">+</span> Add another product
            </button>
        @endif
    </x-slot:footer>
</x-catalog.detail-products-card>
