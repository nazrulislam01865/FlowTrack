@props([
    'id',
    'text',
])

<span {{ $attributes->class(['ft-tooltip']) }} data-ft-ui-component="tooltip">
    <span tabindex="0" aria-describedby="{{ $id }}">{{ $slot }}</span>
    <span id="{{ $id }}" class="ft-tooltip__content" role="tooltip">{{ $text }}</span>
</span>
