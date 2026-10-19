@props(['name', 'size' => 22])
@switch($name)
    @case('alert')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" fill="currentColor" opacity=".14"/><path d="M12 7.3v6.1M12 16.8h.01" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
        </svg>
        @break
    @case('eye')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M2.8 12s3.1-5.4 9.2-5.4S21.2 12 21.2 12 18.1 17.4 12 17.4 2.8 12 2.8 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8"/>
        </svg>
        @break
    @case('palette')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 3.5a8.5 8.5 0 1 0 0 17h1.3a1.9 1.9 0 0 0 1.3-3.3 1.5 1.5 0 0 1 1-2.6h1.8A3.1 3.1 0 0 0 20.5 11 8.5 8.5 0 0 0 12 3.5Z" stroke="currentColor" stroke-width="1.7"/><circle cx="8" cy="9" r="1" fill="currentColor"/><circle cx="11.2" cy="6.9" r="1" fill="currentColor"/><circle cx="15" cy="8" r="1" fill="currentColor"/><circle cx="7.2" cy="13" r="1" fill="currentColor"/>
        </svg>
        @break
    @case('gear')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M9.4 4.1 10 2.7h4l.6 1.4 1.5.9 1.5-.2 2 3.4-.9 1.2v1.8l.9 1.2-2 3.4-1.5-.2-1.5.9-.6 1.4h-4l-.6-1.4-1.5-.9-1.5.2-2-3.4.9-1.2V9.4l-.9-1.2 2-3.4 1.5.2 1.5-.9Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="12" cy="10.3" r="2.3" stroke="currentColor" stroke-width="1.5"/>
        </svg>
        @break
    @case('search')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="10.5" cy="10.5" r="6.2" stroke="currentColor" stroke-width="1.9"/><path d="m15.1 15.1 4.5 4.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
        </svg>
        @break
    @case('truck')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M3 6.5h10.5v10H3zM13.5 10h3.4l3.1 3.2v3.3h-6.5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
            <circle cx="7" cy="17.5" r="2" stroke="currentColor" stroke-width="1.7"/><circle cx="17" cy="17.5" r="2" stroke="currentColor" stroke-width="1.7"/>
        </svg>
        @break
    @case('billing')
    @case('document')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 3.5h8l4 4v13H6z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M14 3.5v4h4M9 12h6M9 15h6M9 18h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        </svg>
        @break
    @case('payment')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2.2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9h18M7 15h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        </svg>
        @break
    @case('production')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 19V8l5 3V8l5 3V5h4l2 14H4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8 15h2M13 15h2M17 15h1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        </svg>
        @break
    @case('calendar')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <rect x="4" y="5.5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 3.5v4M16 3.5v4M4 9.5h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
        @break
    @case('clock')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break
    @case('stop')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/><rect x="9.4" y="9.4" width="5.2" height="5.2" rx=".8" fill="currentColor"/>
        </svg>
        @break
    @case('warning-triangle')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 4 21 20H3L12 4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 9v5M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
        </svg>
        @break
    @case('external')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M14 4h6v6M20 4l-9 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
        @break
    @case('info')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M12 10.5v5M12 7.5h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
        </svg>
        @break
    @case('refresh')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M19 7v5h-5M5 17v-5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 12a6 6 0 0 0-10.2-4.2L5 10M6 12a6 6 0 0 0 10.2 4.2L19 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break
    @case('cube-search')
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 30 30" fill="none" aria-hidden="true">
            <path d="M4.5 8.5 11 5l6.5 3.5v7L11 19l-6.5-3.5v-7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="m4.5 8.5 6.5 3.6 6.5-3.6M11 12.1V19" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="20.3" cy="19.8" r="4.1" stroke="currentColor" stroke-width="1.6"/><path d="m23.3 22.8 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        @break
    @case('check')
    @default
        <svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="m7.5 12.5 3 3 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
@endswitch
