@props(['active' => false, 'removable' => false, 'label' => null])
<button
    type="button"
    {{ $attributes->class(['ft-filter-chip', 'ft-filter-chip--removable' => $removable]) }}
    data-ft-ui-component="filter-chip"
    aria-pressed="{{ $active ? 'true' : 'false' }}"
>
    @if($label)<span>{{ $label }}</span>@else{{ $slot }}@endif
    @if($removable)<span class="ft-filter-chip__remove" aria-hidden="true">×</span>@endif
</button>
