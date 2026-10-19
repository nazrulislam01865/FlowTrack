<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
    'loading' => false,
    'loadingLabel' => 'Working…',
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
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
    'loading' => false,
    'loadingLabel' => 'Working…',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($href): ?>
    <a
        <?php if (! ($disabled || $loading)): ?> href="<?php echo e($href); ?>" <?php endif; ?>
        <?php echo e($attributes->class([
            'ft-btn',
            'ft-btn--primary' => $variant === 'primary',
            'ft-btn--secondary' => $variant === 'secondary',
            'ft-btn--tertiary' => $variant === 'tertiary',
            'ft-btn--danger' => $variant === 'danger',
            'ft-btn--sm' => $size === 'sm',
            'ft-btn--lg' => $size === 'lg',
        ])); ?>

        data-ft-ui-component="button"
        <?php if($disabled || $loading): ?> aria-disabled="true" tabindex="-1" <?php endif; ?>
        <?php if($loading): ?> aria-busy="true" <?php endif; ?>
    >
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($loading): ?><span class="ft-btn__spinner" aria-hidden="true"></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <span><?php echo e($loading ? $loadingLabel : $slot); ?></span>
    </a>
<?php else: ?>
    <button
        type="<?php echo e($type); ?>"
        <?php echo e($attributes->class([
            'ft-btn',
            'ft-btn--primary' => $variant === 'primary',
            'ft-btn--secondary' => $variant === 'secondary',
            'ft-btn--tertiary' => $variant === 'tertiary',
            'ft-btn--danger' => $variant === 'danger',
            'ft-btn--sm' => $size === 'sm',
            'ft-btn--lg' => $size === 'lg',
        ])); ?>

        data-ft-ui-component="button"
        <?php if($disabled || $loading): echo 'disabled'; endif; ?>
        <?php if($loading): ?> aria-busy="true" <?php endif; ?>
    >
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($loading): ?><span class="ft-btn__spinner" aria-hidden="true"></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <span><?php echo e($loading ? $loadingLabel : $slot); ?></span>
    </button>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/button.blade.php ENDPATH**/ ?>