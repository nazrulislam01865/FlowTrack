@props(['job', 'context' => []])
@php
    $record = $context['displayRecord'] ?? null;
    $hasRedo = (bool) ($context['hasRedo'] ?? false);
    $isDiscountScope = $record?->scope === 'discount';
    $scopeLabel = match ($record?->scope) {
        'production' => 'Production',
        'discount' => 'No redo / adjustment',
        default => 'Artwork and production',
    };
    $reportedBy = trim((string) ($record?->issue_reported_by ?? ''));
    $customerAdjustmentType = (string) ($record?->customer_adjustment_type ?: 'percent');
    $customerAdjustmentValue = (float) ($record?->customer_adjustment_value ?? $record?->customer_discount_percent ?? 0);
    $isMissingQty = $isDiscountScope && $customerAdjustmentType === 'pcs';
    $customerAdjustmentLabel = $customerAdjustmentType === 'pcs'
        ? number_format((int) $customerAdjustmentValue).' pcs missing quantity'
        : rtrim(rtrim(number_format($customerAdjustmentValue, 2), '0'), '.').'% customer adjustment';
@endphp

@if($hasRedo && $record)
    <section class="ft-redo-banner show" aria-label="Redo order notice">
        <div class="ft-redo-banner-icon">↻</div>
        <div>
            @if($isDiscountScope)
                <h3>{{ $isMissingQty ? 'Missing quantity deduction' : 'Customer adjustment' }} recorded for {{ $record->originalOrder?->displayOrderNumber() ?? $job->displayOrderNumber() }}</h3>
                <p>
                    {{ $reportedBy !== '' ? $reportedBy.'-reported issue' : 'Reported issue' }}
                    · {{ $customerAdjustmentLabel }}
                    · {{ number_format((int) $record->affected_quantity) }} units affected
                    · workflow remains unchanged.
                </p>
            @else
                <h3>Redo order created from {{ $record->originalOrder?->displayOrderNumber() ?? $job->displayOrderNumber() }}</h3>
                <p>
                    {{ $reportedBy !== '' ? $reportedBy.'-reported issue' : 'Reported issue' }}
                    · {{ $scopeLabel }} will be repeated
                    · {{ number_format((int) $record->redo_quantity) }} units
                    · financial recovery recorded.
                </p>
            @endif
        </div>
        <button type="button" class="btn small" wire:click="setDetailTab('redo')">View redo details</button>
    </section>
@endif
