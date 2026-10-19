        <section class="view">
            <div class="pagehead">
                <div><h1>Inquiries</h1><p>Manage client requests from first inquiry through tasks, conversion, or closure.</p></div>
                <div class="actions">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canModule('reports', 'export')): ?>
                        <?php if (isset($component)) { $__componentOriginal482bd23a44299291608e7a4e016b33b6 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal482bd23a44299291608e7a4e016b33b6 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.list-export-period-modal','data' => ['action' => route('inquiries.export'),'filters' => $inquiryExportQuery,'buttonClass' => 'secondary ft-list-export-button','entityLabel' => 'inquiries']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.list-export-period-modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('inquiries.export')),'filters' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($inquiryExportQuery),'button-class' => 'secondary ft-list-export-button','entity-label' => 'inquiries']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal482bd23a44299291608e7a4e016b33b6)): ?>
<?php $attributes = $__attributesOriginal482bd23a44299291608e7a4e016b33b6; ?>
<?php unset($__attributesOriginal482bd23a44299291608e7a4e016b33b6); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal482bd23a44299291608e7a4e016b33b6)): ?>
<?php $component = $__componentOriginal482bd23a44299291608e7a4e016b33b6; ?>
<?php unset($__componentOriginal482bd23a44299291608e7a4e016b33b6); ?>
<?php endif; ?>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canModule('inquiries','create')): ?><button class="primary" type="button" wire:click="openCreate">＋ New Inquiry</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            <div class="metrics ft-summary-card-grid ft-inquiry-summary-grid" aria-label="Inquiry summary filters">
                <?php if (isset($component)) { $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Created Today','value' => $metrics['createdToday'] ?? 0,'icon' => 'created','tone' => 'blue','caption' => 'New inquiries received','active' => $metricFilter === 'createdToday','wire:click' => 'setMetricFilter(\'createdToday\')','ariaPressed' => ''.e($metricFilter === 'createdToday' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Created Today','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metrics['createdToday'] ?? 0),'icon' => 'created','tone' => 'blue','caption' => 'New inquiries received','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter === 'createdToday'),'wire:click' => 'setMetricFilter(\'createdToday\')','aria-pressed' => ''.e($metricFilter === 'createdToday' ? 'true' : 'false').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $attributes = $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $component = $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Not Started','value' => $metrics['notStarted'] ?? 0,'icon' => 'not-started','tone' => 'slate','caption' => 'Waiting for first action','active' => $metricFilter === 'notStarted','wire:click' => 'setMetricFilter(\'notStarted\')','ariaPressed' => ''.e($metricFilter === 'notStarted' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Not Started','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metrics['notStarted'] ?? 0),'icon' => 'not-started','tone' => 'slate','caption' => 'Waiting for first action','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter === 'notStarted'),'wire:click' => 'setMetricFilter(\'notStarted\')','aria-pressed' => ''.e($metricFilter === 'notStarted' ? 'true' : 'false').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $attributes = $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $component = $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'In Progress','value' => $metrics['inProgress'] ?? 0,'icon' => 'in-progress','tone' => 'blue','caption' => 'Work currently underway','active' => $metricFilter === 'inProgress','wire:click' => 'setMetricFilter(\'inProgress\')','ariaPressed' => ''.e($metricFilter === 'inProgress' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'In Progress','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metrics['inProgress'] ?? 0),'icon' => 'in-progress','tone' => 'blue','caption' => 'Work currently underway','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter === 'inProgress'),'wire:click' => 'setMetricFilter(\'inProgress\')','aria-pressed' => ''.e($metricFilter === 'inProgress' ? 'true' : 'false').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $attributes = $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $component = $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Due This Week','value' => $metrics['dueThisWeek'] ?? 0,'icon' => 'due-week','tone' => 'amber','caption' => 'Required date this week','active' => $metricFilter === 'dueThisWeek','wire:click' => 'setMetricFilter(\'dueThisWeek\')','ariaPressed' => ''.e($metricFilter === 'dueThisWeek' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Due This Week','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metrics['dueThisWeek'] ?? 0),'icon' => 'due-week','tone' => 'amber','caption' => 'Required date this week','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter === 'dueThisWeek'),'wire:click' => 'setMetricFilter(\'dueThisWeek\')','aria-pressed' => ''.e($metricFilter === 'dueThisWeek' ? 'true' : 'false').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $attributes = $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $component = $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Completed','value' => $metrics['completed'] ?? 0,'icon' => 'completed','tone' => 'green','caption' => 'All completed inquiries','active' => $metricFilter === 'completed','wire:click' => 'setMetricFilter(\'completed\')','ariaPressed' => ''.e($metricFilter === 'completed' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Completed','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metrics['completed'] ?? 0),'icon' => 'completed','tone' => 'green','caption' => 'All completed inquiries','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter === 'completed'),'wire:click' => 'setMetricFilter(\'completed\')','aria-pressed' => ''.e($metricFilter === 'completed' ? 'true' : 'false').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $attributes = $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $component = $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Needs Attention','value' => $metrics['attention'] ?? 0,'icon' => 'attention','tone' => 'red','caption' => 'Blocked, overdue or unassigned','active' => $metricFilter === 'attention','wire:click' => 'setMetricFilter(\'attention\')','ariaPressed' => ''.e($metricFilter === 'attention' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Needs Attention','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metrics['attention'] ?? 0),'icon' => 'attention','tone' => 'red','caption' => 'Blocked, overdue or unassigned','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter === 'attention'),'wire:click' => 'setMetricFilter(\'attention\')','aria-pressed' => ''.e($metricFilter === 'attention' ? 'true' : 'false').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $attributes = $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542)): ?>
<?php $component = $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542; ?>
<?php unset($__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542); ?>
<?php endif; ?>
            </div>

            <div class="shell inquiry-list-v2">
                <div class="toolbar">
                    <?php if (isset($component)) { $__componentOriginalf6ee3670073e124e2f361de392ee6597 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf6ee3670073e124e2f361de392ee6597 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-input','data' => ['class' => 'search ft-inquiry-search-control','property' => 'search','value' => $search,'label' => 'Search inquiries','placeholder' => 'Search inquiry, title, client, task or assignee','debounce' => 350,'hideLabel' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'search ft-inquiry-search-control','property' => 'search','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($search),'label' => 'Search inquiries','placeholder' => 'Search inquiry, title, client, task or assignee','debounce' => 350,'hide-label' => true]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf6ee3670073e124e2f361de392ee6597)): ?>
<?php $attributes = $__attributesOriginalf6ee3670073e124e2f361de392ee6597; ?>
<?php unset($__attributesOriginalf6ee3670073e124e2f361de392ee6597); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf6ee3670073e124e2f361de392ee6597)): ?>
<?php $component = $__componentOriginalf6ee3670073e124e2f361de392ee6597; ?>
<?php unset($__componentOriginalf6ee3670073e124e2f361de392ee6597); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.filter-bar','data' => ['class' => 'filters inquiry-filter-controls','label' => 'Inquiry filters']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.filter-bar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'filters inquiry-filter-controls','label' => 'Inquiry filters']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                        <div class="ft-inquiry-filter-grid">
                            <div class="ft-inquiry-filter-group ft-inquiry-filter-group--quick" role="group" aria-label="Inquiry view">
                                <?php if (isset($component)) { $__componentOriginalc0177670b291fb3bce5b8c760c5c613c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc0177670b291fb3bce5b8c760c5c613c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.filter-chip','data' => ['class' => 'chip','active' => $metricFilter === '' && $inquiryToolbarIsClear,'wire:click' => 'setQuick(\'all\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.filter-chip'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'chip','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter === '' && $inquiryToolbarIsClear),'wire:click' => 'setQuick(\'all\')']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>
All <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc0177670b291fb3bce5b8c760c5c613c)): ?>
<?php $attributes = $__attributesOriginalc0177670b291fb3bce5b8c760c5c613c; ?>
<?php unset($__attributesOriginalc0177670b291fb3bce5b8c760c5c613c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc0177670b291fb3bce5b8c760c5c613c)): ?>
<?php $component = $__componentOriginalc0177670b291fb3bce5b8c760c5c613c; ?>
<?php unset($__componentOriginalc0177670b291fb3bce5b8c760c5c613c); ?>
<?php endif; ?>
                                <?php if (isset($component)) { $__componentOriginalc0177670b291fb3bce5b8c760c5c613c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc0177670b291fb3bce5b8c760c5c613c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.filter-chip','data' => ['class' => 'chip ft-inquiry-attention-filter','active' => $quick === 'attention','wire:click' => 'setQuick(\'attention\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.filter-chip'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'chip ft-inquiry-attention-filter','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($quick === 'attention'),'wire:click' => 'setQuick(\'attention\')']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                                    <span aria-hidden="true">⚠</span> Attention needed
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc0177670b291fb3bce5b8c760c5c613c)): ?>
<?php $attributes = $__attributesOriginalc0177670b291fb3bce5b8c760c5c613c; ?>
<?php unset($__attributesOriginalc0177670b291fb3bce5b8c760c5c613c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc0177670b291fb3bce5b8c760c5c613c)): ?>
<?php $component = $__componentOriginalc0177670b291fb3bce5b8c760c5c613c; ?>
<?php unset($__componentOriginalc0177670b291fb3bce5b8c760c5c613c); ?>
<?php endif; ?>
                            </div>

                            <div class="ft-inquiry-filter-field ft-inquiry-filter-field--status">
                                <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-inquiry-status-filter','label' => 'Task status','property' => 'pendingListStatus','value' => $pendingListStatus,'placeholder' => 'All task statuses','options' => collect($listStatusOptions)->map(fn ($statusOption) => ['id' => $statusOption, 'label' => $statusOption]),'hideLabel' => true,'fixedMenu' => true,'menuWidth' => 220]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-inquiry-status-filter','label' => 'Task status','property' => 'pendingListStatus','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pendingListStatus),'placeholder' => 'All task statuses','options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect($listStatusOptions)->map(fn ($statusOption) => ['id' => $statusOption, 'label' => $statusOption])),'hide-label' => true,'fixed-menu' => true,'menu-width' => 220]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $attributes = $__attributesOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__attributesOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $component = $__componentOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__componentOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
                            </div>

                            <div class="ft-inquiry-filter-field ft-inquiry-filter-field--client">
                                <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-inquiry-list-client-filter','label' => 'Client','property' => 'pendingListClient','type' => 'clients','context' => 'inquiries','action' => 'setInquiryPendingListFilter','value' => $pendingListClient,'placeholder' => 'All clients','selectedLabel' => $pendingListClientLabel ?: null,'initialOptions' => $listClientFilterOptions,'menuWidth' => 300,'fixedMenu' => true,'wire:key' => 'inquiry-list-client-filter-'.e($pendingListClient ?: 'all').'-'.e(substr(md5($pendingListClientLabel ?: 'all'), 0, 8)).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-inquiry-list-client-filter','label' => 'Client','property' => 'pendingListClient','type' => 'clients','context' => 'inquiries','action' => 'setInquiryPendingListFilter','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pendingListClient),'placeholder' => 'All clients','selected-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pendingListClientLabel ?: null),'initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listClientFilterOptions),'menu-width' => 300,'fixed-menu' => true,'wire:key' => 'inquiry-list-client-filter-'.e($pendingListClient ?: 'all').'-'.e(substr(md5($pendingListClientLabel ?: 'all'), 0, 8)).'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $attributes = $__attributesOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__attributesOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $component = $__componentOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__componentOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
                            </div>

                            <div class="ft-inquiry-filter-group ft-inquiry-filter-group--dates" role="group" aria-label="Created date filter">
                                <?php if (isset($component)) { $__componentOriginal6e32424d5df2e7bdda9ad721db0b2c8d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6e32424d5df2e7bdda9ad721db0b2c8d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.date-range','data' => ['class' => 'ft-inquiry-date-range','fromProperty' => 'pendingDateFrom','toProperty' => 'pendingDateTo','fromValue' => $pendingDateFrom,'toValue' => $pendingDateTo,'label' => 'Created date','fromLabel' => 'From','toLabel' => 'To']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.date-range'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-inquiry-date-range','from-property' => 'pendingDateFrom','to-property' => 'pendingDateTo','from-value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pendingDateFrom),'to-value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pendingDateTo),'label' => 'Created date','from-label' => 'From','to-label' => 'To']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6e32424d5df2e7bdda9ad721db0b2c8d)): ?>
