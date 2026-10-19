@props([
    'count' => 0,
    'matchingTotal' => 0,
    'allMatchingSelected' => false,
    'canEdit' => false,
    'canDelete' => false,
])

<div class="ft-product-bulk-bar" x-data="{ statusOpen: false, moreOpen: false }">
    <div class="ft-product-bulk-summary">
        <strong>{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('product', $count) }} selected</strong>
        @if(!$allMatchingSelected && $matchingTotal > $count)
            <button type="button" wire:click="selectAllFilteredProducts">Select all {{ number_format($matchingTotal) }} products</button>
        @elseif($allMatchingSelected)
            <span>All matching products selected</span>
        @endif
    </div>

    <div class="ft-product-bulk-actions">
        @if($canEdit)
            <div class="ft-product-bulk-menu-wrap">
                <button type="button" class="ft-product-bulk-btn" x-on:click="statusOpen = !statusOpen; moreOpen = false" :aria-expanded="statusOpen.toString()">
                    Set status
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                </button>
                <div class="ft-product-bulk-menu" x-cloak x-show="statusOpen" x-on:click.outside="statusOpen=false">
                    <button type="button" wire:click="bulkSetProductStatus('active')" x-on:click="statusOpen=false"><span class="ft-bulk-dot is-active"></span>Active</button>
                    <button type="button" wire:click="bulkSetProductStatus('inactive')" x-on:click="statusOpen=false"><span class="ft-bulk-dot is-inactive"></span>Inactive</button>
                </div>
            </div>
            <button type="button" class="ft-product-bulk-btn is-bulk-secondary is-supplier" wire:click="openProductSupplierAssignment">Assign supplier</button>
            <button type="button" class="ft-product-bulk-btn is-bulk-secondary" wire:click="openProductBulkPanel('clients')">Assign clients</button>
            <button type="button" class="ft-product-bulk-btn is-bulk-secondary" wire:click="openProductBulkPanel('category')">Change category</button>
        @endif

        <button type="button" class="ft-product-bulk-btn is-bulk-secondary" wire:click="exportSelectedProducts">Export selected Excel</button>

        <div class="ft-product-bulk-menu-wrap">
            <button type="button" class="ft-product-bulk-btn" x-on:click="moreOpen = !moreOpen; statusOpen = false" :aria-expanded="moreOpen.toString()">
                More
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
            </button>
            <div class="ft-product-bulk-menu ft-product-bulk-more-menu" x-cloak x-show="moreOpen" x-on:click.outside="moreOpen=false">
                @if($canEdit)
                    <button type="button" class="ft-product-bulk-mobile-only" wire:click="openProductSupplierAssignment" x-on:click="moreOpen=false">Assign supplier</button>
                    <button type="button" class="ft-product-bulk-mobile-only" wire:click="openProductBulkPanel('clients')" x-on:click="moreOpen=false">Assign clients</button>
                    <button type="button" class="ft-product-bulk-mobile-only" wire:click="openProductBulkPanel('category')" x-on:click="moreOpen=false">Change category</button>
                @endif
                <button type="button" class="ft-product-bulk-mobile-only" wire:click="exportSelectedProducts" x-on:click="moreOpen=false">Export</button>
                @if($canDelete)
                    <button type="button" class="is-danger" wire:click="bulkDeleteProducts" wire:confirm="Delete the selected products? This cannot be undone." x-on:click="moreOpen=false">Delete products</button>
                @endif
            </div>
        </div>

        <button type="button" class="ft-product-bulk-clear" wire:click="clearProductSelection">× Clear selection</button>
    </div>
</div>
