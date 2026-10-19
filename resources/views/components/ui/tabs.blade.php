@props([
    'label' => 'Sections',
])

<div {{ $attributes->class(['ft-tabs']) }} data-ft-ui-component="tabs" role="tablist" aria-label="{{ $label }}">
    {{ $slot }}
</div>
