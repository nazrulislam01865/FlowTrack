@props([
    'message',
])

<div {{ $attributes->class(['ft-validation-message']) }} data-ft-ui-component="validation-message" role="alert">
    <span aria-hidden="true">!</span>
    <span>{{ $message }}</span>
</div>
