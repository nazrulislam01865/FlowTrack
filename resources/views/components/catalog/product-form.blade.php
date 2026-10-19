@props([
    'editProduct' => null,
    'parents' => collect(),
    'allProductCategories' => collect(),
    'mainCategories' => collect(),
    'subcategories' => collect(),
    'clients' => collect(),
    'canCreateProductCategory' => false,
    'productImagePreview' => null,
    'clientAvailabilityMode' => 'all',
    'clientIds' => [],
    'productSupplierId' => null,
    'productSupplierIds' => [],
    'certificateUpload' => null,
    'templateUpload' => null,
    'removeCertificate' => false,
    'removeTemplate' => false,
    'categoryCreator' => null,
    'selectedMainCategory' => '',
    'selectedProductCategoryId' => null,
    'selectedSubcategory' => '',
    'pricePreview' => [],
    'customerPricePreview' => [],
    'clientPriceRows' => [],
    'supplierPriceRows' => [],
    'remoteSurchargePreview' => [],
    'productOptions' => [],
    'productOptionUploads' => [],
    'shipmentUrgencies' => collect(),
    'productShipmentUrgencies' => [],
    'shipmentUrgencyPickerOpen' => false,
    'shipmentUrgencyPickerSelection' => [],
    'newProductCategoryMain' => '',
    'newSubcategoryProductCategoryId' => null,
    'taxonomyReady' => true,
    'shipmentOptionsReady' => true,
])
@php
    $isEdit = (bool) $editProduct;
    $displayCode = $editProduct?->productDisplayCode() ?? 'Generated after creation';
    $existingDocs = collect($editProduct?->productDocuments() ?? []);
    $certificateDoc = $removeCertificate ? null : $existingDocs->firstWhere('kind', 'certificate');
    $templateDoc = $removeTemplate ? null : $existingDocs->firstWhere('kind', 'template');
    $clientLabelsById = collect($clients)->mapWithKeys(fn ($client) => [
        (int) data_get($client, 'id', 0) => (string) data_get($client, 'name', data_get($client, 'label', '')),
    ]);
    // Only selected clients get visible editors; preserve legacy price rows in
    // component state so a change in availability never deletes saved pricing.
    $taggedClientIds = array_map('intval', $clientIds);
    $visibleClientPriceRows = collect($clientPriceRows)->filter(fn ($row) =>
        in_array((int) ($row['client_id'] ?? 0), $taggedClientIds, true));
    $productCategoryOptions = collect($parents)->map(fn($category) => [
        'id' => $category->id,
        'label' => $category->name,
        'meta' => $category->code,
    ]);
