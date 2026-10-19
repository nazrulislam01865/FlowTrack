@props([
    'level' => 'main',
    'editing' => false,
    'readOnly' => false,
    'mainCategories' => collect(),
    'productCategories' => collect(),
    'selectedParentId' => null,
    'nameValue' => '',
    'descriptionValue' => '',
])
@php
    $titleLevel = match($level) { 'main' => 'main category', 'product' => 'product category', 'sub' => 'subcategory', default => 'category' };
    $mainOptions = collect($mainCategories)->map(fn($item) => ['id' => $item->id, 'label' => $item->name, 'meta' => $item->code]);
    $productOptions = collect($productCategories)->map(function($item){
        $main = trim((string)(data_get($item->metadata,'main_category') ?: data_get($item->metadata,'excel_main_category')));
        return ['id'=>$item->id,'label'=>$item->name,'meta'=>$main];
    });
@endphp
<div class="ft-category-editor-backdrop" wire:click.self="closeCategoryEditor" wire:keydown.escape="closeCategoryEditor">
    <section class="ft-category-editor" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="ft-category-editor-title" x-data x-on:click.stop>
        <header class="ft-category-editor-head">
            <div>
                <h2 id="ft-category-editor-title">{{ $readOnly ? 'View' : ($editing ? 'Edit' : 'Add') }} {{ $titleLevel }}</h2>
                <p>{{ $readOnly ? 'Review the category hierarchy and details.' : 'Codes are generated automatically and the hierarchy is used by Product create/edit.' }}</p>
            </div>
            <button type="button" wire:click="closeCategoryEditor" aria-label="Close">×</button>
        </header>

        <div class="ft-category-editor-body">
            @if(!$editing && !$readOnly)
                <label class="ft-category-editor-field">
                    <span>Category level <i>*</i></span>
                    <select wire:model.live="categoryEditorLevel">
                        <option value="main">Main category</option>
                        <option value="product">Product category</option>
                        <option value="sub">Subcategory</option>
                    </select>
                </label>
            @else
                <div class="ft-category-editor-level-pill">{{ ucfirst($titleLevel) }}</div>
            @endif

            @if($level === 'product')
                <div class="ft-category-editor-field">
                    @if($readOnly)
                        <span>Main category <i>*</i></span>
                        <div class="ft-category-editor-readonly">{{ optional(collect($mainCategories)->firstWhere('id', (int)$selectedParentId))->name ?? '—' }}</div>
                    @else
                        <x-ui.search-select
                            class="ft-product-search-select ft-taxonomy-search-select"
                            label="Main category"
                            property="categoryEditorParentId"
                            :value="$selectedParentId"
                            placeholder="Select main category"
                            :options="$mainOptions"
                            :clearable="false"
                            :required="true"
                            :fixed-menu="true"
                            :menu-width="430"
                            search-placeholder="Search main category…"
                        />
                    @endif
                    @error('categoryEditorParentId')<b class="validation-error">{{ $message }}</b>@enderror
                </div>
            @elseif($level === 'sub')
                <div class="ft-category-editor-field">
                    @if($readOnly)
                        <span>Product category <i>*</i></span>
                        <div class="ft-category-editor-readonly">{{ optional(collect($productCategories)->firstWhere('id', (int)$selectedParentId))->name ?? '—' }}</div>
                    @else
                        <x-ui.search-select
                            class="ft-product-search-select ft-taxonomy-search-select"
                            label="Product category"
                            property="categoryEditorParentId"
                            :value="$selectedParentId"
                            placeholder="Select product category"
                            :options="$productOptions"
                            :clearable="false"
                            :required="true"
                            :fixed-menu="true"
                            :menu-width="430"
                            search-placeholder="Search product category…"
                        />
                    @endif
                    @error('categoryEditorParentId')<b class="validation-error">{{ $message }}</b>@enderror
                </div>
            @endif

            <label class="ft-category-editor-field">
                <span>Name <i>*</i></span>
                @if($readOnly)
                    <div class="ft-category-editor-readonly">{{ $nameValue ?: '—' }}</div>
                @else
                    <input type="text" wire:model.blur="categoryEditorName" maxlength="255" placeholder="Enter category name">
                @endif
                @error('categoryEditorName')<b class="validation-error">{{ $message }}</b>@enderror
            </label>

            <label class="ft-category-editor-field">
                <span>Description</span>
                @if($readOnly)
                    <div class="ft-category-editor-readonly is-description">{{ $descriptionValue ?: '—' }}</div>
                @else
                    <textarea wire:model.blur="categoryEditorDescription" rows="4" maxlength="5000" placeholder="Add a short description"></textarea>
                @endif
                @error('categoryEditorDescription')<b class="validation-error">{{ $message }}</b>@enderror
            </label>

            @if($editing && !$readOnly)
                <label class="ft-category-editor-field">
                    <span>Status</span>
                    <select wire:model="categoryEditorStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </label>
            @endif

            @if(!$readOnly)
                <div class="ft-category-editor-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>
                    Code is generated automatically. Product forms will use this hierarchy immediately after saving.
                </div>
            @endif
        </div>

        <footer class="ft-category-editor-foot">
            <button type="button" class="ft-category-secondary" wire:click="closeCategoryEditor">{{ $readOnly ? 'Close' : 'Cancel' }}</button>
            @if(!$readOnly)
                <button type="button" class="ft-category-primary" wire:click="saveCategoryEditor" wire:loading.attr="disabled" wire:target="saveCategoryEditor">
                    <span wire:loading.remove wire:target="saveCategoryEditor">{{ $editing ? 'Save changes' : 'Create category' }}</span>
                    <span wire:loading wire:target="saveCategoryEditor">Saving…</span>
                </button>
            @endif
        </footer>
    </section>
</div>
