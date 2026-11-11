@props(['items' => [], 'payloadKey' => 'supplier_po_items', 'dateKey' => 'supplier_po_delivery_date', 'dateValue' => '', 'orderDate' => null])
<section class="ft-po-items-edit" aria-label="Editable purchase order products">
    <div class="ft-po-items-edit-head">
        <strong>Purchase Order details</strong>
        <small>Only these PO values change. The original Order stays unchanged.</small>
    </div>
    <div class="ft-po-items-edit-scroll">
        <table class="ft-po-items-edit-table">
            <thead><tr><th>Product / description</th><th>Model / SKU</th><th>Quantity</th><th>Supplier unit price</th></tr></thead>
            <tbody>
            @foreach($items as $item)
                @php $id = (int) ($item['item_id'] ?? $item['id']); @endphp
                <tr wire:key="ft-po-edit-{{ $payloadKey }}-{{ $id }}">
                    <td><input type="text" maxlength="255" required wire:model="orderWorkflowActionPayload.{{ $payloadKey }}.{{ $id }}.name" aria-label="Product name {{ $loop->iteration }}" value="{{ $item['name'] }}" />@error('orderWorkflowActionPayload.'.$payloadKey.'.'.$id.'.name')<small class="validation-error">{{ $message }}</small>@enderror</td>
                    <td><input type="text" maxlength="100" wire:model="orderWorkflowActionPayload.{{ $payloadKey }}.{{ $id }}.sku" aria-label="Model or SKU {{ $loop->iteration }}" value="{{ $item['sku'] ?? '' }}" /></td>
                    <td><input type="number" inputmode="numeric" min="1" max="999999999" step="1" required wire:model="orderWorkflowActionPayload.{{ $payloadKey }}.{{ $id }}.quantity" aria-label="Quantity {{ $loop->iteration }}" value="{{ $item['quantity'] }}" />@error('orderWorkflowActionPayload.'.$payloadKey.'.'.$id.'.quantity')<small class="validation-error">{{ $message }}</small>@enderror</td>
                    <td><input type="number" inputmode="decimal" min="0" max="999999999.99" step="0.01" wire:model="orderWorkflowActionPayload.{{ $payloadKey }}.{{ $id }}.supplier_unit_cost" aria-label="Supplier unit price {{ $loop->iteration }}" value="{{ $item['supplier_unit_cost'] ?? '' }}" placeholder="Not set" />@error('orderWorkflowActionPayload.'.$payloadKey.'.'.$id.'.supplier_unit_cost')<small class="validation-error">{{ $message }}</small>@enderror</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <label class="ft-po-edit-date">
        <span>Purchase Order delivery date <small>(custom, independent of Order)</small></span>
        <input type="date" wire:model="orderWorkflowActionPayload.{{ $dateKey }}" value="{{ $dateValue }}" @if($orderDate) max="{{ $orderDate }}" @endif />
        @if($orderDate)<small>On or before the Order delivery date: {{ $orderDate }}.</small>@endif
        @error('orderWorkflowActionPayload.'.$dateKey)<small class="validation-error">{{ $message }}</small>@enderror
    </label>
</section>
