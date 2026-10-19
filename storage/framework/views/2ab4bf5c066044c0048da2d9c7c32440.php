<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['name' => null, 'user' => null, 'src' => null, 'dark' => false, 'size' => null]));

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

foreach (array_filter((['name' => null, 'user' => null, 'src' => null, 'dark' => false, 'size' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $displayName = $user?->name ?? $name ?? 'FlowTrack';
    $imagePath = $user?->profile_image_path;

    // Serve stored avatars through an application route instead of relying on
    // public/storage. This works consistently in local/dev and production even
    // when a filesystem symlink is missing, while the random stored filename
    // gives us a stable cache-busting URL whenever the photo is replaced.
    $imageUrl = $src;

    if (! $imageUrl && $user?->id) {
        $imageUrl = $user->profileImageUrl();
    }

    $initials = collect(preg_split('/\s+/', trim($displayName)))
        ->filter()
        ->map(fn ($part) => mb_substr($part, 0, 1))
        ->take(2)
        ->implode('');
    $fontSizeRem = $size ? max(9, round($size / 3.4)) / 16 : null;
    $style = $size ? "width:{$size}px;height:{$size}px;font-size:{$fontSizeRem}rem" : null;
?>
<span <?php echo e($attributes->class(['avatar', 'dark' => $dark])->merge(['style' => $style])); ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($imageUrl): ?>
        <img
            src="<?php echo e($imageUrl); ?>"
            alt=""
            aria-hidden="true"
            loading="lazy"
            decoding="async"
            data-ft-image-fallback="sibling"
        >
        <span class="avatar-initials" hidden aria-hidden="true"><?php echo e($initials ?: 'FT'); ?></span>
    <?php else: ?>
        <span class="avatar-initials" aria-hidden="true"><?php echo e($initials ?: 'FT'); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</span>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/avatar.blade.php ENDPATH**/ ?>