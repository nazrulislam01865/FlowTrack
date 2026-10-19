<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo $__env->yieldContent('title', 'Order tracking — '.($branding['name'] ?? 'FlowTrack')); ?></title>
    <link rel="icon" href="<?php echo e(($branding['favicon_url'] ?? asset('images/flowtrack-favicon.svg'))); ?>">
    <script
        src="<?php echo e(asset('js/flowtrack-image-fallback.js')); ?>?v=<?php echo e(\App\Support\FrontendBuildVersion::current()); ?>"
        data-fallback-src="<?php echo e(asset('images/flowtrack-image-fallback.svg')); ?>"
    ></script>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/tracking.css', 'resources/js/order-tracking.js']); ?>
    <link rel="stylesheet" href="<?php echo e(asset('css/flowtrack-system-branding.css')); ?>?v=20261019">
</head>
<body class="ft-tracking-page">
    <?php echo $__env->yieldContent('content'); ?>
    <?php if (isset($component)) { $__componentOriginal7800c327eec291517b20175d0dfe4a85 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7800c327eec291517b20175d0dfe4a85 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.system-footer','data' => ['branding' => $branding]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('system-footer'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['branding' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($branding)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7800c327eec291517b20175d0dfe4a85)): ?>
<?php $attributes = $__attributesOriginal7800c327eec291517b20175d0dfe4a85; ?>
<?php unset($__attributesOriginal7800c327eec291517b20175d0dfe4a85); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7800c327eec291517b20175d0dfe4a85)): ?>
<?php $component = $__componentOriginal7800c327eec291517b20175d0dfe4a85; ?>
<?php unset($__componentOriginal7800c327eec291517b20175d0dfe4a85); ?>
<?php endif; ?>
</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/tracking/layout.blade.php ENDPATH**/ ?>