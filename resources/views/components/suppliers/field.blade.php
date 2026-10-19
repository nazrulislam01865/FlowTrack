@props([
    'label',
    'required' => false,
    'error' => null,
    'wide' => false,
    'help' => null,
])
<div {{ $attributes->class(['ft-supplier-field', 'is-wide' => $wide]) }}>
    <label>
        {{ $label }}
        @if($required)<span class="ft-supplier-required" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    @if($help)<div class="ft-supplier-field-help">{{ $help }}</div>@endif
    @if($error)
        @error($error)<div class="validation-error">{{ $message }}</div>@enderror
    @endif
</div>
