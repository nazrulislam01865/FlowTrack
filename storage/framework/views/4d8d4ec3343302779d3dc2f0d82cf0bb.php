<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'property' => 'search',
    'value' => '',
    'placeholder' => 'Search…',
    'label' => 'Search',
    'debounce' => 300,
    'clearAction' => null,
    'hint' => null,
    'shortcut' => '/',
    'hideLabel' => false,
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'property' => 'search',
    'value' => '',
    'placeholder' => 'Search…',
    'label' => 'Search',
    'debounce' => 300,
    'clearAction' => null,
    'hint' => null,
    'shortcut' => '/',
    'hideLabel' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<div
    <?php echo e($attributes->class(['ft-search-input', 'ft-jl-control', 'ft-jl-control-search'])); ?>

    data-ft-ui-component="search-input"
    x-data
    <?php if($shortcut === '/'): ?> x-on:keydown.window="if ($event.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) { $event.preventDefault(); $refs.search.focus(); }" <?php endif; ?>
    <?php if($shortcut === 'mod+k'): ?> x-on:keydown.window.meta.k.prevent="$refs.search.focus()" x-on:keydown.window.ctrl.k.prevent="$refs.search.focus()" <?php endif; ?>
>
    <label class="ft-search-input__label <?php echo e($hideLabel ? 'u-sr-only' : ''); ?>"><?php echo e($label); ?></label>
    <div class="ft-search-input__control ft-jl-search-wrap">
        <span class="ft-search-input__icon ft-jl-search-icon" aria-hidden="true">⌕</span>
        <input
            x-ref="search"
            class="ft-search-input__input ft-jl-search"
            type="text"
            role="searchbox"
            inputmode="search"
            <?php if($debounce === 700): ?> wire:model.live.debounce.700ms="<?php echo e($property); ?>"
            <?php elseif($debounce === 650): ?> wire:model.live.debounce.650ms="<?php echo e($property); ?>"
            <?php elseif($debounce === 400): ?> wire:model.live.debounce.400ms="<?php echo e($property); ?>"
            <?php elseif($debounce === 350): ?> wire:model.live.debounce.350ms="<?php echo e($property); ?>"
            <?php else: ?> wire:model.live.debounce.300ms="<?php echo e($property); ?>" <?php endif; ?>
            placeholder="<?php echo e($placeholder); ?>"
            autocomplete="off"
            x-on:keydown.escape="if ($el.value) { <?php if($clearAction): ?> $wire.call(<?php echo \Illuminate\Support\Js::from($clearAction)->toHtml() ?>) <?php else: ?> $wire.set(<?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>, '') <?php endif; ?>; }"
        >
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($value)): ?>
            <button type="button" class="ft-search-input__clear ft-jl-clear-search" <?php if($clearAction): ?> wire:click="<?php echo e($clearAction); ?>" <?php else: ?> wire:click="$set('<?php echo e($property); ?>', '')" <?php endif; ?> aria-label="Clear search">×</button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hint): ?><small class="ft-search-input__hint"><?php echo e($hint); ?></small><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/search-input.blade.php ENDPATH**/ ?>