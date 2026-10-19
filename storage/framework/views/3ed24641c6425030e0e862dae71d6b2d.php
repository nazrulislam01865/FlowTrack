<?php $__env->startSection('title', 'Order tracking — '.($branding['name'] ?? 'FlowTrack')); ?>

<?php $__env->startSection('content'); ?>
<main class="ft-tracking-shell">
    <section class="ft-tracking-search-pane" aria-labelledby="tracking-search-page-title">
        <h1 id="tracking-search-page-title">Order tracking</h1>
        <?php if (isset($component)) { $__componentOriginal89cb4f77f847127c3203eb38fb2a1ebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal89cb4f77f847127c3203eb38fb2a1ebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.order-tracking.search-card','data' => ['lookupType' => $lookupType ?? old('lookup_type', 'order'),'identifier' => $identifier ?? '','email' => $email ?? '','lookupError' => $lookupError ?? null]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('order-tracking.search-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['lookup-type' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($lookupType ?? old('lookup_type', 'order')),'identifier' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($identifier ?? ''),'email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($email ?? ''),'lookup-error' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($lookupError ?? null)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal89cb4f77f847127c3203eb38fb2a1ebf)): ?>
<?php $attributes = $__attributesOriginal89cb4f77f847127c3203eb38fb2a1ebf; ?>
<?php unset($__attributesOriginal89cb4f77f847127c3203eb38fb2a1ebf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal89cb4f77f847127c3203eb38fb2a1ebf)): ?>
<?php $component = $__componentOriginal89cb4f77f847127c3203eb38fb2a1ebf; ?>
<?php unset($__componentOriginal89cb4f77f847127c3203eb38fb2a1ebf); ?>
<?php endif; ?>
    </section>

    <section class="ft-tracking-result-pane" aria-labelledby="tracking-result-page-title">
        <h1 id="tracking-result-page-title">Order tracking</h1>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($tracking)): ?>
            <?php if (isset($component)) { $__componentOriginalbc5c69fb1d47d7e90696d0f961cffe35 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbc5c69fb1d47d7e90696d0f961cffe35 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.order-tracking.result-card','data' => ['tracking' => $tracking]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('order-tracking.result-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['tracking' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($tracking)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbc5c69fb1d47d7e90696d0f961cffe35)): ?>
<?php $attributes = $__attributesOriginalbc5c69fb1d47d7e90696d0f961cffe35; ?>
<?php unset($__attributesOriginalbc5c69fb1d47d7e90696d0f961cffe35); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbc5c69fb1d47d7e90696d0f961cffe35)): ?>
<?php $component = $__componentOriginalbc5c69fb1d47d7e90696d0f961cffe35; ?>
<?php unset($__componentOriginalbc5c69fb1d47d7e90696d0f961cffe35); ?>
<?php endif; ?>
        <?php else: ?>
            <div class="ft-tracking-result-placeholder" aria-hidden="true"></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>
</main>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('tracking.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/tracking/index.blade.php ENDPATH**/ ?>