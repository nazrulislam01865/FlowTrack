@props(['branding' => []])
@php($footer = $branding['system_footer'] ?? \App\Support\SystemFooter::defaults())
@if($footer['enabled'])
<footer {{ $attributes->class(['ft-system-footer']) }} aria-label="Copyright information">
    <span>{{ $footer['copyright'] }}
        @if($footer['website_url'] !== '')
            <a href="{{ $footer['website_url'] }}" target="_blank" rel="noopener noreferrer">{{ $footer['owner'] }}</a>
        @else
            {{ $footer['owner'] }}
        @endif
        @if($footer['suffix'] !== ''). {{ $footer['suffix'] }}@endif
    </span>
</footer>
@endif
