@props([
    'message',
    'title' => 'Could not save changes',
    'retryAction' => null,
])
<div {{ $attributes->class(['ft-server-error']) }} data-ft-ui-component="server-error" role="alert" aria-live="assertive">
    <div class="ft-server-error__copy">
        <strong>{{ $title }}</strong>
        <span>{{ $message }}</span>
    </div>
    @if($retryAction)
        <button type="button" class="ft-server-error__retry" wire:click="{{ $retryAction }}">Retry</button>
    @endif
</div>
