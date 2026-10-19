@props(['product', 'size' => 'md'])
<span class="ft-rfq-product-thumb is-{{ $size }}">
    @if(filled($product['image_url'] ?? null))
        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] ?? 'Product' }}">
    @else
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"></rect><circle cx="9" cy="9" r="1.4"></circle><path d="m6.5 17 4-4 2.8 2.5 1.7-1.7 2.5 3.2"></path></svg>
    @endif
</span>
