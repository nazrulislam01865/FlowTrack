<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'client' => null,
    'name' => null,
    'size' => 34,
    'src' => null,
    'shape' => 'rounded',
    'archived' => false,
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
    'client' => null,
    'name' => null,
    'size' => 34,
    'src' => null,
    'shape' => 'rounded',
    'archived' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $displayName = trim((string) ($client?->name ?? $name ?? 'Client'));
    $imageUrl = $src ?: $client?->logoUrl();
    $initials = \App\Support\BoardPresenter::initials($displayName) ?: 'C';
    $shapeClass = $shape === 'circle' ? 'is-circle' : 'is-rounded';
?>
<span <?php echo e($attributes->class(['ft-client-logo-mark', $shapeClass, 'is-archived' => $archived])->merge(['style' => "--ft-client-logo-size:{$size}px"])); ?> aria-hidden="true">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($imageUrl): ?>
        <img src="<?php echo e($imageUrl); ?>" alt="" loading="lazy" decoding="async" data-ft-image-fallback="icon">
        <span class="ft-client-logo-fallback" hidden><?php echo e($initials); ?></span>
    <?php else: ?>
        <span class="ft-client-logo-fallback"><?php echo e($initials); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</span>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/client-logo.blade.php ENDPATH**/ ?>