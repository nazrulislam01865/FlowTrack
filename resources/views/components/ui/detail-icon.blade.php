@props(['name'])

<span {{ $attributes->class('ft-detail-svg-icon') }} aria-hidden="true">
    @switch($name)
        @case('edit')
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M4.5 19.5h4l10-10a2.8 2.8 0 0 0-4-4l-10 10v4Z"></path>
                <path d="m13.5 6.5 4 4"></path>
            </svg>
            @break
        @case('calendar')
            <svg viewBox="0 0 24 24" fill="none">
                <rect x="4" y="5.5" width="16" height="14" rx="2"></rect>
                <path d="M8 3.5v4M16 3.5v4M4 10h16"></path>
            </svg>
            @break
        @case('copy')
            <svg viewBox="0 0 24 24" fill="none">
                <rect x="8" y="8" width="10" height="10" rx="1.5"></rect>
                <path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"></path>
            </svg>
            @break
        @default
            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8"></circle></svg>
    @endswitch
</span>
