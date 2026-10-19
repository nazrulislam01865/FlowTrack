@props([
    'label' => null,
    'for' => null,
    'help' => null,
    'required' => false,
    'optional' => false,
])

<div {{ $attributes->class(['ft-field']) }} data-ft-ui-component="field">
    @if(filled($label))
        <div class="ft-field__label-row">
            <label class="ft-field__label" @if($for) for="{{ $for }}" @endif>
                {{ $label }}@if($required)<span class="ft-field__required" aria-hidden="true"> *</span>@endif
            </label>
            @if($optional)<span class="ft-field__optional">Optional</span>@endif
        </div>
    @endif
    {{ $slot }}
    @if(filled($help))<div class="ft-field__help">{{ $help }}</div>@endif
    @if(isset($error)){{ $error }}@endif
</div>
