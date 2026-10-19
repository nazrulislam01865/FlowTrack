@props([
    'label' => 'Loading…',
])

<div {{ $attributes->class(['ft-loading']) }} data-ft-ui-component="loading" role="status" aria-live="polite">
    <span class="ft-loading__spinner" aria-hidden="true"></span>
    <span>{{ $label }}</span>
</div>
