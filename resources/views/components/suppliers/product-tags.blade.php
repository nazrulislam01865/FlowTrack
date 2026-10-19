@props(['products' => collect()])

@php
    $products = collect($products);
    $visible = $products->take(3);
    $remaining = max(0, $products->count() - $visible->count());
@endphp

<div class="ft-supplier-list-product-tags">
    @forelse($visible as $product)
        <span class="ft-supplier-list-tag">{{ $product->productDisplayCode() }}</span>
    @empty
        <span class="ft-supplier-list-empty-products">No products assigned</span>
    @endforelse
    @if($remaining > 0)
        <span class="ft-supplier-list-tag is-more">+{{ $remaining }} more</span>
    @endif
</div>
