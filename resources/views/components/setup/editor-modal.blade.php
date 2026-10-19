@props(['label' => 'Setup editor', 'class' => 'ft-phase-reference-modal', 'closeAction' => null])
<div class="ft-reference-overlay" @if(isset($closeAction)) wire:click.self="{{ $closeAction }}" @endif></div>
<div class="{{ $class }}" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-label="{{ $label }}">
    {{ $slot }}
</div>
