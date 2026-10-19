@props([
    'label',
    'value',
    'icon' => 'supplier',
])

<div {{ $attributes->class('ft-supplier-list-stat') }}>
    <div class="ft-supplier-list-stat-icon" aria-hidden="true">
        @if($icon === 'product')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 8 4.5-8 4.5-8-4.5L12 3Z"/><path d="m4 12 8 4.5 8-4.5M4 16.5l8 4.5 8-4.5"/></svg>
        @elseif($icon === 'clock')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        @elseif($icon === 'attention')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/></svg>
        @else
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
        @endif
    </div>
    <div class="ft-supplier-list-stat-copy">
        <span>{{ $label }}</span>
        <strong>{{ $value }}</strong>
    </div>
</div>
