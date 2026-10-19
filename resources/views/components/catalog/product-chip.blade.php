@props(['label'])
<span {{ $attributes->class(['ft-product-page-chip']) }}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
    <span>{{ $label }}</span>
</span>
