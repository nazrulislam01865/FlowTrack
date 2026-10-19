@props([
    'count' => 0,
])

<div
    class="ft-order-delete-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ft-order-delete-title"
    aria-describedby="ft-order-delete-description"
    wire:key="order-bulk-delete-confirmation"
    x-data
    x-on:keydown.escape.window="$wire.closeBulkDeleteConfirmation()"
>
    <button
        type="button"
        class="ft-order-delete-backdrop"
        wire:click="closeBulkDeleteConfirmation"
        aria-label="Cancel bulk order deletion"
    ></button>

    <section class="ft-order-delete-card">
        <button
            type="button"
            class="ft-order-delete-close"
            wire:click="closeBulkDeleteConfirmation"
            wire:loading.attr="disabled"
            wire:target="bulkDeleteOrders"
            aria-label="Close confirmation"
        >×</button>

        <div class="ft-order-delete-body">
            <div class="ft-order-delete-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
            </div>

            <h2 id="ft-order-delete-title" class="ft-order-delete-title">Delete selected orders?</h2>
            <p id="ft-order-delete-description" class="ft-order-delete-copy">
                Please confirm before deleting the selected orders from FlowTrack.
            </p>

            <div class="ft-order-delete-count">
                <strong>{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('order', $count) }}</strong>
                <span>selected for deletion</span>
            </div>

            <p class="ft-order-delete-warning">This action cannot be undone. Cancel if you want to review the selection first.</p>
        </div>

        <footer class="ft-order-delete-actions">
            <button
                type="button"
                class="ft-order-delete-cancel"
                wire:click="closeBulkDeleteConfirmation"
                wire:loading.attr="disabled"
                wire:target="bulkDeleteOrders"
            >Cancel</button>
            <button
                type="button"
                class="ft-order-delete-confirm"
                wire:click="bulkDeleteOrders"
                wire:loading.attr="disabled"
                wire:target="bulkDeleteOrders"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                <span wire:loading.remove wire:target="bulkDeleteOrders">Delete {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('order', $count) }}</span>
                <span wire:loading wire:target="bulkDeleteOrders">Deleting...</span>
            </button>
        </footer>
    </section>
</div>
