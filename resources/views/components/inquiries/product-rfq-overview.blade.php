@props([
    'overview' => [],
    'canAdd' => false,
    'showAddForm' => false,
])

@php
    $stats = $overview['stats'] ?? [];
    $productCount = (int) ($overview['product_count'] ?? 0);
    $totalUnits = (float) ($overview['total_units'] ?? 0);
    $unitDecimals = fmod(abs($totalUnits), 1.0) === 0.0 ? 0 : 2;
@endphp

<section {{ $attributes->class(['ft-inquiry-product-rfq-overview']) }} aria-labelledby="inquiry-product-rfq-overview-title">
    <div class="ft-inquiry-prq-stats" aria-label="Product and RFQ summary">
        <x-inquiries.product-rfq-stat label="products" :value="(int) ($stats['products'] ?? 0)" icon="product" />
        <x-inquiries.product-rfq-stat label="supplier assignments" :value="(int) ($stats['supplier_assignments'] ?? 0)" icon="suppliers" />
        <x-inquiries.product-rfq-stat label="invitations sent" :value="(int) ($stats['invitations_sent'] ?? 0)" icon="sent" />
        <x-inquiries.product-rfq-stat label="quotations received" :value="(int) ($stats['quotations_received'] ?? 0)" icon="quotes" />
    </div>

    <section class="ft-inquiry-prq-card">
        <header class="ft-inquiry-prq-card__head">
            <div>
                <h2 id="inquiry-product-rfq-overview-title">Products, suppliers &amp; RFQ progress</h2>
                <p>Suppliers and quotation activity are tracked separately for each product.</p>
            </div>
            <div class="ft-inquiry-prq-card__head-actions">
                @if($canAdd && ! $showAddForm)
                    <button type="button" class="ft-inquiry-prq-add" wire:click="openAddInquiryProductForm" wire:loading.attr="disabled" wire:target="openAddInquiryProductForm">
                        <span aria-hidden="true">＋</span> Add product
                    </button>
                @endif
                <span>{{ $productCount }} {{ \Illuminate\Support\Str::plural('product', $productCount) }} · {{ number_format($totalUnits, $unitDecimals) }} total units</span>
            </div>
        </header>

        <div class="ft-inquiry-prq-table-wrap">
            <table class="ft-inquiry-prq-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Assigned suppliers</th>
                        <th>RFQ progress</th>
                        <th>Quotations</th>
                        <th>Updated</th>
                        <th aria-label="Actions"></th>
                    </tr>
                </thead>
                <tbody>{{ $slot }}</tbody>
            </table>
        </div>

        @isset($afterTable)
            <div class="ft-inquiry-prq-after-table">{{ $afterTable }}</div>
        @endisset

        <footer class="ft-inquiry-prq-note">
            <span class="ft-inquiry-prq-note__icon" aria-hidden="true">i</span>
            <span>Supplier assignments, invitation delivery and quotation responses are shown against the products they cover.</span>
        </footer>
    </section>
</section>
