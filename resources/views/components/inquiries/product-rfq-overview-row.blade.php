@props([
    'row',
    'canEdit' => false,
    'canDelete' => false,
])

@php
    $suppliers = collect($row['suppliers'] ?? []);
    $visibleSuppliers = $suppliers->take(3);
    $supplierCount = (int) ($row['supplier_count'] ?? $suppliers->count());
    $progress = collect($row['progress'] ?? []);
    $quantity = (float) ($row['quantity'] ?? 0);
    $quantityDecimals = fmod(abs($quantity), 1.0) === 0.0 ? 0 : 2;
    $meta = collect([$row['code'] ?? null, $row['category'] ?? null])->filter()->implode(' · ');
    $updatedAt = $row['updated_at'] ?? null;
@endphp

<tr wire:key="inquiry-product-rfq-overview-row-{{ (int) ($row['item_id'] ?? 0) }}" x-data="{ actionOpen: false }">
    <td data-label="Product">
        <div class="ft-inquiry-prq-product">
            <span class="ft-inquiry-prq-product__image">
                @if(filled($row['image_url'] ?? null))
                    <img src="{{ $row['image_url'] }}" alt="{{ $row['name'] ?? 'Product' }}">
                @else
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"></rect><circle cx="9" cy="9" r="1.5"></circle><path d="m6.5 17 4-4 2.7 2.5 1.8-1.8 2.5 3.3"></path></svg>
                @endif
            </span>
            <span class="ft-inquiry-prq-product__copy">
                <strong>{{ $row['name'] ?? 'Product' }}</strong>
                <small>{{ $meta !== '' ? $meta : 'Inquiry product' }}</small>
            </span>
        </div>
    </td>
    <td data-label="Quantity">
        <strong class="ft-inquiry-prq-quantity">{{ number_format($quantity, $quantityDecimals) }} {{ $row['unit'] ?? 'units' }}</strong>
    </td>
    <td data-label="Assigned suppliers">
        @if($supplierCount > 0)
            <div class="ft-inquiry-prq-suppliers">
                @foreach($visibleSuppliers as $supplier)
                    <span class="ft-inquiry-prq-supplier" title="{{ $supplier['name'] ?? 'Supplier' }}">
                        <span class="ft-inquiry-prq-supplier__avatar">{{ $supplier['initials'] ?? '—' }}</span>
                        <span>{{ $supplier['short_code'] ?? ($supplier['name'] ?? 'Supplier') }}</span>
                    </span>
                @endforeach
                <span class="ft-inquiry-prq-supplier-count">{{ $supplierCount }} {{ \Illuminate\Support\Str::plural('supplier', $supplierCount) }}</span>
            </div>
        @else
            <span class="ft-inquiry-prq-empty-value">No suppliers</span>
        @endif
    </td>
    <td data-label="RFQ progress">
        @if($progress->isNotEmpty())
            <div class="ft-inquiry-prq-progress">
                @foreach($progress as $badge)
                    <span class="ft-inquiry-prq-progress__badge is-{{ $badge['tone'] ?? 'neutral' }}">{{ (int) ($badge['count'] ?? 0) }} {{ $badge['label'] ?? '' }}</span>
                @endforeach
            </div>
        @else
            <span class="ft-inquiry-prq-progress__badge is-neutral">Not started</span>
        @endif
    </td>
    <td data-label="Quotations">
        @php($quotes = (int) ($row['quotation_count'] ?? 0))
        <span class="ft-inquiry-prq-quote {{ $quotes > 0 ? 'is-received' : '' }}">{{ $quotes }} received</span>
    </td>
    <td data-label="Updated">
        <span class="ft-inquiry-prq-updated">
            {{ $updatedAt ? (\App\Support\UserLocalTime::localize($updatedAt)?->diffForHumans() ?: '—') : '—' }}
        </span>
    </td>
    <td class="ft-inquiry-prq-actions" data-label="Actions">
        <button type="button" class="ft-inquiry-prq-view" wire:click="setDetailTab('rfq')">View details</button>
        @if($canEdit || $canDelete)
            <x-catalog.detail-product-actions
                :item-id="(int) ($row['item_id'] ?? 0)"
                :can-edit="$canEdit"
                edit-method="openEditInquiryProduct"
                :can-delete="$canDelete"
                remove-method="removeInquiryItem"
                confirm-text="Remove this product from the Inquiry?"
            />
        @else
            <span class="ft-inquiry-prq-kebab-placeholder" aria-hidden="true">⋮</span>
        @endif
    </td>
</tr>