@endphp
<div class="ft-product-page ft-product-form-page ft-form-standard ft-form-standard--product" data-ft-feedback-scope="form" x-data="{dragging:false}">
    <div class="ft-product-page-breadcrumb"><button type="button" wire:click="close">Products</button><span>/</span><strong>{{ $isEdit ? 'Edit product' : 'Create product' }}</strong></div>
    <header class="ft-product-form-header">
        <div><h1>{{ $isEdit ? 'Edit product' : 'Create product' }}</h1><p>{{ $isEdit ? 'Update the product information, linked suppliers, availability and supporting documents.' : 'Add a product and link its category, suppliers, image and supporting documents.' }}</p></div>
        @if($isEdit)
            <div class="ft-product-form-top-actions"><button type="button" class="ft-product-page-btn is-secondary" wire:click="close">Cancel</button><button type="button" class="ft-product-page-btn is-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save,productImage,productCertificateUpload,productTemplateUpload">Save changes</button></div>
        @endif
    </header>

    <div class="ft-product-form-shell">
        <x-catalog.product-section number="1" title="Product information">
            <div class="ft-product-form-info-grid">
                <div class="ft-product-form-fields">
                    <div class="ft-form-grid ft-form-grid-3">
                        <label class="ft-product-field"><span>Product code</span><div class="ft-product-locked-field">{{ $displayCode }} <span>⌑</span></div><small>Generated automatically after the product is created.</small></label>
                        <label class="ft-product-field"><span>Reference product code <em>Optional</em></span><input wire:model.blur="productReferenceCode" placeholder="Client or supplier reference"><small>Client or supplier reference used for search and matching.</small>@error('productReferenceCode')<b class="validation-error">{{ $message }}</b>@enderror</label>
                        <label class="ft-product-field"><span>Product name <i>*</i></span><input wire:model.blur="name" placeholder="Enter product name">@error('name')<b class="validation-error">{{ $message }}</b>@enderror</label>
                    </div>
                    <div class="ft-product-search-select-wrap ft-product-default-supplier">
                        <x-ui.search-select
                            class="ft-product-search-select"
                            label="Default supplier"
                            property="productSupplierId"
                            type="suppliers"
                            context="master-product"
                            :value="$productSupplierId"
                            placeholder="No default supplier"
                            :clearable="true"
                            :optional="true"
                            :fixed-menu="true"
                            :menu-width="360"
                            search-placeholder="Search supplier…"
                        />
                        <small class="ft-product-help">Create Order uses this supplier by default; choosing it also links it to the product.</small>
                        @error('productSupplierId')<b class="validation-error">{{ $message }}</b>@enderror
                    </div>
                    <div class="ft-product-search-select-wrap ft-product-linked-suppliers">
                        <x-ui.multi-select
                            label="Linked suppliers"
                            property="productSupplierIds"
                            type="suppliers"
                            context="master-product"
                            :values="$productSupplierIds"
                            placeholder="Search and select suppliers"
                            :fixed-menu="true"
                            :menu-width="380"
                            :max-selected="100"
                        />
                        <small class="ft-product-help">A product can belong to multiple suppliers. Only these suppliers will be available when changing its supplier in Create Order. Remove a supplier here to unlink it; clear the default first if necessary.</small>
                        @error('productSupplierIds')<b class="validation-error">{{ $message }}</b>@enderror
                        @error('productSupplierIds.*')<b class="validation-error">{{ $message }}</b>@enderror
                    </div>
                    <label class="ft-product-field ft-product-size-field"><span>Product size</span><textarea wire:model.blur="productSize" rows="4" placeholder='Add size/specification details. Use a new line for each item, e.g. width, finished length, material, capacity or dimensions.'></textarea><small>Enter multiple size/specification details on separate lines so the information stays easy to read.</small>@error('productSize')<b class="validation-error">{{ $message }}</b>@enderror</label>
                    <div class="ft-product-client-scope">
                        <span class="ft-product-field-title">Client availability</span>
                        <div class="ft-product-radio-row"><label><input type="radio" value="all" wire:model.live="productClientAvailabilityMode"> All clients</label><label><input type="radio" value="specific" wire:model.live="productClientAvailabilityMode"> Selected clients</label></div>
                        @if($clientAvailabilityMode === 'specific')
                            <x-ui.multi-select
                                label="Select clients"
                                property="productClientIds"
                                type="clients"
                                context="master-product"
                                :values="$clientIds"
                                :initial-options="$clients"
                                placeholder="Search and select clients"
                                :fixed-menu="true"
                                :menu-width="380"
                                :max-selected="100"
                                class="ft-product-client-multi-select"
                            />
                            <small class="ft-product-help">Only selected clients can find and use this product. Results are loaded in bounded pages.</small>
                            @error('productClientIds')<x-ui.validation-message :message="$message" />@enderror
                            @error('productClientIds.*')<x-ui.validation-message :message="$message" />@enderror
                        @endif
                    </div>
                </div>
                <div class="ft-product-image-column">
                    <span class="ft-product-field-title">Product image <em>Optional</em></span>
                    <div class="ft-product-image-drop" :class="dragging ? 'is-dragging':''" x-on:dragover.prevent="dragging=true" x-on:dragleave.prevent="dragging=false" x-on:drop.prevent="dragging=false;if($event.dataTransfer.files.length){const dt=new DataTransfer();dt.items.add($event.dataTransfer.files[0]);$refs.productImage.files=dt.files;$refs.productImage.dispatchEvent(new Event('change',{bubbles:true}))}" x-on:click="$refs.productImage.click()">
                        <input x-ref="productImage" type="file" wire:model="productImage" accept="image/png,image/jpeg,image/webp">
                        @if($productImagePreview)<img src="{{ $productImagePreview }}" alt="Product preview">@else<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 5h16v14H4z"/><path d="m7 16 3.5-4 3 3 2-2 2.5 3"/></svg>@endif
                        <strong>Drop image or <span>browse</span></strong>
                    </div>
                    <small>PNG, JPG or WEBP · Max 5 MB</small>@error('productImage')<b class="validation-error">{{ $message }}</b>@enderror
                </div>
            </div>
        </x-catalog.product-section>

        @if($taxonomyReady)
        <x-catalog.product-section number="2" title="Category hierarchy" subtitle="Select from top to bottom. Each list is filtered by the selection above.">
            <div class="ft-form-grid ft-form-grid-3 ft-category-grid">
                <div class="ft-product-search-select-wrap">
                    <x-ui.search-select
                        class="ft-product-search-select"
                        wire:key="product-main-category-filter-{{ $isEdit ? 'edit' : 'create' }}"
                        label="Main category"
                        property="productFormMainCategory"
                        action="setProductTaxonomySelection"
                        :value="$selectedMainCategory"
                        placeholder="Select main category"
                        :options="$mainCategories"
                        :clearable="false"
                        :required="true"
                        :fixed-menu="true"
                        :menu-width="360"
                        search-placeholder="Search main category…"
                        footer-message="Type to search the available main categories."
                    />
                    @if($canCreateProductCategory)<button type="button" class="ft-product-inline-link" wire:click="openCategoryCreator('main')">+ Create main category</button>@endif
                    @error('productFormMainCategory')<b class="validation-error">{{ $message }}</b>@enderror
                </div>

                <div class="ft-product-search-select-wrap">
                    <x-ui.search-select
                        class="ft-product-search-select"
                        wire:key="product-category-filter-{{ $isEdit ? 'edit' : 'create' }}-{{ md5((string) $selectedMainCategory) }}"
                        label="Product category"
                        property="parentId"
                        action="setProductTaxonomySelection"
                        :value="$selectedProductCategoryId"
                        :placeholder="trim((string)$selectedMainCategory) === '' ? 'Select main category first' : 'Select product category'"
                        :options="$productCategoryOptions"
                        :disabled="trim((string)$selectedMainCategory) === ''"
                        :clearable="false"
                        :required="true"
                        :fixed-menu="true"
                        :menu-width="380"
                        search-placeholder="Search product category…"
                        footer-message="Type to search product categories in the selected main category."
                    />
                    @if($canCreateProductCategory)<button type="button" class="ft-product-inline-link" wire:click="openCategoryCreator('product')">+ Create product category</button>@endif
                    @error('parentId')<b class="validation-error">{{ $message }}</b>@enderror
                </div>

                <div class="ft-product-search-select-wrap">
                    <x-ui.search-select
                        class="ft-product-search-select"
                        wire:key="product-subcategory-filter-{{ $isEdit ? 'edit' : 'create' }}-{{ (int) ($selectedProductCategoryId ?? 0) }}"
                        label="Subcategory"
                        property="productSubcategory"
                        action="setProductTaxonomySelection"
                        :value="$selectedSubcategory"
                        :placeholder="$selectedProductCategoryId ? 'No subcategory' : 'Select product category first'"
                        :options="$subcategories"
                        :disabled="!$selectedProductCategoryId"
                        :clearable="true"
                        :optional="true"
                        :fixed-menu="true"
                        :menu-width="380"
                        search-placeholder="Search subcategory…"
                        footer-message="Subcategory is optional. Type to search available options."
                    />
                    @if($canCreateProductCategory)<button type="button" class="ft-product-inline-link" wire:click="openCategoryCreator('sub')">+ Create subcategory</button>@endif
                    @error('productSubcategory')<b class="validation-error">{{ $message }}</b>@enderror
                </div>
            </div>
            <small class="ft-product-help">Missing a category? Create it here without leaving the product form. Codes are generated automatically.</small>
        </x-catalog.product-section>

        @else
            <x-catalog.product-section number="2" title="Category hierarchy" subtitle="Category options load only when this section is needed.">
                <x-ui.progressive-section-loader section="product-taxonomy" :rows="4" />
            </x-catalog.product-section>
        @endif

        <x-catalog.product-section number="3" title="Product pricing" subtitle="Maintain supplier costs and client selling prices separately. Client prices automatically apply in orders and inquiries.">
            @if(app(\App\Services\Orders\SupplierCostVisibility::class)->allowed())
            <div class="ft-product-supplier-price-list">
                <div class="ft-product-price-section-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin:18px 0 10px">
                    <strong>Supplier-based price tables ({{ count($supplierPriceRows) }})</strong>
                </div>
                <small>Each tagged supplier has its own quantity-based cost table. Approved supplier requests appear here automatically. Supplier costs do not affect client selling prices.</small>
                @forelse($supplierPriceRows as $supplierPriceIndex => $supplierPrice)
                    <div class="ft-product-client-price-entry" wire:key="product-supplier-price-{{ $supplierPrice['key'] ?? $supplierPriceIndex }}" style="margin-top:14px;padding:14px;border:1px solid var(--ft-border,#d1d5db)">
                        <strong>{{ $supplierPrice['supplier_name'] ?? ('Supplier #'.$supplierPrice['supplier_id']) }}</strong>
                        <label class="ft-product-field ft-product-price-table-field" style="margin-top:12px">
                            <span>Quantity / Supplier unit price</span>
                            <textarea wire:model.change="productSupplierPrices.{{ $supplierPriceIndex }}.table" rows="5" spellcheck="false" placeholder="Paste the Excel Quantity and Product price table here"></textarea>
                            @error('productSupplierPrices.'.$supplierPriceIndex.'.table')<b class="validation-error">{{ $message }}</b>@enderror
                        </label>
                        @if(count($supplierPrice['preview'] ?? []))
                            <div class="ft-product-price-preview-wrap"><table class="ft-product-price-preview">
                                <thead><tr><th>Quantity</th>
                                    @foreach($supplierPrice['preview'] as $breakpoint)<th>{{ number_format((int) $breakpoint['quantity']) }}</th>@endforeach
                                </tr></thead><tbody><tr><th>Supplier price</th>
                                    @foreach($supplierPrice['preview'] as $breakpoint)<td>{{ (float) $breakpoint['price'] === 0.0 ? '0' : rtrim(rtrim(number_format((float) $breakpoint['price'], 6, '.', ''), '0'), '.') }}</td>@endforeach
                                </tr></tbody></table></div>
                        @endif
                    </div>
                @empty
                    <small>Tag a supplier above to enter that supplier's quantity and unit prices.</small>
                @endforelse
                @error('productSupplierPrices')
                    <div class="ft-product-price-refresh" style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <b class="validation-error">{{ $message }}</b>
                        <button type="button" class="ft-product-page-btn is-secondary"
                            wire:click="refreshProductSupplierPrices"
                            wire:confirm="Reload current supplier prices? Unsaved changes to supplier price tables will be replaced, but all other product fields will stay as they are.">Reload supplier prices</button>
                    </div>
                @enderror
            </div>
            @else
                <small>Supplier prices are restricted. Customer price tables remain editable.</small>
            @endif
            <div class="ft-product-client-price-list">
                <div class="ft-product-price-section-header" style="margin:18px 0 10px">
                    <strong>Client price tables ({{ $clientAvailabilityMode === 'specific' ? $visibleClientPriceRows->count() : 1 }})</strong>
                </div>
                <small>{{ $clientAvailabilityMode === 'all'
                    ? 'One quantity-based selling-price table applies to all clients. Supplier costs remain separate.'
                    : 'Each tagged client has its own quantity-based selling-price table, separate from supplier costs. Empty tables are not saved.' }}</small>
                @if($clientAvailabilityMode === 'specific')
                    @forelse($visibleClientPriceRows as $clientPriceIndex => $clientPrice)
                        <div class="ft-product-client-price-entry" wire:key="product-client-price-{{ $clientPrice['key'] ?? $clientPriceIndex }}" style="margin-top:14px;padding:14px;border:1px solid var(--ft-border,#d1d5db)">
                            <strong>{{ $clientLabelsById->get((int) ($clientPrice['client_id'] ?? 0)) ?: 'Client #'.($clientPrice['client_id'] ?? '') }}</strong>
                            <label class="ft-product-field ft-product-price-table-field" style="margin-top:12px">
                                <span>Quantity / Client unit price</span>
                                <textarea wire:model.change="productClientPrices.{{ $clientPriceIndex }}.table" rows="5" spellcheck="false" placeholder="Paste the Excel Quantity and Product price table here"></textarea>
                                @error('productClientPrices.'.$clientPriceIndex.'.table')<b class="validation-error">{{ $message }}</b>@enderror
                            </label>
                            @if(count($clientPrice['preview'] ?? []))
                                <div class="ft-product-price-preview-wrap"><table class="ft-product-price-preview">
                                    <thead><tr><th>Quantity</th>
                                        @foreach($clientPrice['preview'] as $breakpoint)<th>{{ number_format((int) $breakpoint['quantity']) }}</th>@endforeach
                                    </tr></thead><tbody><tr><th>Client price</th>
                                        @foreach($clientPrice['preview'] as $breakpoint)<td>{{ (float) $breakpoint['price'] === 0.0 ? '0' : rtrim(rtrim(number_format((float) $breakpoint['price'], 6, '.', ''), '0'), '.') }}</td>@endforeach
                                    </tr></tbody></table></div>
                            @endif
                        </div>
                    @empty
                        <small>Select one or more clients above to enter their quantity and selling prices.</small>
                    @endforelse
                @else
                    <div class="ft-product-client-price-entry" wire:key="product-all-clients-price" style="margin-top:14px;padding:14px;border:1px solid var(--ft-border,#d1d5db)">
                        <strong>All clients</strong>
                        <label class="ft-product-field ft-product-price-table-field" style="margin-top:12px">
                            <span>Quantity / Client unit price</span>
                            <textarea wire:model.change="productCustomerPriceTable" rows="5" spellcheck="false" placeholder="Paste the Excel Quantity and Product price table here"></textarea>
                            @error('productCustomerPriceTable')<b class="validation-error">{{ $message }}</b>@enderror
                        </label>
                        @if(count($customerPricePreview))
                            <div class="ft-product-price-preview-wrap"><table class="ft-product-price-preview">
                                <thead><tr><th>Quantity</th>
                                    @foreach($customerPricePreview as $breakpoint)<th>{{ number_format((int) $breakpoint['quantity']) }}</th>@endforeach
                                </tr></thead><tbody><tr><th>Client price</th>
                                    @foreach($customerPricePreview as $breakpoint)<td>{{ (float) $breakpoint['price'] === 0.0 ? '0' : rtrim(rtrim(number_format((float) $breakpoint['price'], 6, '.', ''), '0'), '.') }}</td>@endforeach
                                </tr></tbody></table></div>
                        @endif
                    </div>
                @endif
                @error('productClientPrices')<b class="validation-error">{{ $message }}</b>@enderror
            </div>
            <label class="ft-product-field ft-product-price-table-field" style="margin-top:18px">
                <span>Remote area surcharge prices (optional)</span>
                <small>Per-product, quantity-based surcharge per unit. Paste a Quantity row and a Product price row; these are separate from customer selling prices and supplier costs.</small>
                <textarea wire:model.blur="productRemoteSurchargeTable" rows="3" spellcheck="false" placeholder="Quantity&#9;100&#9;500&#9;1000&#10;Product price&#9;0.50&#9;0.40&#9;0.30"></textarea>
                @error('productRemoteSurchargeTable')<b class="validation-error">{{ $message }}</b>@enderror
            </label>
        </x-catalog.product-section>

        <x-catalog.product-section number="4" title="Product options" subtitle="Add optional choices such as color, size or material. An option can also add an extra unit charge." class="ft-product-options-section">
            <div class="ft-product-options-list">
                @foreach($productOptions as $optionIndex => $option)
                    @php
                        $optionUpload = $productOptionUploads[$optionIndex] ?? null;
                        $optionPreview = null;
                        if ($optionUpload && method_exists($optionUpload, 'temporaryUrl')) {
                            try { $optionPreview = $optionUpload->temporaryUrl(); } catch (\Throwable $e) { $optionPreview = null; }
                        }
                        $optionPreview ??= data_get($option, 'image_url');
                    @endphp
                    <div class="ft-product-option-row" wire:key="product-option-{{ data_get($option, 'key', $optionIndex) }}">
                        <label class="ft-product-field ft-product-option-label-field">
                            <span>Label <i>*</i></span>
                            <input wire:model.blur="productOptions.{{ $optionIndex }}.label" placeholder="e.g. Red, Large, Cotton">
                            @error('productOptions.'.$optionIndex.'.label')<b class="validation-error">{{ $message }}</b>@enderror
                        </label>
                        <label class="ft-product-field ft-product-option-charge-field">
                            <span>Extra charge <em>Optional</em></span>
                            <input type="number" min="0" step="0.01" wire:model.blur="productOptions.{{ $optionIndex }}.extra_charge" placeholder="0.00" inputmode="decimal">
                            @error('productOptions.'.$optionIndex.'.extra_charge')<b class="validation-error">{{ $message }}</b>@enderror
                        </label>
                        <label class="ft-product-field ft-product-option-image-field">
                            <span>Image <em>Optional</em></span>
                            <span class="ft-product-option-image-input">
                                @if($optionPreview)<img src="{{ $optionPreview }}" alt="">@else<span class="ft-product-option-image-placeholder">No image</span>@endif
                                <span class="ft-product-option-image-button">{{ $optionPreview ? 'Change image' : 'Choose image' }}</span>
                                <input type="file" wire:model="productOptionUploads.{{ $optionIndex }}" accept="image/png,image/jpeg,image/webp">
                            </span>
                            @error('productOptionUploads.'.$optionIndex)<b class="validation-error">{{ $message }}</b>@enderror
                        </label>
                        <button type="button" class="ft-product-option-remove" wire:click="removeProductOption({{ $optionIndex }})" aria-label="Remove option">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" class="ft-product-option-add" wire:click="addProductOption">+ Add option</button>
            @error('productOptions')<b class="validation-error">{{ $message }}</b>@enderror
        </x-catalog.product-section>

        @if($shipmentOptionsReady)
            <x-catalog.product-shipment-urgencies
                number="5"
                :shipment-urgencies="$shipmentUrgencies"
                :selected-urgencies="$productShipmentUrgencies"
                :picker-open="$shipmentUrgencyPickerOpen"
                :picker-selection="$shipmentUrgencyPickerSelection"
            />
        @else
            <x-catalog.product-section number="5" title="Shipping urgencies" subtitle="Shipping urgency master data loads only when this section is needed." class="ft-product-shipping-section">
                <x-ui.progressive-section-loader section="product-shipping-urgencies" :rows="3" />
            </x-catalog.product-section>
        @endif

        <x-catalog.product-section number="6" title="Supporting documents" subtitle="Add the product files now or replace them later while editing.">
            <div class="ft-product-support-grid is-friendly">
                <label class="ft-product-field ft-certificate-number-field"><span>Test certificate number <em>Optional</em></span><input wire:model.blur="productTestCertificateNumber" placeholder="T-26423684-06-R1"><small>Reference number printed on the test certificate.</small>@error('productTestCertificateNumber')<b class="validation-error">{{ $message }}</b>@enderror</label>
                <x-catalog.file-upload
                    model="productCertificateUpload"
                    label="Certificate & Test Report"
                    :hint="\App\Support\AttachmentUpload::helperText(10)"
                    :accept="\App\Support\AttachmentUpload::accept()"
                    :upload="$certificateUpload"
                    :current="$certificateDoc"
                    clear-action="clearProductCertificateUpload"
                    remove-current-action="removeProductCertificate"
                />
                <x-catalog.file-upload
                    model="productTemplateUpload"
                    label="Product template"
                    :hint="\App\Support\AttachmentUpload::helperText(10)"
                    :accept="\App\Support\AttachmentUpload::accept()"
                    :upload="$templateUpload"
                    :current="$templateDoc"
                    clear-action="clearProductTemplateUpload"
                    remove-current-action="removeProductTemplate"
                />
            </div>
            <div class="ft-product-document-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>
                <span>These documents stay linked to this product and are available when it is added to an Inquiry or Order.</span>
            </div>
        </x-catalog.product-section>

        <footer class="ft-product-form-footer"><span>Required fields are marked <i>*</i></span><div><button type="button" class="ft-product-page-btn is-secondary" wire:click="close">Cancel</button>@if(!$isEdit)<button type="button" class="ft-product-page-btn is-secondary" wire:click="saveProductDraft" wire:loading.attr="disabled">Save as draft</button>@endif<button type="button" class="ft-product-page-btn is-primary" wire:click="save" wire:loading.attr="disabled">{{ $isEdit ? 'Save changes' : 'Create product' }}</button></div></footer>
    </div>

    @if($categoryCreator)
        <x-catalog.category-creator
            :level="$categoryCreator"
            :main-categories="$mainCategories"
            :product-categories="$allProductCategories"
            :selected-main-category="$newProductCategoryMain"
            :selected-product-category-id="$newSubcategoryProductCategoryId"
        />
    @endif
</div>
