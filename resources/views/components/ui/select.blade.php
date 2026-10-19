@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'value' => null,
    'options' => [],
    'placeholder' => null,
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
    <select
        id="{{ $id ?? $name }}"
        @if($name) name="{{ $name }}" @endif
        {{ $attributes->class(['ft-field__control']) }}
        @if($help || $error || ($name && $errors->has($name))) aria-describedby="{{ $id ?? $name }}-feedback" @endif
        @if($error || ($name && $errors->has($name))) aria-invalid="true" @endif
        @required($required)
        @disabled($disabled)
    >
        @if(!is_null($placeholder))<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $optionValue => $optionLabel)
            <option value="{{ is_int($optionValue) ? $optionLabel : $optionValue }}" @selected((string) $value === (string) (is_int($optionValue) ? $optionLabel : $optionValue))>{{ $optionLabel }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if($help || $error || ($name && $errors->has($name)))
        <div id="{{ $id ?? $name }}-feedback" class="ft-field__feedback">
            @if(filled($help))<div class="ft-field__help">{{ $help }}</div>@endif
            @if($error || ($name && $errors->has($name)))<x-ui.validation-message :message="$error ?: $errors->first($name)" />@endif
        </div>
    @endif
</div>
