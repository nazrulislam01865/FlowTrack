<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'phase' => null,
    'short' => false,
    'fallback' => '—',
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
    'phase' => null,
    'short' => false,
    'fallback' => '—',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $label = $fallback;
    $fullLabel = $fallback;
    $color = null;
    if ($phase) {
        $fullLabel = trim((string) ($phase->name ?? '')) ?: trim((string) ($phase->short_name ?? '')) ?: $fallback;
        $label = $short
            ? (trim((string) ($phase->short_name ?? '')) ?: $fullLabel)
            : $fullLabel;
        $color = $phase->color ?? null;
    }
?>
<span <?php echo e($attributes->class(['ft-phase-color-label', 'ft-badge--dynamic'])->merge(['style' => \App\Support\MasterColor::style($color), 'title' => $fullLabel])); ?>><?php echo e($label); ?></span>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/phase-label.blade.php ENDPATH**/ ?>