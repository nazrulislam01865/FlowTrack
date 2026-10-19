<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?>
    <div class="ft-order-list-flash" role="status"><?php echo e(session('success')); ?></div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<header class="list-head">
    <div>
        <div class="breadcrumbs"><?php echo e($pageBreadcrumbs); ?></div>
        <h1><?php echo e($pageTitle); ?></h1>
        <p class="sub"><?php echo e($pageDescription); ?></p>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showPageActions): ?>
        <div class="top-actions">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canAccess('jobs.create')): ?>
                <a class="btn" href="<?php echo e(route('orders.bulk-import')); ?>">⇧ Bulk order</a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canModule('jobs', 'create')): ?>
                <a class="btn primary" href="<?php echo e(route('jobs.index', ['create' => 1])); ?>" wire:navigate>＋ Create order</a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</header>

<?php if (isset($component)) { $__componentOriginalee5bb7364c37061cbe535f4c41f9060f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalee5bb7364c37061cbe535f4c41f9060f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.orders.workflow-stage-overview','data' => ['stages' => $stages,'selectedStageId' => $phaseFilter,'mode' => 'filter','title' => $workflowTitle,'description' => $workflowDescription]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('orders.workflow-stage-overview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['stages' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stages),'selected-stage-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($phaseFilter),'mode' => 'filter','title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($workflowTitle),'description' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($workflowDescription)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

     <?php $__env->slot('summaryCards', null, []); ?> 
        <button
            type="button"
            class="ft-order-workflow-stage-card ft-order-workflow-metric-card <?php echo e($metricFilter === 'createdToday' ? 'active' : ''); ?>"
            style="--stage:#2d72d9;--stage-text:#ffffff"
            wire:click="setMetricFilter('createdToday')"
            aria-pressed="<?php echo e($metricFilter === 'createdToday' ? 'true' : 'false'); ?>"
        >
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($metricFilter === 'createdToday'): ?>
                <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <span class="ft-order-workflow-stage-kicker">Today</span>
            <b>Created Today</b>
            <span class="ft-order-workflow-stage-count">
                <em>Current filters</em>
                <strong><?php echo e(number_format((int) ($metrics['createdToday'] ?? 0))); ?></strong>
            </span>
        </button>

        <button
            type="button"
            class="ft-order-workflow-stage-card ft-order-workflow-metric-card <?php echo e($metricFilter === 'completed' ? 'active' : ''); ?>"
            style="--stage:#159a68;--stage-text:#ffffff"
            wire:click="setMetricFilter('completed')"
            aria-pressed="<?php echo e($metricFilter === 'completed' ? 'true' : 'false'); ?>"
        >
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($metricFilter === 'completed'): ?>
                <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <span class="ft-order-workflow-stage-kicker">Status</span>
            <b>Completed</b>
            <span class="ft-order-workflow-stage-count">
                <em>Current filters</em>
                <strong><?php echo e(number_format((int) ($metrics['completed'] ?? 0))); ?></strong>
            </span>
        </button>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalee5bb7364c37061cbe535f4c41f9060f)): ?>
<?php $attributes = $__attributesOriginalee5bb7364c37061cbe535f4c41f9060f; ?>
<?php unset($__attributesOriginalee5bb7364c37061cbe535f4c41f9060f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalee5bb7364c37061cbe535f4c41f9060f)): ?>
<?php $component = $__componentOriginalee5bb7364c37061cbe535f4c41f9060f; ?>
<?php unset($__componentOriginalee5bb7364c37061cbe535f4c41f9060f); ?>
<?php endif; ?>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/orders/list/header-and-stages.blade.php ENDPATH**/ ?>