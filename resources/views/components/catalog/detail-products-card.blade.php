@props([
    'count' => 0,
    'totalUnits' => 0,
    'title' => 'Products & quantities',
    'variant' => 'order',
])

@php
    $productCount = max(0, (int) $count);
    $units = (float) $totalUnits;
    $unitDecimals = fmod(abs($units), 1.0) === 0.0 ? 0 : 2;
@endphp

<section {{ $attributes->class(['ft-detail-card', 'ft-order-products-card', 'ft-detail-products-card', 'ft-detail-products-card--'.$variant]) }}>
    <header class="ft-order-products-head">
        <div class="ft-order-products-title">
            <h2>{{ $title }}</h2>
            <p class="ft-order-products-summary">
                {{ $productCount }} {{ \Illuminate\Support\Str::plural('product', $productCount) }} · {{ number_format($units, $unitDecimals) }} total units
            </p>
        </div>
    </header>

    <div class="ft-order-products-table-wrap">
        <table class="ft-order-products-detail-table ft-inline-product-table {{ $variant === 'inquiry' ? 'ft-order-products-detail-table--inquiry' : '' }}">
            <thead>
                <tr>
                    @isset($columns)
                        {{ $columns }}
                    @else
                        <th>Product</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Quantity</th>
                        <th>Unit<br>price</th>
                        <th>Notes</th>
                        <th>Updated</th>
                        <th class="ft-order-product-actions-heading" aria-label="Actions"></th>
                    @endisset
                </tr>
            </thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>

    @isset($afterTable)
        {{ $afterTable }}
    @endisset

    @isset($footer)
        <footer class="ft-order-products-footer">{{ $footer }}</footer>
    @endisset
</section>
