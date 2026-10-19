@props(['job', 'context' => []])
@php
    $record = $context['displayRecord'] ?? null;
    $isDiscountScope = $record?->scope === 'discount';
    $customerAdjustmentType = (string) ($record?->customer_adjustment_type ?: 'percent');
    $customerAdjustmentValue = (float) ($record?->customer_adjustment_value ?? $record?->customer_discount_percent ?? 0);
    $supplierAdjustmentType = (string) ($record?->supplier_adjustment_type ?: 'percent');
    $supplierAdjustmentValue = (float) ($record?->supplier_adjustment_value ?? $record?->supplier_redo_charge_percent ?? 0);
    $isMissingQty = $isDiscountScope && $customerAdjustmentType === 'pcs';
    $customerAdjustmentLabel = $customerAdjustmentType === 'pcs'
        ? number_format((int) $customerAdjustmentValue).' pcs missing quantity'
        : rtrim(rtrim(number_format($customerAdjustmentValue, 2), '0'), '.').'%';
    $supplierAdjustmentLabel = $supplierAdjustmentType === 'pcs'
        ? number_format((int) $supplierAdjustmentValue).' pcs'
        : rtrim(rtrim(number_format($supplierAdjustmentValue, 2), '0'), '.').'%';
    $currency = (string) ($record?->originalOrder?->currency ?: $job->currency ?: 'USD');
    $money = fn ($value) => ($currency === 'USD' ? '$' : $currency.' ').number_format((float) $value, 2);
    $originalOrderValue = max(0, (float) ($record?->order_value_before_adjustment ?? 0));
    if ($originalOrderValue <= 0) {
        $originalOrderValue = max(0, (float) ($record?->originalOrder?->commercial_value ?? 0));
    }
    $supplierFreight = max(0, round((float) ($record?->freight_amount ?? 0) - (float) ($record?->courier_freight_amount ?? 0), 2));
    $courierFreight = max(0, (float) ($record?->courier_freight_amount ?? 0));
    $adjustedOrderValue = max(0, (float) ($record?->order_value_after_adjustment ?? 0));
    if ($adjustedOrderValue <= 0 && $originalOrderValue > 0) {
        $adjustedOrderValue = max(0, $originalOrderValue - (float) ($record?->customer_impact ?? 0));
    }
@endphp

@if($record)
    <section class="ft-redo-finance-review">
        <div class="ft-redo-card">
            <header class="ft-redo-cardhead">
                <div>
                    <h2>{{ $isDiscountScope ? ($isMissingQty ? 'Missing quantity adjustment' : 'Customer financial adjustment') : 'Redo financial adjustment' }}</h2>
                    <small>
                        {{ $isDiscountScope
                            ? (($record->originalOrder?->displayOrderNumber() ?? $job->displayOrderNumber()).' · no redo Order created · workflow unchanged')
                            : (($record->redoOrder?->displayOrderNumber() ?? 'Redo order').' · original invoice and payments unchanged') }}
                    </small>
                </div>
                <button type="button" class="btn small" wire:click="setDetailTab('redo')">View redo details</button>
            </header>
            <div class="ft-redo-cardbody">
                <table class="ft-redo-fin-table">
                    <tr><td>Affected order value</td><td>{{ $money($record->affected_order_value) }}</td></tr>
                    <tr><td>Customer · {{ $customerAdjustmentLabel }}</td><td>{{ $record->customer_resolution === 'discount' ? '-'.$money($record->customer_impact) : $money(0) }}</td></tr>
                    @if($isDiscountScope && $record->customer_resolution === 'discount')
                        <tr><td>Order total after deduction</td><td>{{ $money($adjustedOrderValue) }}</td></tr>
                    @endif
                    <tr><td>Supplier · {{ $supplierAdjustmentLabel }}</td><td>{{ $money($record->supplier_redo_charge) }}</td></tr>
                    <tr><td>Supplier freight deduction</td><td>{{ $supplierFreight > 0 ? $money($supplierFreight) : 'Not applicable' }}</td></tr>
                    <tr><td>Courier company freight deduction</td><td>{{ $courierFreight > 0 ? $money($courierFreight) : 'Not applicable' }}</td></tr>
                    <tr class="total"><td>Total supplier recovery</td><td>{{ $money($record->total_supplier_recovery) }}</td></tr>
                    @if($courierFreight > 0)
                        <tr class="total"><td>Total courier recovery</td><td>{{ $money($courierFreight) }}</td></tr>
                    @endif
                </table>
            </div>
        </div>
    </section>
@endif
