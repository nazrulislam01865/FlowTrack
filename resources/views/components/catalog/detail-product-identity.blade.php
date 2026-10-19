@props([
    'imageUrl' => null,
    'alt' => '',
    'code' => null,
    'reference' => null,
    'fallbackMeta' => 'Product',
])

<div class="ft-order-product-main-cell">
    <span class="ft-order-product-image">
        @if($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $alt }}">
        @else
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v13H4z"/><path d="m7 15 3.2-3.4 2.4 2.4 2.2-2.3L18 15"/><circle cx="8.5" cy="9" r="1.2"/></svg>
        @endif
    </span>
    <div class="ft-order-product-copy">
        {{ $slot }}
        <small>
            @if($code)
                Product code {{ $code }}
            @endif
            @if($code && $reference)
                ·
            @endif
            @if($reference)
                Ref {{ $reference }}
            @endif
            @if(!$code && !$reference)
                {{ $fallbackMeta }}
            @endif
        </small>
    </div>
</div>
