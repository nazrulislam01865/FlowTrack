@props(['title', 'closeAction', 'label' => null])
<div class="ft-reference-overlay" wire:click.self="{{ $closeAction }}"></div>
<div class="ft-phase-reference-modal ft-delete-impact-modal" role="alertdialog" aria-modal="true" aria-label="{{ $label ?: $title }}">
    <div class="ft-phase-modal-head">
        <h2>{{ $title }}</h2>
        <button type="button" wire:click="{{ $closeAction }}">×</button>
    </div>
    <div class="ft-phase-modal-body">{{ $slot }}</div>
    @if(isset($footer))<div class="ft-phase-modal-footer">{{ $footer }}</div>@endif
</div>
