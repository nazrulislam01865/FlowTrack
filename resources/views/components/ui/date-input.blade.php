@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'value' => null,
    'min' => null,
    'max' => null,
    'help' => null,
    'error' => null,
    'required' => false,
    'optional' => false,
    'disabled' => false,
])

<div class="ft-field" data-ft-ui-component="field">
    @if(filled($label))
        <div class="ft-field__label-row">
            <label class="ft-field__label" for="{{ $id ?? $name }}">
                {{ $label }}@if($required)<span class="ft-field__required" aria-hidden="true"> *</span>@endif
            </label>
            @if($optional)<span class="ft-field__optional">Optional</span>@endif
        </div>
    @endif
    <input
        id="{{ $id ?? $name }}"
        @if($name) name="{{ $name }}" @endif
        type="date"
        @if(!is_null($value)) value="{{ $value }}" @endif
        @if(filled($min)) min="{{ $min }}" @endif
        @if(filled($max)) max="{{ $max }}" @endif
        {{ $attributes->class(['ft-field__control']) }}
        @if($help || $error || ($name && $errors->has($name))) aria-describedby="{{ $id ?? $name }}-feedback" @endif
        @if($error || ($name && $errors->has($name))) aria-invalid="true" @endif
        @required($required)
        @disabled($disabled)
    >
    @if($help || $error || ($name && $errors->has($name)))
        <div id="{{ $id ?? $name }}-feedback" class="ft-field__feedback">
            @if(filled($help))<div class="ft-field__help">{{ $help }}</div>@endif
            @if($error || ($name && $errors->has($name)))<x-ui.validation-message :message="$error ?: $errors->first($name)" />@endif
        </div>
    @endif
</div>
