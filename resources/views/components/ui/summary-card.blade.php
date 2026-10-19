@props([
    'label',
    'value' => 0,
    'icon' => 'created',
    'tone' => 'teal',
    'caption' => '',
    'active' => false,
    'displayValue' => null,
    'valueExpression' => null,
])
@php
    $icons = [
        'created' => '<path d="M12 3v6m-3-3h6"/><path d="M5 3h3m8 0h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2"/>',
        'not-started' => '<circle cx="12" cy="12" r="9"/><path d="M9 9v6m6-6v6"/>',
        'in-progress' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 4 4-4 4m-3-4h7"/>',
        'due-week' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'completed' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'attention' => '<path d="M5 4v16m0-14h10l-1.5 3L15 12H5"/><path d="M19 6v4"/>',
        'clients' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'orders' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 11h18M10 11v2h4v-2"/>',
        'money' => '<circle cx="12" cy="12" r="9"/><path d="M16 8.5c-.8-.9-2-1.5-3.5-1.5-1.9 0-3.5 1-3.5 2.5s1.4 2.1 3.4 2.5c2 .4 3.6 1 3.6 2.5S14.4 17 12.5 17C11 17 9.6 16.4 8.7 15.4M12 5v14"/>',
    ];
@endphp

<button
    type="button"
    {{ $attributes->class(['metric-filter-card', 'ft-summary-card', 'tone-'.$tone, 'active' => $active, 'is-active' => $active]) }}
>
    <span class="ft-summary-card-label">{{ $label }}</span>
    <i class="ft-summary-card-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24">{!! $icons[$icon] ?? $icons['created'] !!}</svg>
    </i>
    @if($valueExpression)
        <strong class="ft-summary-card-value" x-text="{{ $valueExpression }}">{{ $displayValue !== null ? $displayValue : number_format((int) $value) }}</strong>
    @else
        <strong class="ft-summary-card-value">{{ $displayValue !== null ? $displayValue : number_format((int) $value) }}</strong>
    @endif
    <small class="ft-summary-card-caption">{{ $caption }}</small>
</button>
