@props(['name'])
<svg {{ $attributes->merge(['class' => 'ft-rfq-icon', 'viewBox' => '0 0 24 24', 'fill' => 'none', 'aria-hidden' => 'true']) }}>
    @switch($name)
        @case('lock')
            <rect x="5" y="10" width="14" height="10" rx="2"></rect>
            <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
            @break
        @case('help')
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M9.8 9a2.4 2.4 0 1 1 3.8 1.95c-.95.68-1.6 1.14-1.6 2.3"></path>
            <path d="M12 17h.01"></path>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 7v5l3 2"></path>
            @break
        @case('arrow-left')
            <path d="M19 12H5"></path>
            <path d="m10 17-5-5 5-5"></path>
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6"></path>
            @break
        @case('chevron-down')
            <path d="m8 10 4 4 4-4"></path>
            @break
        @case('save')
            <path d="M5 4h11l3 3v13H5z"></path>
            <path d="M8 4v6h8V4"></path>
            <path d="M9 20v-6h6v6"></path>
            @break
        @case('pencil')
            <path d="m4 20 4.4-1 9.8-9.8a2.1 2.1 0 0 0-3-3L5.4 16z"></path>
            <path d="m13.8 7.6 3 3"></path>
            @break
        @case('info')
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 10v6"></path>
            <path d="M12 7h.01"></path>
            @break
        @case('upload-cloud')
            <path d="M7 18H6a4 4 0 0 1-.4-8A6.5 6.5 0 0 1 18 9.2 4.5 4.5 0 0 1 18 18h-1"></path>
            <path d="M12 19V9"></path>
            <path d="m8.5 12.5 3.5-3.5 3.5 3.5"></path>
            @break
        @case('check')
            <path d="m6.5 12.5 3.5 3.5 7.5-8"></path>
            @break
        @case('external')
            <path d="M14 5h5v5"></path>
            <path d="m19 5-8 8"></path>
            <path d="M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path>
            @break
        @case('download')
            <path d="M12 4v11"></path>
            <path d="m8 11 4 4 4-4"></path>
            <path d="M5 20h14"></path>
            @break
        @case('alert')
            <path d="M12 8v5"></path>
            <path d="M12 16h.01"></path>
            <path d="M10.3 4.6 3.4 17a2 2 0 0 0 1.75 3h13.7a2 2 0 0 0 1.75-3L13.7 4.6a2 2 0 0 0-3.4 0Z"></path>
            @break
    @endswitch
</svg>