<?php $attributes = $__attributesOriginal6e32424d5df2e7bdda9ad721db0b2c8d; ?>
<?php unset($__attributesOriginal6e32424d5df2e7bdda9ad721db0b2c8d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6e32424d5df2e7bdda9ad721db0b2c8d)): ?>
<?php $component = $__componentOriginal6e32424d5df2e7bdda9ad721db0b2c8d; ?>
<?php unset($__componentOriginal6e32424d5df2e7bdda9ad721db0b2c8d); ?>
<?php endif; ?>
                            </div>

                            <label class="completed-toggle ft-inquiry-hide-completed <?php echo e($pendingHideCompleted ? 'active' : ''); ?>">
                                <input type="checkbox" wire:model.live="pendingHideCompleted" aria-label="Hide completed inquiries">
                                <span class="completed-check" aria-hidden="true">✓</span>
                                <span>Hide completed</span>
                            </label>

                            <div class="ft-inquiry-filter-actions ft-inquiry-filter-group ft-inquiry-filter-group--actions" aria-label="Inquiry filter actions">
                                <button
                                    type="button"
                                    class="ft-inquiry-filter-action ft-inquiry-apply-filter"
                                    wire:click="applyFilters"
                                    wire:loading.attr="disabled"
                                    wire:target="applyFilters"
                                    <?php if(! $inquiryFilterDraftDirty): echo 'disabled'; endif; ?>
                                    aria-label="Apply inquiry filter"
                                >Apply filter</button>
                                <?php if (isset($component)) { $__componentOriginal6f21a7d61664ddbb53ab0f97f87e5266 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f21a7d61664ddbb53ab0f97f87e5266 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.filter-reset','data' => ['class' => 'chip ft-inquiry-filter-action ft-inquiry-clear-filter','action' => 'clearFilters','label' => 'Clear','icon' => '×','disabled' => ! $inquiryAnyFilterActive,'ariaLabel' => 'Clear active inquiry filter']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.filter-reset'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'chip ft-inquiry-filter-action ft-inquiry-clear-filter','action' => 'clearFilters','label' => 'Clear','icon' => '×','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(! $inquiryAnyFilterActive),'aria-label' => 'Clear active inquiry filter']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f21a7d61664ddbb53ab0f97f87e5266)): ?>
<?php $attributes = $__attributesOriginal6f21a7d61664ddbb53ab0f97f87e5266; ?>
<?php unset($__attributesOriginal6f21a7d61664ddbb53ab0f97f87e5266); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f21a7d61664ddbb53ab0f97f87e5266)): ?>
<?php $component = $__componentOriginal6f21a7d61664ddbb53ab0f97f87e5266; ?>
<?php unset($__componentOriginal6f21a7d61664ddbb53ab0f97f87e5266); ?>
<?php endif; ?>
                            </div>
                        </div>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed)): ?>
