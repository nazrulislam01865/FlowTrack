@props(['type' => 'other'])

@if($type === 'sea')
    <svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
        <path d="M4 15.5 6.2 8h11.6l2.2 7.5"/>
        <path d="M8.2 8V5.5h7.6V8M10.2 5.5V3.8h3.6v1.7"/>
        <path d="M3 16.2c1.2 0 1.8 1.4 3 1.4s1.8-1.4 3-1.4 1.8 1.4 3 1.4 1.8-1.4 3-1.4 1.8 1.4 3 1.4 1.8-1.4 3-1.4"/>
        <path d="M4 20c1.2 0 1.8 1.2 3 1.2S8.8 20 10 20s1.8 1.2 3 1.2 1.8-1.2 3-1.2 1.8 1.2 3 1.2"/>
    </svg>
@elseif($type === 'air')
    <svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
        <path d="m3.4 13.2 6.3 1.7 5.7-5.7-8.7-3.1 1.6-1.6 10.1 1.7 2-2c.8-.8 2-.9 2.5-.4.5.5.4 1.7-.4 2.5l-2 2 1.7 10.1-1.6 1.6-3.1-8.7-5.7 5.7 1.7 6.3-1.3 1.3-3.3-5-5-3.3 1.5-1.4Z"/>
    </svg>
@elseif($type === 'express')
    <svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.9', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
        <path d="M13.2 2.8 5.5 13h5.4l-.4 8.2L18.5 11h-5.4l.1-8.2Z"/>
    </svg>
@else
    <svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
        <path d="M4 7.5h10v9H4zM14 10h3.5l2.5 3v3.5h-6z"/>
        <circle cx="7.5" cy="18" r="1.5"/><circle cx="17.5" cy="18" r="1.5"/>
    </svg>
@endif
