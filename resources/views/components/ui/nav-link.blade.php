@props([
    'route',
    'label',
    'icon',
    'badge' => null,
    'params' => [],
    'child' => false,
    'active' => null,
])
@php
    $isActive = is_bool($active) ? $active : request()->routeIs($route);
    if (!is_bool($active)) {
        if ($route === 'task-pack.setup') $isActive = $isActive || request()->routeIs('task-pack.*');
        if ($route === 'jobs.index') $isActive = $isActive || request()->routeIs('orders.bulk-import*');
    }
@endphp
<a href="{{ route($route, $params) }}" wire:navigate data-ft-nav-route="{{ $route }}" class="nav-btn {{ $child ? 'ft-sidebar-child-link' : '' }} {{ $isActive ? 'active' : '' }}" @if($isActive) aria-current="page" @endif>
    @switch($icon)
        @case('dashboard')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>@break
        @case('work')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>@break
        @case('jobs')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3"/><path d="M4 12h16"/></svg>@break
        @case('inquiries')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 1 1 3.8 1.95c-.95.66-1.6 1.18-1.6 2.55"/><path d="M12 17h.01"/></svg>@break
        @case('clients')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2"/></svg>@break
        @case('products')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>@break
        @case('categories')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h7v6H4zM13 5h7v6h-7zM4 13h7v6H4zM13 13h7v6h-7z"/></svg>@break
        @case('suppliers')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>@break
        @case('board')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="5" height="16"/><rect x="10" y="4" width="5" height="10"/><rect x="17" y="4" width="4" height="13"/></svg>@break
        @case('documents')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>@break
        @case('reports')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>@break
        @case('notifications')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>@break
        @case('workflow')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5h5v5H5zM14 5h5v5h-5zM14 14h5v5h-5z"/><path d="M10 7.5h4M16.5 10v4M5 16.5h9"/></svg>@break
        @case('master')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16v4H4zM4 11h16v4H4zM4 17h16v3H4z"/></svg>@break
        @case('dot')<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="2.1" fill="currentColor" stroke="none"/></svg>@break
        @case('plus')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8"/><path d="M12 8v8M8 12h8"/></svg>@break
        @case('upload')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M5 13v6h14v-6"/></svg>@break
        @case('cancelled')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="m9 9 6 6M15 9l-6 6"/></svg>@break
        @default<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/></svg>
    @endswitch
    <span>{{ $label }}</span>
    @if($badge !== null)<span class="nav-badge">{{ $badge }}</span>@endif
</a>
