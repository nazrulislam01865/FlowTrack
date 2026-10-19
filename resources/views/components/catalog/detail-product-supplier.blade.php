@props([
    'supplier' => null,
    'name' => null,
    'meta' => null,
    'fallback' => 'No default supplier',
])

@php
    $supplierName = $supplier ? $supplier->supplierShortCode() : trim((string) ($name ?? ''));
    $initials = $supplierName !== ''
        ? collect(preg_split('/\s+/', $supplierName) ?: [])
            ->filter()
            ->map(fn ($word) => mb_strtoupper(mb_substr((string) $word, 0, 1)))
            ->take(2)
            ->implode('')
        : '—';
@endphp

<div class="ft-detail-product-supplier {{ $supplierName === '' ? 'is-empty' : '' }}">
    <span class="ft-detail-product-supplier__badge" aria-hidden="true">{{ $initials ?: '—' }}</span>
    <span class="ft-detail-product-supplier__copy">
        <strong>{{ $supplierName !== '' ? $supplierName : $fallback }}</strong>
        @if(filled($meta))
            <span>{{ $meta }}</span>
        @endif
    </span>
</div>