<?php $attributes = $__attributesOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed; ?>
<?php unset($__attributesOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed)): ?>
<?php $component = $__componentOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed; ?>
<?php unset($__componentOriginal14f469cfc51ebc3cb5d7fb0ffa2702ed); ?>
<?php endif; ?>
                </div>
                <div class="inquiry-list-table" role="region" aria-label="Inquiry list" tabindex="0">
                    <div class="listhead">
                        <div>Inquiry</div>
                        <div>Title</div>
                        <div>Client / Item</div>
                        <div>Priority</div>
                        <div>Due Date</div>
                        <div>Status</div>
                        <div>Flag</div>
                        <div>Current Task</div>
                        <div>Assignee</div>
                        <div>Task Status</div>
                        <div>Started At</div>
                        <div>Progress</div>
                        <div>Updated At</div>
                        <div aria-label="Actions"></div>
                    </div>
                    <div class="inquiry-list-body">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $inquiryRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <?php
                                $clientCode = strtoupper(trim((string) ($row['clientCode'] ?? '')));
                                $clientName = strtoupper(trim((string) ($row['client'] ?? '')));
                                $clientRowTone = ($clientCode === 'IID' || preg_match('/\bIID\b/i', $clientName))
                                    ? 'iid'
                                    : (($clientCode === 'NEP' || preg_match('/\bNEP\b/i', $clientName)) ? 'nep' : '');
                                $hasCompletedTask = (bool) ($row['hasCompletedTask'] ?? false);

                                // Client color is the visual baseline for an Inquiry:
                                // IID = light green, NEP = light blue. Once work advances,
                                // only a configured ACTIVE Task Pack task color may override
                                // that client tone. Do not fall back to Inquiry/Completed status
                                // color here, otherwise every fully-completed NEP Inquiry turns
                                // green and becomes visually indistinguishable from IID.
                                $taskDrivenRowColor = $hasCompletedTask
                                    ? \App\Support\MasterColor::normalize((string) ($row['activeTaskColor'] ?? ''))
                                    : null;
                                $useClientBaseTone = $clientRowTone !== '' && blank($taskDrivenRowColor);
                            ?>
                            <article
                                class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                                    'row',
                                    'ft-client-row-'.$clientRowTone => $useClientBaseTone,
                                    'has-task-color' => filled($taskDrivenRowColor),
                                ]); ?>"
                                style="<?php echo e(\App\Support\MasterColor::taskRowStyle($taskDrivenRowColor)); ?>"
                                <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'inquiry-list-'.e($row['id']).''; ?>wire:key="inquiry-list-<?php echo e($row['id']); ?>"
                            >
                                <div class="cell ft-inquiry-list-identity" data-label="Inquiry">
                                    <span class="ft-copyable-id-wrap ft-inquiry-list-code-wrap">
                                        <a class="id" href="<?php echo e(route('inquiries.index', ['open' => $row['id']])); ?>" wire:navigate><?php echo e($row['number']); ?></a>
                                        <button type="button" class="ft-copy-id-btn" title="Copy Inquiry ID" aria-label="Copy <?php echo e($row['number']); ?>" onclick="event.preventDefault(); event.stopPropagation(); navigator.clipboard?.writeText(<?php echo \Illuminate\Support\Js::from($row['number'])->toHtml() ?>); this.classList.add('copied'); setTimeout(()=>this.classList.remove('copied'),900)">⧉</button>
                                    </span>
                                    <span class="sub ft-inquiry-created-by" title="Created by <?php echo e($row['createdBy']); ?>">Created by <?php echo e($row['createdBy']); ?></span>
                                    <span class="sub ft-inquiry-created-at"><?php echo e($row['createdDate']); ?> · <?php echo e($row['createdTime']); ?></span>
                                </div>
                                <div class="cell ft-inquiry-list-title-cell" data-label="Title">
                                    <span class="title ft-inquiry-title-preview ft-inquiry-title-desktop" title="<?php echo e($row['title']); ?>"><?php echo e($row['titlePreview']); ?></span>
                                    <span class="title ft-inquiry-title-mobile" title="<?php echo e($row['title']); ?>"><?php echo e($row['title']); ?></span>
                                    <span class="sub ft-inquiry-mobile-created">Created by <?php echo e($row['createdBy']); ?> · <?php echo e($row['createdDate']); ?> · <?php echo e($row['createdTime']); ?></span>
                                </div>
                                <div class="ft-inquiry-mobile-separator ft-inquiry-mobile-separator-before-task" aria-hidden="true"></div>
                                <div class="cell ft-inquiry-list-client-cell" data-label="Client / Item">
                                    <span class="ft-client-name-with-logo"><?php if (isset($component)) { $__componentOriginalb7fdbb44e2f28c5f803966058155c072 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb7fdbb44e2f28c5f803966058155c072 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.client-logo','data' => ['name' => $row['client'],'src' => $row['clientLogoUrl'] ?? null,'size' => 24]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.client-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($row['client']),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($row['clientLogoUrl'] ?? null),'size' => 24]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb7fdbb44e2f28c5f803966058155c072)): ?>
<?php $attributes = $__attributesOriginalb7fdbb44e2f28c5f803966058155c072; ?>
<?php unset($__attributesOriginalb7fdbb44e2f28c5f803966058155c072); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb7fdbb44e2f28c5f803966058155c072)): ?>
<?php $component = $__componentOriginalb7fdbb44e2f28c5f803966058155c072; ?>
<?php unset($__componentOriginalb7fdbb44e2f28c5f803966058155c072); ?>
<?php endif; ?><span class="title"><?php echo e($row['client']); ?></span></span>
                                    <span class="sub">Contact: <?php echo e($row['clientContact'] ?: '—'); ?></span>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row['item']): ?><span class="sub"><?php echo e($row['item']); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <?php
                                    $rowTaskStatusColor = $masterData->displayColorFor('inquiry_task_status', $row['taskStatus']);
                                    $rowTaskFlagTone = match ($row['flag']) {
                                        'Requires attention', 'Overdue' => 'red',
                                        'Due Today' => 'amber',
                                        'No flag' => 'green',
                                        default => 'blue',
                                    };
                                    $rowInquiryPriorityColor = $masterData->displayColorFor('priority', $row['priority']);
                                    $rowInquiryStatusColor = $row['statusColor'] ?? null;
                                ?>
                                <div class="cell ft-inquiry-list-priority-cell" data-label="Priority"><span class="pill <?php echo e($rowInquiryPriorityColor ? 'ft-master-color' : $priorityTone($row['priority'])); ?>" style="<?php echo e(\App\Support\MasterColor::style($rowInquiryPriorityColor)); ?>"><?php echo e($row['priority']); ?></span></div>
                                <div class="cell ft-inquiry-list-due-cell" data-label="Due Date"><span class="title"><?php echo e($row['due']); ?></span></div>
                                <div class="cell ft-inquiry-list-status-cell" data-label="Status"><span class="pill <?php echo e($rowInquiryStatusColor ? 'ft-master-color' : $tone($row['status'])); ?>" style="<?php echo e(\App\Support\MasterColor::style($rowInquiryStatusColor)); ?>"><?php echo e($row['status']); ?></span></div>
                                <div class="cell ft-inquiry-list-flag-cell" data-label="Flag">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row['flag'] === 'No flag'): ?>
                                        <span class="ft-inquiry-no-flag">No flag</span>
                                    <?php else: ?>
                                        <span class="pill <?php echo e($rowTaskFlagTone); ?>"><?php echo e($row['flag']); ?></span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div class="cell ft-inquiry-list-task-cell" data-label="Current Task"><span class="title"><?php echo e($row['currentTask']); ?></span><span class="sub"><?php echo e($row['taskCaption']); ?></span></div>
                                <div class="cell ft-inquiry-list-assignee-cell" data-label="Assignee">
                                    <div class="ownerline">
                                        <?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['class' => 'ft-inquiry-assignee-avatar','name' => $row['assignee'],'src' => $row['assigneeAvatar'] ?? null,'size' => 34]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-inquiry-assignee-avatar','name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($row['assignee']),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($row['assigneeAvatar'] ?? null),'size' => 34]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2)): ?>
