<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_','-',app()->getLocale())); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <meta name="flowtrack-session-timeout" content="<?php echo e((int) config('session.lifetime', 30) * 60); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
        <meta name="flowtrack-session-status-url" content="<?php echo e(route('session.status')); ?>">
        <meta name="flowtrack-session-recover-url" content="<?php echo e(route('session.recover')); ?>">
        <meta name="flowtrack-logout-url" content="<?php echo e(route('logout')); ?>">
        <meta name="flowtrack-timezone-sync-url" content="<?php echo e(route('session.timezone')); ?>">
        <meta name="flowtrack-display-timezone" content="<?php echo e(app(\App\Services\WorkspaceSettingsService::class)->displayTimezone()); ?>">
        <meta name="flowtrack-rich-text-upload-url" content="<?php echo e(route('rich-text-images.store')); ?>">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <title><?php echo e(($title ?? null) ? $title.' — ' : ''); ?><?php echo e($branding['name'] ?? 'FlowTrack'); ?></title>
    <link rel="icon" href="<?php echo e($branding['favicon_url'] ?? asset('images/flowtrack-favicon.svg')); ?>">
    <script
        src="<?php echo e(asset('js/flowtrack-image-fallback.js')); ?>?v=<?php echo e(\App\Support\FrontendBuildVersion::current()); ?>"
        data-fallback-src="<?php echo e(asset('images/flowtrack-image-fallback.svg')); ?>"
    ></script>
    <?php echo app('Illuminate\Foundation\Vite')('resources/css/application/prelude.css'); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
        <meta name="flowtrack-notification-count-url" content="<?php echo e(route('notifications.unread-count')); ?>">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->check() && app(\App\Services\ReverbChannelService::class)->enabled()): ?>
        <meta name="flowtrack-reverb-key" content="<?php echo e(data_get(config('reverb'), 'apps.apps.0.key')); ?>">
        <meta name="flowtrack-reverb-host" content="<?php echo e(data_get(config('reverb'), 'apps.apps.0.options.host')); ?>">
        <meta name="flowtrack-reverb-port" content="<?php echo e(data_get(config('reverb'), 'apps.apps.0.options.port', 443)); ?>">
        <meta name="flowtrack-reverb-scheme" content="<?php echo e(data_get(config('reverb'), 'apps.apps.0.options.scheme', 'https')); ?>">
        <meta name="flowtrack-reverb-channel" content="private-flowtrack.user.<?php echo e(auth()->id()); ?>">
        <meta name="flowtrack-reverb-workspace-channel" content="private-flowtrack.workspace.<?php echo e(max(1, (int) config('flowtrack.workspace_id', 1))); ?>">
        <meta name="flowtrack-reverb-auth" content="<?php echo e(route('realtime.auth')); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((bool) config('observability.enabled', false) && (bool) config('observability.realtime_client_endpoint', true)): ?>
            <meta name="flowtrack-realtime-telemetry-url" content="<?php echo e(route('telemetry.realtime')); ?>">
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo app('Illuminate\Foundation\Vite')([
        'resources/css/app.css',
        'resources/js/app.js',
    ]); ?>
    
    <?php echo app('Illuminate\Foundation\Vite')('resources/css/application/after-core.css'); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('dashboard', 'team-performance.report')): ?>
        <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/dashboard/prototype.css'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo app('Illuminate\Foundation\Vite')('resources/css/application/after-dashboard.css'); ?>
    <?php echo app('Illuminate\Foundation\Vite')('resources/css/application/shared-components.css'); ?>
    <link rel="stylesheet" href="<?php echo e(asset('css/flowtrack-system-branding.css')); ?>?v=20261019">

    
    <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/orders/index.css'); ?>

    

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('all-tasks')): ?>
        <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/work/index.css'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('workflow.*', 'task-pack.*', 'order-workflow.*')): ?>
        <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/setup/index.css'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('dashboard', 'team-performance.report')): ?>
        <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/dashboard/layout.css'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('documents.index')): ?>
        <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/documents/filters.css'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('inquiries.*')): ?>
        <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/inquiries/filters.css'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('clients.index')): ?>
        <?php echo app('Illuminate\Foundation\Vite')('resources/css/modules/clients/filters.css'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('supplier-reminder.index')): ?>
        <link rel="stylesheet" href="<?php echo e(asset('css/flowtrack-supplier-reminders.css')); ?>?v=7">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

    
    <?php echo app('Illuminate\Foundation\Vite')('resources/theme/flowtrack/theme.css'); ?>

    
    <script
        src="<?php echo e(asset('js/flowtrack-build-track.js')); ?>?v=<?php echo e(\App\Support\FrontendBuildVersion::current()); ?>"
        data-flowtrack-build-track
        data-navigate-track
        defer
    ></script>
    <script
        src="<?php echo e(asset('js/flowtrack-sidebar-navigation.js')); ?>?v=<?php echo e(\App\Support\FrontendBuildVersion::current()); ?>"
        data-navigate-once
        defer
    ></script>
</head>
<body class="<?php echo e(request()->routeIs('dashboard', 'team-performance.report') ? 'ft-management-dashboard-page' : ''); ?>">
<div class="app">
    
    <?php app("livewire")->forceAssetInjection(); ?><div x-persist="<?php echo e('flowtrack-sidebar'); ?>">
        <?php echo $__env->make('layouts.partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>
    <div id="sidebarShade" class="mobile-sidebar-shade"></div>
    <main class="main">
        <?php echo $__env->make('layouts.partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php if (isset($component)) { $__componentOriginald3006faa8aaaa46125e3ed862ca54da4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald3006faa8aaaa46125e3ed862ca54da4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.async-feedback','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.async-feedback'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald3006faa8aaaa46125e3ed862ca54da4)): ?>
<?php $attributes = $__attributesOriginald3006faa8aaaa46125e3ed862ca54da4; ?>
<?php unset($__attributesOriginald3006faa8aaaa46125e3ed862ca54da4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald3006faa8aaaa46125e3ed862ca54da4)): ?>
<?php $component = $__componentOriginald3006faa8aaaa46125e3ed862ca54da4; ?>
<?php unset($__componentOriginald3006faa8aaaa46125e3ed862ca54da4); ?>
<?php endif; ?>
        <div class="content <?php echo e(request()->routeIs('dashboard', 'team-performance.report') ? 'ft-dashboard-content-shell' : ''); ?> <?php echo e(request()->routeIs('reports') ? 'ft-inquiry-intelligence-content-shell' : ''); ?>">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success') && !request()->routeIs('task-pack.setup','master-data','financial-master-data','profile','inquiries.*','company.setup')): ?><div class="flash"><?php echo e(session('success')); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php echo $__env->yieldContent('content'); ?>
        </div>
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
    </main>
    <?php echo $__env->make('layouts.partials.mobile-bottom', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>


</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/layouts/app.blade.php ENDPATH**/ ?>