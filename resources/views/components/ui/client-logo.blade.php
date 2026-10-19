@props([
    'client' => null,
    'name' => null,
    'size' => 34,
    'src' => null,
    'shape' => 'rounded',
    'archived' => false,
])
@php
    $displayName = trim((string) ($client?->name ?? $name ?? 'Client'));
    $imageUrl = $src ?: $client?->logoUrl();
    $initials = \App\Support\BoardPresenter::initials($displayName) ?: 'C';
    $shapeClass = $shape === 'circle' ? 'is-circle' : 'is-rounded';
@endphp
<span {{ $attributes->class(['ft-client-logo-mark', $shapeClass, 'is-archived' => $archived])->merge(['style' => "--ft-client-logo-size:{$size}px"]) }} aria-hidden="true">
    @if($imageUrl)
        <img src="{{ $imageUrl }}" alt="" loading="lazy" decoding="async" data-ft-image-fallback="icon">
        <span class="ft-client-logo-fallback" hidden>{{ $initials }}</span>
    @else
        <span class="ft-client-logo-fallback">{{ $initials }}</span>
    @endif
</span>
