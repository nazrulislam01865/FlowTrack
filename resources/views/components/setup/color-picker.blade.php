@props(['model', 'label' => 'Choose color', 'inputClass' => 'ft-master-color-picker', 'textClass' => null, 'containerClass' => null, 'showText' => false])
@if($containerClass)<div class="{{ $containerClass }}">@endif
<input @if($inputClass) class="{{ $inputClass }}" @endif type="color" wire:model.live="{{ $model }}" aria-label="{{ $label }}">
@if($showText)<input @if($textClass) class="{{ $textClass }}" @endif type="text" wire:model="{{ $model }}" maxlength="7" spellcheck="false">@endif
@if($containerClass)</div>@endif
