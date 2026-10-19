@props([
    'label',
    'variant' => 'neutral',
    'color' => null,
    'dot' => false,
])

<x-ui.badge
    :label="$label"
    :variant="$color ? 'neutral' : $variant"
    :dynamic-color="$color"
    :dot="$dot"
    class="{{ $attributes->get('class') }}"
/>
