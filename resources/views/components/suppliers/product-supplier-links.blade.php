@props(['suppliers' => collect(), 'defaultSupplierId' => null])
@php
    $rows = collect($suppliers)->values();
    $defaultId = (int) ($defaultSupplierId ?? 0);
    $default = $defaultId > 0 ? $rows->firstWhere('id', $defaultId) : null;
    $primary = $default ?: $rows->first();
    $extra = max(0, $rows->count() - ($primary ? 1 : 0));
@endphp
<div class="ft-product-supplier-links">
    @if($primary)
        <span class="ft-product-supplier-primary" title="{{ $primary->name }}{{ $default && (int)$primary->id === $defaultId ? ' · Default supplier' : '' }}">
            <b>{{ $primary->supplierShortCode() }}</b>
            @if($default && (int)$primary->id === $defaultId)<small>Default</small>@endif
        </span>
        @if($extra > 0)<span class="ft-product-supplier-more">+{{ $extra }} more</span>@endif
    @else
        <span class="ft-product-supplier-none">Supplier needed</span>
    @endif
</div>
