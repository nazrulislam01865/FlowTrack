@props(['labels' => []])
@php
    $labels = collect($labels)->filter(fn ($label) => trim((string) $label) !== '')->values();
@endphp
<div class="ft-product-availability" aria-label="Client availability">
    @if($labels->isEmpty() || $labels->contains(fn ($label) => strtolower(trim((string) $label)) === 'all clients'))
        <span class="ft-product-availability-all">All clients</span>
    @else
        @foreach($labels->take(2) as $label)
            <span>{{ $label }}</span>
        @endforeach
        @if($labels->count() > 2)
            <span>+{{ $labels->count() - 2 }}</span>
        @endif
    @endif
</div>
