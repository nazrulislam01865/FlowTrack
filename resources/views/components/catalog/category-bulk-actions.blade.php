@props([
    'count' => 0,
    'canEdit' => false,
    'canDelete' => false,
])

<div class="ft-product-bulk-bar ft-category-bulk-bar" x-data="{ statusOpen: false, moreOpen: false }">
    <div class="ft-product-bulk-summary">
        <strong>{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('category', $count) }} selected</strong>
        <span>Selection is kept while you move between category pages.</span>
    </div>

    <div class="ft-product-bulk-actions">
        @if($canEdit)
            <div class="ft-product-bulk-menu-wrap">
                <button type="button" class="ft-product-bulk-btn" x-on:click="statusOpen = !statusOpen; moreOpen = false" :aria-expanded="statusOpen.toString()">
                    Set status
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                </button>
                <div class="ft-product-bulk-menu" x-cloak x-show="statusOpen" x-on:click.outside="statusOpen=false">
                    <button type="button" wire:click="bulkSetCategoryStatus('active')" x-on:click="statusOpen=false"><span class="ft-bulk-dot is-active"></span>Active</button>
                    <button type="button" wire:click="bulkSetCategoryStatus('inactive')" x-on:click="statusOpen=false"><span class="ft-bulk-dot is-inactive"></span>Inactive</button>
                </div>
            </div>
        @endif

        <button type="button" class="ft-product-bulk-btn is-bulk-secondary" wire:click="exportSelectedCategories">Export</button>

        @if($canDelete)
            <div class="ft-product-bulk-menu-wrap">
                <button type="button" class="ft-product-bulk-btn" x-on:click="moreOpen = !moreOpen; statusOpen = false" :aria-expanded="moreOpen.toString()">
                    More
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                </button>
                <div class="ft-product-bulk-menu ft-product-bulk-more-menu" x-cloak x-show="moreOpen" x-on:click.outside="moreOpen=false">
                    <button type="button" class="ft-product-bulk-mobile-only" wire:click="exportSelectedCategories" x-on:click="moreOpen=false">Export</button>
                    <button type="button" class="is-danger" wire:click="bulkDeleteCategories" x-on:click="moreOpen=false">Delete permanently</button>
                </div>
            </div>
        @endif

        <button type="button" class="ft-product-bulk-clear" wire:click="clearCategorySelection">× Clear selection</button>
    </div>
</div>
