@props([
    'count' => 0,
])

<div class="ft-order-bulk-bar" role="region" aria-label="Bulk order actions">
    <div class="ft-order-bulk-summary">
        <strong>{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('order', $count) }} selected</strong>
        <span>Select more rows or delete the selected orders together.</span>
    </div>
    <div class="ft-order-bulk-actions">
        <button
            type="button"
            class="ft-order-bulk-clear"
            wire:click="clearOrderSelection"
            wire:loading.attr="disabled"
            wire:target="clearOrderSelection,openBulkDeleteConfirmation,bulkDeleteOrders"
        >Clear selection</button>
        <button
            type="button"
            class="ft-order-bulk-delete"
            wire:click="openBulkDeleteConfirmation"
            wire:loading.attr="disabled"
            wire:target="openBulkDeleteConfirmation"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
            <span wire:loading.remove wire:target="openBulkDeleteConfirmation">Delete selected</span>
            <span wire:loading wire:target="openBulkDeleteConfirmation">Opening...</span>
        </button>
    </div>
</div>
