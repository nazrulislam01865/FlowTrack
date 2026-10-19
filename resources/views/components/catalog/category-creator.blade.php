@props([
    'level',
    'mainCategories' => collect(),
    'productCategories' => collect(),
    'selectedMainCategory' => '',
    'selectedProductCategoryId' => null,
])
@php
    $title = match ($level) {
        'main' => 'Create main category',
        'product' => 'Create product category',
        'sub' => 'Create subcategory',
        default => 'Create category',
    };
    $subtitle = match ($level) {
        'main' => 'Add a reusable top-level category. The code is generated automatically.',
        'product' => 'Choose the main category, then add the product category details.',
        'sub' => 'Choose the product category, then add the optional subcategory.',
        default => '',
    };
    $saveMethod = match ($level) {
        'main' => 'createMainCategory',
        'product' => 'createProductCategory',
        'sub' => 'createProductSubcategory',
        default => 'closeCategoryCreator',
    };
    $subcategoryProductCategoryOptions = collect($productCategories)->map(function ($category) {
        $main = trim((string) (data_get($category->metadata, 'main_category') ?: data_get($category->metadata, 'excel_main_category')));
        return ['id' => $category->id, 'label' => $category->name, 'meta' => $main];
    });
@endphp
<div class="ft-taxonomy-modal-backdrop" wire:click.self="closeCategoryCreator" wire:keydown.escape="closeCategoryCreator" role="presentation">
    <section class="ft-taxonomy-modal" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="ft-taxonomy-title" x-data x-on:click.stop>
        <header class="ft-taxonomy-modal-header">
            <div>
                <h3 id="ft-taxonomy-title">{{ $title }}</h3>
                <p>{{ $subtitle }}</p>
            </div>
            <button type="button" class="ft-taxonomy-close" wire:click="closeCategoryCreator" aria-label="Close category form">×</button>
        </header>

        <div class="ft-taxonomy-modal-body">
            @if($level === 'main')
                <label class="ft-product-field">
                    <span>Name <i>*</i></span>
                    <input type="text" wire:model.blur="newMainCategoryName" maxlength="255" placeholder="Enter main category name" autofocus>
                    @error('newMainCategoryName')<b class="validation-error">{{ $message }}</b>@enderror
                </label>
                <label class="ft-product-field">
                    <span>Description</span>
                    <textarea wire:model.blur="newMainCategoryDescription" maxlength="5000" rows="4" placeholder="Add a short description"></textarea>
                    @error('newMainCategoryDescription')<b class="validation-error">{{ $message }}</b>@enderror
                </label>
            @elseif($level === 'product')
                <div class="ft-product-search-select-wrap">
                    <x-ui.search-select
                        class="ft-product-search-select ft-taxonomy-search-select"
                        label="Main category"
                        property="newProductCategoryMain"
                        :value="$selectedMainCategory"
                        placeholder="Select main category"
                        :options="$mainCategories"
                        :clearable="false"
                        :required="true"
                        :fixed-menu="true"
                        :menu-width="430"
                        search-placeholder="Search main category…"
                        footer-message="Type to search the available main categories."
                    />
                    @error('newProductCategoryMain')<b class="validation-error">{{ $message }}</b>@enderror
                </div>
                <label class="ft-product-field">
                    <span>Name <i>*</i></span>
                    <input type="text" wire:model.blur="newProductCategoryName" maxlength="255" placeholder="Enter product category name">
                    @error('newProductCategoryName')<b class="validation-error">{{ $message }}</b>@enderror
                </label>
                <label class="ft-product-field">
                    <span>Description</span>
                    <textarea wire:model.blur="newProductCategoryDescription" maxlength="5000" rows="4" placeholder="Add a short description"></textarea>
                    @error('newProductCategoryDescription')<b class="validation-error">{{ $message }}</b>@enderror
                </label>
            @elseif($level === 'sub')
                <div class="ft-product-search-select-wrap">
                    <x-ui.search-select
                        class="ft-product-search-select ft-taxonomy-search-select"
                        label="Product category"
                        property="newSubcategoryProductCategoryId"
                        :value="$selectedProductCategoryId"
                        placeholder="Select product category"
                        :options="$subcategoryProductCategoryOptions"
                        :clearable="false"
                        :required="true"
                        :fixed-menu="true"
                        :menu-width="430"
                        search-placeholder="Search product category…"
                        footer-message="Type to search product categories."
                    />
                    @error('newSubcategoryProductCategoryId')<b class="validation-error">{{ $message }}</b>@enderror
                </div>
                <label class="ft-product-field">
                    <span>Name <i>*</i></span>
                    <input type="text" wire:model.blur="newSubcategoryName" maxlength="255" placeholder="Enter subcategory name">
                    @error('newSubcategoryName')<b class="validation-error">{{ $message }}</b>@enderror
                </label>
                <label class="ft-product-field">
                    <span>Description</span>
                    <textarea wire:model.blur="newSubcategoryDescription" maxlength="5000" rows="4" placeholder="Add a short description"></textarea>
                    @error('newSubcategoryDescription')<b class="validation-error">{{ $message }}</b>@enderror
                </label>
            @endif

            <div class="ft-taxonomy-code-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>
                Code will be generated automatically when you create this category.
            </div>
        </div>

        <footer class="ft-taxonomy-modal-footer">
            <button type="button" class="ft-product-page-btn is-secondary" wire:click="closeCategoryCreator">Cancel</button>
            <button type="button" class="ft-product-page-btn is-primary" wire:click="{{ $saveMethod }}" wire:loading.attr="disabled" wire:target="{{ $saveMethod }}">
                <span wire:loading.remove wire:target="{{ $saveMethod }}">Create</span>
                <span wire:loading wire:target="{{ $saveMethod }}">Creating…</span>
            </button>
        </footer>
    </section>
</div>
