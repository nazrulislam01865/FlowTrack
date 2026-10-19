<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'fromProperty' => 'dateFrom', 'toProperty' => 'dateTo', 'fromValue' => '', 'toValue' => '',
    'label' => 'Date range', 'fromLabel' => 'Date from', 'toLabel' => 'Date to', 'clearAction' => null,
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
    'fromProperty' => 'dateFrom', 'toProperty' => 'dateTo', 'fromValue' => '', 'toValue' => '',
    'label' => 'Date range', 'fromLabel' => 'Date from', 'toLabel' => 'Date to', 'clearAction' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php $active = filled($fromValue) || filled($toValue); ?>
<div <?php echo e($attributes->class(['ft-date-range', 'ft-date-range-filter', 'is-active' => $active])); ?> data-ft-ui-component="date-range" role="group" aria-label="<?php echo e($label); ?>">
    <div class="ft-date-range-fields">
        <label class="ft-date-range-field">
            <span><?php echo e($fromLabel); ?></span>
            <input type="date" lang="en-GB" wire:model.live="<?php echo e($fromProperty); ?>" <?php if(filled($toValue)): ?> max="<?php echo e($toValue); ?>" <?php endif; ?> aria-label="<?php echo e($label); ?> from">
        </label>
        <label class="ft-date-range-field">
            <span><?php echo e($toLabel); ?></span>
            <input type="date" lang="en-GB" wire:model.live="<?php echo e($toProperty); ?>" <?php if(filled($fromValue)): ?> min="<?php echo e($fromValue); ?>" <?php endif; ?> aria-label="<?php echo e($label); ?> to">
        </label>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($clearAction && $active): ?><button type="button" class="ft-date-range__clear" wire:click="<?php echo e($clearAction); ?>">Clear dates</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/date-range.blade.php ENDPATH**/ ?>