@props([
    'phase' => null,
    'short' => false,
    'fallback' => '—',
])
@php
    $label = $fallback;
    $fullLabel = $fallback;
    $color = null;
    if ($phase) {
        $fullLabel = trim((string) ($phase->name ?? '')) ?: trim((string) ($phase->short_name ?? '')) ?: $fallback;
        $label = $short
            ? (trim((string) ($phase->short_name ?? '')) ?: $fullLabel)
            : $fullLabel;
        $color = $phase->color ?? null;
    }
@endphp
<span {{ $attributes->class(['ft-phase-color-label', 'ft-badge--dynamic'])->merge(['style' => \App\Support\MasterColor::style($color), 'title' => $fullLabel]) }}>{{ $label }}</span>
