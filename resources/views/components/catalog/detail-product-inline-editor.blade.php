@props([
    'title' => 'Edit product',
    'productName' => '',
    'meta' => null,
    'variant' => 'order',
])

<div {{ $attributes->class(['ft-detail-product-inline-editor', 'ft-detail-product-inline-editor--'.$variant]) }}>
    <div class="ft-detail-product-inline-editor__head">
        <div class="ft-detail-product-inline-editor__title-wrap">
            <span class="ft-detail-product-inline-editor__eyebrow">Editing</span>
            <div>
                <strong>{{ $title }}</strong>
                @if(filled($productName))
                    <span>{{ $productName }}</span>
                @endif
                @if(filled($meta))
                    <small>{{ $meta }}</small>
                @endif
            </div>
        </div>
        @isset($close)
            {{ $close }}
        @endisset
    </div>

    <div class="ft-detail-product-inline-editor__fields">
        {{ $slot }}
    </div>

    @isset($message)
        <div class="ft-detail-product-inline-editor__message">{{ $message }}</div>
    @endisset

    @isset($actions)
        <div class="ft-detail-product-inline-editor__actions">{{ $actions }}</div>
    @endisset
</div>
