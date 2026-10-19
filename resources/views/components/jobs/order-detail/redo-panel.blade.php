@props(['job', 'context' => []])
@php
    $record = $context['displayRecord'] ?? null;
    $records = collect($context['records'] ?? []);
    $isDiscountScope = $record?->scope === 'discount';
    $scopeLabel = match ($record?->scope) {
        'production' => 'Production only',
        'discount' => 'No redo / financial adjustment',
        default => 'Artwork + production',
    };
    $restartLabel = $isDiscountScope
        ? 'No workflow restart'
        : ($record?->redoOrder?->phase?->name
            ?: ($record?->scope === 'production' ? 'Production phase' : 'Artwork phase'));
    $customerAdjustmentType = (string) ($record?->customer_adjustment_type ?: 'percent');
    $customerAdjustmentValue = (float) ($record?->customer_adjustment_value ?? $record?->customer_discount_percent ?? 0);
    $supplierAdjustmentType = (string) ($record?->supplier_adjustment_type ?: 'percent');
    $supplierAdjustmentValue = (float) ($record?->supplier_adjustment_value ?? $record?->supplier_redo_charge_percent ?? 0);
    $isMissingQty = $isDiscountScope && $customerAdjustmentType === 'pcs';
    $customerAdjustmentLabel = $customerAdjustmentType === 'pcs'
        ? number_format((int) $customerAdjustmentValue).' pcs missing quantity'
        : rtrim(rtrim(number_format($customerAdjustmentValue, 2), '0'), '.').'% customer adjustment';
    $supplierAdjustmentLabel = $supplierAdjustmentType === 'pcs'
        ? number_format((int) $supplierAdjustmentValue).' pcs'
        : rtrim(rtrim(number_format($supplierAdjustmentValue, 2), '0'), '.').'%';
    $resolution = $record?->customer_resolution === 'discount'
        ? $customerAdjustmentLabel
        : 'Free redo for customer';
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
    <section class="ft-redo-panel show">
        <div class="ft-redo-grid">
            <article class="ft-redo-card">
                <header class="ft-redo-cardhead">
                    <h2>{{ $isDiscountScope ? ($isMissingQty ? 'Missing quantity adjustment' : 'Customer adjustment relationship') : 'Redo order relationship' }}</h2>
                    <span class="pill redo">{{ $isDiscountScope ? ($isMissingQty ? 'Missing qty' : 'Adjustment') : '↻ Redo order' }}</span>
                </header>
                <div class="ft-redo-cardbody">
                    <div class="ft-redo-relation">
                        <button
                            type="button"
                            class="ft-redo-order-chip"
                            wire:click="openLinkedRedoOrder({{ (int) $record->original_order_id }})"
                            title="Open original Order"
                        >
                            <small>Original order</small>
                            <b>{{ $record->originalOrder?->displayOrderNumber() ?? '—' }}</b>
                            <span>{{ number_format((int) ($record->originalOrder?->quantity ?? 0)) }} pcs · source Order remains intact</span>
                        </button>

                        <div class="ft-redo-relation-arrow">→</div>

                        @if($isDiscountScope)
                            <div class="ft-redo-order-chip current ft-redo-order-chip-static">
                                <small>Resolution</small>
                                <b>{{ $isMissingQty ? 'Missing quantity' : 'Customer adjustment' }}</b>
                                <span>{{ $customerAdjustmentLabel }} · {{ number_format((int) $record->affected_quantity) }} affected units</span>
                            </div>
                        @else
                            <button
                                type="button"
                                class="ft-redo-order-chip current"
                                wire:click="openLinkedRedoOrder({{ (int) $record->redo_order_id }})"
                                title="Open redo Order"
                            >
                                <small>Redo order</small>
                                <b>{{ $record->redoOrder?->displayOrderNumber() ?? '—' }}</b>
                                <span>{{ $scopeLabel }} · {{ number_format((int) $record->redo_quantity) }} units</span>
                            </button>
                        @endif
                    </div>

                    <div class="ft-redo-info-row"><span>Issue source</span><b>{{ $record->issue_reported_by }}</b></div>
                    <div class="ft-redo-info-row"><span>Issue category</span><b>{{ $record->issue_category }}</b></div>
                    @if(filled($record->issue_description))
                        <div class="ft-redo-info-row">
                            <span>Issue reason</span>
                            <div class="ft-rich-text-content"><x-ui.mention-text :text="$record->issue_description" /></div>
                        </div>
                    @endif
                    <div class="ft-redo-info-row"><span>Resolution</span><b>{{ $resolution }}</b></div>
                    <div class="ft-redo-info-row"><span>Workflow restart</span><b>{{ $restartLabel }}</b></div>
                    <div class="ft-redo-info-row"><span>Supplier</span><b>{{ $record->supplier?->name ?: 'Supplier not decided' }}</b></div>

                    @if($records->count() > 1)
                        <div class="ft-redo-history-note">
                            This original Order has {{ $records->count() }} redo/adjustment records. The newest record is shown here.
                        </div>
                    @endif
                </div>
            </article>

            <div class="ft-redo-stack">
                <article class="ft-redo-card">
                    <header class="ft-redo-cardhead"><h2>{{ $isDiscountScope ? ($isMissingQty ? 'Missing quantity financial impact' : 'Customer adjustment financial impact') : 'Redo financial impact' }}</h2></header>
                    <div class="ft-redo-cardbody">
                        <table class="ft-redo-fin-table">
                            <tr><td>Affected value</td><td>{{ $money($record->affected_order_value) }}</td></tr>
                            <tr>
                                <td>Customer · {{ $customerAdjustmentLabel }}</td>
                                <td>{{ $record->customer_resolution === 'discount' ? '-'.$money($record->customer_impact) : $money(0) }}</td>
                            </tr>
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
                        <p class="ft-redo-footnote">
                            {{ $isDiscountScope
                                ? ($isMissingQty
                                    ? 'The missing quantity value is deducted from the order total for this financial adjustment. No replacement Order or workflow restart was created.'
                                    : 'The customer adjustment is recorded against the original Order. No replacement Order or workflow restart was created.')
                                : 'All amounts are recorded as financial adjustments against the redo Order; the original invoice remains unchanged.' }}
                        </p>
                    </div>
                </article>

                <article class="ft-redo-card">
                    <header class="ft-redo-cardhead"><h2>Redo audit trail</h2></header>
                    <div class="ft-redo-cardbody ft-redo-audit">
                        <div class="ft-redo-event">
                            <i></i>
                            <div>
                                <b>Issue reported</b>
                                <small>{{ number_format((int) $record->affected_quantity) }} units · {{ $record->issue_reported_by }} · {{ $record->reported_date?->format('M j, Y') }}</small>
                            </div>
                        </div>
                        <div class="ft-redo-event">
                            <i></i>
                            <div>
                                <b>{{ $isDiscountScope ? ($isMissingQty ? 'Missing quantity approved' : 'Adjustment approved') : 'Redo approved' }}</b>
                                <small>{{ $scopeLabel }} · {{ $record->creator?->name ?: 'FlowTrack user' }}</small>
                            </div>
                        </div>
                        <div class="ft-redo-event">
                            <i></i>
                            <div>
                                @if($isDiscountScope)
                                    <b>{{ $isMissingQty ? 'Missing quantity deduction recorded' : 'Customer adjustment recorded' }}</b>
                                    <small>No redo Order created · original workflow remained unchanged</small>
                                @else
                                    <b>Redo order created</b>
                                    <small>{{ $record->redoOrder?->displayOrderNumber() ?? 'Linked Order' }} · linked to original order automatically</small>
                                @endif
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>
@endif
