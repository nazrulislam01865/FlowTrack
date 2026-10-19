<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['as' => 'div', 'label' => 'Filters', 'loadingTarget' => null]));

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

foreach (array_filter((['as' => 'div', 'label' => 'Filters', 'loadingTarget' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($as === 'section'): ?>
<section <?php echo e($attributes->class(['ft-filter-bar'])); ?> data-ft-ui-component="filter-bar" role="group" aria-label="<?php echo e($label); ?>" <?php if($loadingTarget): ?> wire:loading.class="ft-filter-bar--updating" wire:target="<?php echo e($loadingTarget); ?>" <?php endif; ?>>
    <?php echo e($slot); ?>

</section>
<?php else: ?>
<div <?php echo e($attributes->class(['ft-filter-bar'])); ?> data-ft-ui-component="filter-bar" role="group" aria-label="<?php echo e($label); ?>" <?php if($loadingTarget): ?> wire:loading.class="ft-filter-bar--updating" wire:target="<?php echo e($loadingTarget); ?>" <?php endif; ?>>
    <?php echo e($slot); ?>

</div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/filter-bar.blade.php ENDPATH**/ ?>