<?php $attributes = $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2; ?>
<?php unset($__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2)): ?>
<?php $component = $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2; ?>
<?php unset($__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2); ?>
<?php endif; ?>
                                        <span class="title" title="<?php echo e($row['assignee']); ?>"><?php echo e($row['assignee']); ?></span>
                                    </div>
                                </div>
                                <div class="cell ft-inquiry-list-task-status-cell" data-label="Task Status"><span class="pill <?php echo e($rowTaskStatusColor ? 'ft-master-color' : $tone($row['taskStatus'])); ?>" style="<?php echo e(\App\Support\MasterColor::style($rowTaskStatusColor)); ?>"><?php echo e($row['taskStatus']); ?></span></div>
                                <div class="ft-inquiry-mobile-separator ft-inquiry-mobile-separator-after-task" aria-hidden="true"></div>
                                <div class="cell ft-inquiry-list-started-cell" data-label="Started At">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row['hasStarted']): ?>
                                        <span class="title"><?php echo e($row['startedDate']); ?></span>
                                        <span class="sub"><?php echo e($row['startedTime']); ?></span>
                                    <?php else: ?>
                                        <span class="title ft-inquiry-not-started">Not Started</span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div class="cell ft-inquiry-list-progress-cell" data-label="Progress">
                                    <div class="ft-inquiry-list-progress">
                                        <div class="ft-inquiry-list-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo e($row['progressPercent']); ?>" aria-label="<?php echo e($row['progress']); ?> of <?php echo e($row['total']); ?> tasks completed"><span style="width:<?php echo e($row['progressPercent']); ?>%"></span></div>
                                        <b><?php echo e($row['progress']); ?>/<?php echo e($row['total']); ?></b>
                                    </div>
                                </div>
                                <div class="cell ft-inquiry-list-updated-cell" data-label="Updated At">
                                    <span class="title"><?php echo e($row['updatedDate']); ?></span>
                                    <span class="sub"><?php echo e($row['updatedTime']); ?></span>
                                </div>
                                <div class="ft-inquiry-mobile-separator ft-inquiry-mobile-separator-before-footer" aria-hidden="true"></div>
                                <div class="cell ft-inquiry-list-actions-cell" data-label="Actions" x-data="{ open: false }">
                                        <button
                                            class="ft-inquiry-row-action-trigger"
                                            type="button"
                                            :aria-expanded="open ? 'true' : 'false'"
                                            aria-haspopup="menu"
                                            aria-controls="inquiry-actions-<?php echo e($row['id']); ?>"
                                            aria-label="Actions for <?php echo e($row['number']); ?>"
                                            x-on:click.stop="
                                                const menu = $refs.menu;
                                                if (menu.matches(':popover-open')) { menu.hidePopover(); return; }
                                                const rect = $el.getBoundingClientRect();
                                                const menuWidth = 166;
                                                const menuHeight = <?php echo e($canDeleteInquiries ? 88 : 46); ?>;
                                                const edge = 10;
                                                const gap = 6;
                                                const left = Math.min(window.innerWidth - menuWidth - edge, Math.max(edge, rect.right - menuWidth));
                                                const openAbove = (window.innerHeight - rect.bottom) < (menuHeight + gap + edge) && rect.top > (menuHeight + gap + edge);
                                                const top = openAbove ? rect.top - menuHeight - gap : rect.bottom + gap;
                                                menu.style.left = `${left}px`;
                                                menu.style.top = `${Math.max(edge, top)}px`;
                                                menu.showPopover();
                                            "
                                        >⋮</button>
                                        <div
                                            id="inquiry-actions-<?php echo e($row['id']); ?>"
                                            class="ft-inquiry-row-action-menu"
                                            x-ref="menu"
                                            popover="auto"
                                            role="menu"
                                            x-on:toggle="open = $event.newState === 'open'"
                                        >
                                            <a
                                                class="ft-inquiry-row-action-view"
                                                href="<?php echo e(route('inquiries.index', ['open' => $row['id']])); ?>"
                                                role="menuitem"
                                                wire:navigate
                                                x-on:click="$refs.menu.hidePopover()"
                                                aria-label="View details for <?php echo e($row['number']); ?>"
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                                                <span>View</span>
                                            </a>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canDeleteInquiries): ?>
                                                <button class="ft-inquiry-row-action-danger" type="button" role="menuitem" x-on:click="$refs.menu.hidePopover()" wire:click="deleteInquiry(<?php echo e($row['id']); ?>)" wire:confirm="Delete <?php echo e($row['number']); ?>? This removes the inquiry from active lists. Any converted order remains available.">
                                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                                                    <span>Delete inquiry</span>
                                                </button>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                            </article>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                            <div class="ft-inquiry-list-empty">No matching inquiries.</div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
                <div class="footer">
                    <span>Showing <?php echo e($inquiryPaginator->firstItem() ?? 0); ?>–<?php echo e($inquiryPaginator->lastItem() ?? 0); ?> of <?php echo e($inquiryPaginator->total()); ?> inquiries</span>
                    <span>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($inquiryPaginator->lastPage() > 1): ?>
                            <button class="chip" type="button" wire:click="previousPage('inquiryPage')" <?php if($inquiryPaginator->onFirstPage()): echo 'disabled'; endif; ?>>←</button>
                            Page <?php echo e($inquiryPaginator->currentPage()); ?> of <?php echo e($inquiryPaginator->lastPage()); ?>

                            <button class="chip" type="button" wire:click="nextPage('inquiryPage')" <?php if(!$inquiryPaginator->hasMorePages()): echo 'disabled'; endif; ?>>→</button>
                        <?php else: ?>
                            Page 1 of 1
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </span>
                </div>
            </div>
        </section>

<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/inquiries/sections/list.blade.php ENDPATH**/ ?>