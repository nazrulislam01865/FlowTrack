@props(['orderId', 'generated' => false, 'select' => false])
<div {{ $attributes->class(['ft-po-row-action', 'is-generated' => $generated]) }}>
    @if($select)
        <x-ui.button :variant="$generated ? 'secondary' : 'primary'" size="sm"
            class="ft-po-row-action-button"
            wire:click="selectOrder({{ (int) $orderId }})"
            wire:loading.attr="disabled" wire:target="selectOrder({{ (int) $orderId }})"
            :aria-label="($generated ? 'View purchase orders for order ' : 'Generate purchase order for order ').$orderId">
            @if($generated)
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8l4 4v14H4V3h4z"/><path d="M16 3v5h4M8 13h8m-8 4h5"/></svg>
            @else
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8m-4-4v8"/></svg>
            @endif
            {{ $generated ? 'View POs' : 'Generate PO' }}
        </x-ui.button>
    @else
        <x-ui.button :variant="$generated ? 'secondary' : 'primary'" size="sm"
            class="ft-po-row-action-button"
            :href="route('orders.purchase-orders', ['order' => $orderId])"
            wire:navigate x-on:click.stop>
            @if($generated)
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8l4 4v14H4V3h4z"/><path d="M16 3v5h4M8 13h8m-8 4h5"/></svg>
            @else
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8m-4-4v8"/></svg>
            @endif
            {{ $generated ? 'View POs' : 'Generate PO' }}
        </x-ui.button>
    @endif
</div>
