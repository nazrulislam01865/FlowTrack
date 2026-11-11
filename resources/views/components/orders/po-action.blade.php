@props(['orderId', 'generated' => false, 'select' => false])
{{-- A PO is created only by the optional Artwork task, not from an order row. --}}
@if(config('flowtrack.supplier_po_creation_enabled', false) && $generated)
<div {{ $attributes->class(['ft-po-row-action', 'is-generated']) }}>
    @if($select)
        <x-ui.button variant="secondary" size="sm"
            class="ft-po-row-action-button"
            wire:click="selectOrder({{ (int) $orderId }})"
            wire:loading.attr="disabled" wire:target="selectOrder({{ (int) $orderId }})"
            :aria-label="'View purchase orders for order '.$orderId">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8l4 4v14H4V3h4z"/><path d="M16 3v5h4M8 13h8m-8 4h5"/></svg>
            View POs
        </x-ui.button>
    @else
        <x-ui.button variant="secondary" size="sm"
            class="ft-po-row-action-button"
            :href="route('orders.purchase-orders', ['order' => $orderId])"
            wire:navigate x-on:click.stop>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8l4 4v14H4V3h4z"/><path d="M16 3v5h4M8 13h8m-8 4h5"/></svg>
            View POs
        </x-ui.button>
    @endif
</div>
@else
    <span class="ft-po-row-action-empty" aria-label="No purchase order generated">—</span>
@endif
