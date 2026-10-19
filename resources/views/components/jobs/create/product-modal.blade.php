@if($showCreateOrderProductModal)
    <div class="overlay livewire-overlay ft-product-create-overlay" wire:click.self="closeCreateOrderProductModal"></div>
    <div
        class="modal livewire-modal ft-product-create-modal ft-order-create-product-modal"
        x-data="{ categoryOpen: false, creatingCategory: false, dragging: false }"
        x-on:create-order-product-category-selected.window="categoryOpen = false; creatingCategory = false"
        x-on:create-order-product-category-created.window="categoryOpen = false; creatingCategory = false"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ft-order-create-product-title"
    >
        <div class="ft-product-create-head">
            <div>
                <h2 id="ft-order-create-product-title">Create new product</h2>
                <p>Add this product to the catalog and select it for this Order.</p>
            </div>
            <button type="button" class="ft-product-create-close" wire:click="closeCreateOrderProductModal" aria-label="Close">&times;</button>
        </div>

        <div class="ft-product-create-body">
            <div class="ft-product-create-field ft-product-sequence-field is-current-step">
                <label><b class="ft-product-step-number">1</b> SKU / Product code <em>Optional</em></label>
                <div class="ft-product-create-input-wrap {{ $hasDuplicateCode ? 'is-duplicate' : (($manualProductCode !== '' && !$productCodeFormatValid) ? 'has-warning' : ($productCodeReady ? 'is-valid' : '')) }}">
                    <input
                        type="text"
                        wire:model.live.debounce.220ms="newProductCode"
                        maxlength="40"
                        autocomplete="off"
                        placeholder="Leave blank to generate automatically"
                        aria-describedby="ft-new-product-code-help"
                    >
                    @if($hasDuplicateCode || ($manualProductCode !== '' && !$productCodeFormatValid))
                        <svg class="ft-order-product-error-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></svg>
                    @elseif($productCodeReady)
                        <svg class="ft-product-valid-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m5 12 4 4L19 6"/></svg>
                    @endif
                </div>
                @if($hasDuplicateCode)
                    <div class="ft-order-product-duplicate-message">{{ $duplicateProduct->trashed() ? 'This product code is reserved by an archived product.' : 'This product code already exists.' }}</div>
                    <div class="ft-order-product-duplicate-card">
                        <span class="ft-order-product-thumb">
                            @if($duplicateProduct->productImageUrl())
                                <img src="{{ $duplicateProduct->productImageUrl() }}" alt="">
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                            @endif
                        </span>
                        <span class="ft-order-product-duplicate-copy">
                            <strong>{{ $duplicateProduct->name }}</strong>
                            <span>SKU: {{ $duplicateProduct->code }} <i>&bull;</i> {{ $duplicateProduct->parent?->name ?? 'Uncategorized' }}</span>
                            <small class="{{ !$duplicateProduct->trashed() && $duplicateProduct->status === 'active' ? 'is-active' : 'is-inactive' }}">{{ $duplicateProduct->trashed() ? 'Archived' : ucfirst($duplicateProduct->status) }}</small>
                        </span>
                        @if(!$duplicateProduct->trashed() && $duplicateProduct->status === 'active')
                            <button type="button" wire:click="selectDuplicateCreateOrderProduct({{ $duplicateProduct->id }})">Select existing</button>
                        @else
                            <span class="ft-order-product-duplicate-inactive">{{ $duplicateProduct->trashed() ? 'Archived product' : 'Inactive product' }}</span>
                        @endif
                    </div>
                @else
                    @if($manualProductCode !== '' && !$productCodeFormatValid)
                        <small id="ft-new-product-code-help" class="ft-product-step-error">Use letters, numbers, dots, dashes or underscores only. Maximum 40 characters.</small>
                    @else
                        <small id="ft-new-product-code-help">Optional. Enter your own unique SKU, or leave this blank and FlowTrack will generate one automatically.</small>
                    @endif
                @endif
                @error('newProductCode')<div class="validation-error">{{ $message }}</div>@enderror
            </div>

            @if($canViewProductCategories)
            <div class="ft-product-create-field ft-product-category-field ft-product-sequence-field {{ !$productCodeReady ? 'is-step-locked' : '' }}" x-on:click.outside="categoryOpen = false">
                <label><b class="ft-product-step-number">2</b> Product category <span>*</span></label>
                <div class="ft-product-category-picker">
                    <div class="ft-product-category-input-wrap" :class="categoryOpen ? 'is-open' : ''">
                        <svg class="ft-product-category-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input
                            type="text"
                            wire:model.live.debounce.220ms="newProductCategorySearch"
                            x-on:focus="categoryOpen = true"
                            x-on:click="categoryOpen = true"
                            x-on:keydown.escape="categoryOpen = false"
                            placeholder="{{ $productCodeReady ? 'Search or create a category' : 'Fix the SKU format first' }}"
                            autocomplete="off"
                            aria-label="Product category"
                            @disabled(!$productCodeReady)
                        >
                        @if($newProductSelectedCategory)
                            <svg class="ft-order-product-category-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m5 12 4 4L19 6"/></svg>
                        @endif
                        <svg class="ft-product-category-chevron" :class="categoryOpen ? 'is-open' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m7 10 5 5 5-5"/></svg>
                    </div>

                    <div class="ft-product-category-menu" x-cloak x-show="categoryOpen && {{ $productCodeReady ? 'true' : 'false' }}" x-transition.origin.top>
                        @if($categorySearchValue !== '' && $newProductCategoryMatches->isEmpty())
                            <div class="ft-product-category-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                <div><strong>No category found</strong><span>No category matches '{{ $categorySearchValue }}'.</span></div>
                            </div>
                        @elseif($categorySuggestions->isNotEmpty())
                            <div class="ft-product-category-list">
                                @foreach($categorySuggestions as $category)
                                    <button type="button" wire:click="selectCreateOrderProductCategory({{ $category->id }})" class="{{ (int) $newProductCategoryId === (int) $category->id ? 'is-selected' : '' }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                                        <span>{{ $category->name }}</span>
                                        @if((int) $newProductCategoryId === (int) $category->id)
                                            <svg class="ft-product-category-row-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="m5 12 4 4L19 6"/></svg>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if($productCodeReady && $categorySearchValue !== '' && !$newProductHasExactCategory && $canCreateProductCategory)
                            <button type="button" class="ft-product-category-create-row" wire:click="beginCreateOrderProductCategory" x-on:click="creatingCategory = true; categoryOpen = true">
                                <span class="ft-product-category-plus">+</span>
                                <span class="ft-product-category-create-copy"><strong>Create '{{ $categorySearchValue }}'</strong><small>The category will be created and selected for this product.</small></span>
                                <span class="ft-product-category-permission">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="13" r="6"/><path d="M9 7V5.8a3 3 0 0 1 6 0V7M12 11v4"/></svg>
                                    You have permission
                                </span>
                            </button>
                        @endif

                        @if($categorySearchValue !== '' && $newProductSimilarCategories->isNotEmpty())
                            <div class="ft-product-category-similar">
                                <span>Similar categories</span>
                                @foreach($newProductSimilarCategories as $category)
                                    <button type="button" wire:click="selectCreateOrderProductCategory({{ $category->id }})">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                        <span>{{ $category->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <div class="ft-product-category-create-form" x-cloak x-show="creatingCategory" x-transition>
                            <label>New category name</label>
                            <div>
                                <input type="text" wire:model="newProductCategoryName" maxlength="255" aria-label="New category name">
                                <button type="button" class="ghost" wire:click="cancelCreateOrderProductCategory" x-on:click="creatingCategory = false">Cancel</button>
                                <button type="button" class="primary" wire:click="createCreateOrderProductCategory" wire:loading.attr="disabled" wire:target="createCreateOrderProductCategory">Create category</button>
                            </div>
                            @error('newProductCategoryName')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                @if(!$productCodeReady)
                    <small class="ft-product-step-hint">Fix the SKU before continuing, or clear it to let FlowTrack generate one automatically.</small>
                @elseif(!$newProductSelectedCategory)
                    <small class="ft-product-step-hint">Select an existing category or create a new category before continuing.</small>
                @endif
                @error('newProductCategoryId')<div class="validation-error">{{ $message }}</div>@enderror
            </div>
            @else
            <div class="ft-product-create-field ft-product-category-field ft-product-sequence-field is-step-locked">
                <label><b class="ft-product-step-number">2</b> Product category <span>*</span></label>
                <div class="ft-create-note">Product Categories <b>View</b> permission is required to select a category for a new product.</div>
            </div>
            @endif

            <div class="ft-product-create-field ft-product-sequence-field {{ !$productCategoryReady ? 'is-step-locked' : '' }}">
                <label><b class="ft-product-step-number">3</b> Product name <span>*</span></label>
                <div class="ft-product-create-input-wrap {{ $hasSimilarProductName ? 'has-warning' : (trim($newProductName) !== '' ? 'is-valid' : '') }}">
                    <input
                        type="text"
                        wire:model.live.debounce.220ms="newProductName"
                        maxlength="255"
                        autocomplete="off"
                        placeholder="{{ $productCategoryReady ? 'Enter product name' : 'Select a product category first' }}"
                        @disabled(!$productCategoryReady)
                    >
                    @if($hasSimilarProductName)
                        <svg class="ft-order-product-warning-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg>
                    @elseif(trim($newProductName) !== '')
                        <svg class="ft-product-valid-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m5 12 4 4L19 6"/></svg>
                    @endif
                </div>
                @if($hasSimilarProductName)
                    <div class="ft-order-product-name-warning">
                        <span>A product with this name may already exist.</span>
                        <button type="button" wire:click="viewSimilarCreateProducts">View similar products</button>
                    </div>
                @endif
                @if(!$productCategoryReady)
                    <small class="ft-product-step-hint">Complete step 2 first. Product name becomes available after a category is selected.</small>
                @endif
                @error('newProductName')<div class="validation-error">{{ $message }}</div>@enderror
            </div>

            <div class="ft-product-create-field ft-product-sequence-field">
                <label><b class="ft-product-step-number">4</b> Default supplier <em>Optional</em></label>
                <x-ui.search-select
                    class="ft-product-create-supplier-select"
                    label="Default supplier"
                    property="newProductSupplierId"
                    :options="$newProductSupplierOptions"
                    :value="$newProductSupplierId"
                    placeholder="Select supplier"
                    :clearable="true"
                    :required="false"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="360"
                    search-placeholder="Search supplier by name or code…"
                    footer-message="Active suppliers from Supplier Master Data."
                />
                <small class="ft-product-step-hint">Optional. Choose any active supplier from Supplier Master Data, or leave this empty and assign one later.</small>
                @error('newProductSupplierId')<div class="validation-error">{{ $message }}</div>@enderror
            </div>

            <div class="ft-product-create-field ft-product-create-image-field ft-product-sequence-field {{ !$productNameReady ? 'is-step-locked' : '' }}">
                <label><b class="ft-product-step-number">5</b> Product image <em>Optional</em></label>
                <div class="ft-product-create-image-row">
                    <div
                        class="ft-product-drop-zone {{ !$productNameReady ? 'is-step-disabled' : '' }}"
                        @if($productNameReady)
                            :class="dragging ? 'is-dragging' : ''"
                            x-on:dragover.prevent="dragging = true"
                            x-on:dragleave.prevent="dragging = false"
                            x-on:drop.prevent="dragging = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.orderProductFile.files = dt.files; $refs.orderProductFile.dispatchEvent(new Event('change', { bubbles: true })); }"
                            x-on:click="$refs.orderProductFile.click()"
                            role="button"
                            tabindex="0"
                            x-on:keydown.enter.prevent="$refs.orderProductFile.click()"
                            x-on:keydown.space.prevent="$refs.orderProductFile.click()"
                        @else
                            aria-disabled="true"
                        @endif
                    >
                        @if($productNameReady)
                            <input x-ref="orderProductFile" type="file" wire:model="newProductImage" accept="image/png,image/jpeg,image/webp" tabindex="-1">
                        @endif
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 5h16v14H4z"/><path d="m7 16 3.5-4 3 3 2-2 2.5 3"/><circle cx="15.5" cy="9" r="1.4"/></svg>
                        <div>
                            @if($productNameReady)
                                <strong wire:loading.remove wire:target="newProductImage">Drop an image here or <span>browse</span></strong>
                                <strong wire:loading wire:target="newProductImage">Preparing image...</strong>
                                <small>PNG, JPG or WEBP&nbsp;&nbsp;&bull;&nbsp;&nbsp;Max 5 MB</small>
                            @else
                                <strong>Complete the product name first</strong>
                                <small>Image upload unlocks after the required product details are complete.</small>
                            @endif
                        </div>
                    </div>
                    <div class="ft-product-create-image-preview">
                        @if($newProductImagePreview)
                            <img src="{{ $newProductImagePreview }}" alt="Product image preview">
                        @else
                            <span aria-hidden="true"></span>
                        @endif
                    </div>
                </div>
                @if(!$productNameReady)
                    <small class="ft-product-step-hint">Complete step 3 to enable the optional product image.</small>
                @endif
                @error('newProductImage')<div class="validation-error">{{ $message }}</div>@enderror
            </div>

            @if($hasDuplicateCode || $hasSimilarProductName)
                <div class="ft-order-product-review-warning">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg>
                    <span>Review the existing product before creating a duplicate.</span>
                </div>
            @endif
        </div>

        <div class="ft-product-create-foot">
            <div class="ft-product-create-permission-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="13" r="6"/><path d="M9 7V5.8a3 3 0 0 1 6 0V7M12 11v4"/></svg>
                <span>
                    Product create permission granted.
                    @if($canCreateProductCategory)
                        Product Category create permission granted.
                    @elseif($canViewProductCategories)
                        Product Categories are view-only for this role.
                    @else
                        Product Category view permission is required to finish a new product.
                    @endif
                </span>
            </div>
            <div class="ft-product-create-actions">
                <button type="button" class="ghost" wire:click="closeCreateOrderProductModal">Cancel</button>
                @if($hasDuplicateCode && !$duplicateProduct->trashed() && $duplicateProduct->status === 'active')
                    <button type="button" class="primary ft-order-select-existing-button" wire:click="selectDuplicateCreateOrderProduct({{ $duplicateProduct->id }})">Select existing product</button>
                @endif
                <button
                    type="button"
                    class="primary"
                    wire:click="createAndAddOrderProduct"
                    wire:loading.attr="disabled"
                    wire:target="createAndAddOrderProduct,newProductImage"
                    @disabled(!$productNameReady || $hasDuplicateCode)
                >Create &amp; add product</button>
            </div>
        </div>
    </div>
@endif
