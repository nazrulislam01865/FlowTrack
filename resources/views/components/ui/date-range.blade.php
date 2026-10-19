@props([
    'fromProperty' => 'dateFrom', 'toProperty' => 'dateTo', 'fromValue' => '', 'toValue' => '',
    'label' => 'Date range', 'fromLabel' => 'Date from', 'toLabel' => 'Date to', 'clearAction' => null,
])
@php $active = filled($fromValue) || filled($toValue); @endphp
<div {{ $attributes->class(['ft-date-range', 'ft-date-range-filter', 'is-active' => $active]) }} data-ft-ui-component="date-range" role="group" aria-label="{{ $label }}">
    <div class="ft-date-range-fields">
        <label class="ft-date-range-field">
            <span>{{ $fromLabel }}</span>
            <input type="date" lang="en-GB" wire:model.live="{{ $fromProperty }}" @if(filled($toValue)) max="{{ $toValue }}" @endif aria-label="{{ $label }} from">
        </label>
        <label class="ft-date-range-field">
            <span>{{ $toLabel }}</span>
            <input type="date" lang="en-GB" wire:model.live="{{ $toProperty }}" @if(filled($fromValue)) min="{{ $fromValue }}" @endif aria-label="{{ $label }} to">
        </label>
    </div>
    @if($clearAction && $active)<button type="button" class="ft-date-range__clear" wire:click="{{ $clearAction }}">Clear dates</button>@endif
</div>
