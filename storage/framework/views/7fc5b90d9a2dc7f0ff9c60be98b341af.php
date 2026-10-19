<?php
    $selected = $detail['client'] ?? null;
    $activeJobs = $detail['active'] ?? collect();
    $attentionTasks = $detail['tasks'] ?? collect();
    $clientListFieldFilterActive = collect([$search, $country, $manager, $outstanding, $archivedDate, $createdBy])
        ->contains(fn ($value) => trim((string) $value) !== '');
    $clientAnyFilterActive = $clientListFieldFilterActive || (!$showArchived && $quick !== 'all');
?>
<div class="ft-clients-reference">
    <div class="ft-clients-page-head">
        <div>
            <h1><?php echo e($showArchived ? 'Archived Clients' : 'Clients'); ?></h1>
            <p><?php echo e($showArchived ? 'Review inactive clients and restore them when needed.' : 'Monitor client Orders, task delivery, account activity and outstanding balances.'); ?></p>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canModule('clients','create')): ?>
            <button class="ft-clients-new ft-dashboard-action-match" type="button" wire:click="openCreate"><span class="ft-dashboard-action-match-icon">+</span>New Client</button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?><div class="flash success"><?php echo e(session('success')); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="ft-client-list-modes" role="tablist" aria-label="Client status">
        <button type="button" wire:click="showActiveClients" class="<?php echo e(!$showArchived ? 'active' : ''); ?>">Active Clients <span><?php echo e($summary['clients']); ?></span></button>
        <button type="button" wire:click="showArchivedClients" class="<?php echo e($showArchived ? 'active' : ''); ?>">Archived Clients <span><?php echo e($summary['archived']); ?></span></button>
    </div>

    <div class="ft-clients-layout ft-clients-layout-full">
        <section class="ft-clients-main">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$showArchived): ?>
            <div class="ft-clients-metrics ft-summary-card-grid ft-summary-card-grid-4 ft-summary-card-grid--4" aria-label="Client summary filters">
                <?php if (isset($component)) { $__componentOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3d9f2b72d2e6c6ddaf41740549ad542 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Total clients','value' => $summary['clients'] ?? 0,'icon' => 'clients','tone' => 'blue','caption' => 'Active client records','active' => $quick === 'all' && ! $clientListFieldFilterActive,'wire:click' => 'setQuick(\'all\')','ariaPressed' => ''.e($quick === 'all' && ! $clientListFieldFilterActive ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Total clients','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($summary['clients'] ?? 0),'icon' => 'clients','tone' => 'blue','caption' => 'Active client records','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($quick === 'all' && ! $clientListFieldFilterActive),'wire:click' => 'setQuick(\'all\')','aria-pressed' => ''.e($quick === 'all' && ! $clientListFieldFilterActive ? 'true' : 'false').'']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Active Jobs','value' => $summary['active_jobs'] ?? 0,'icon' => 'orders','tone' => 'green','caption' => 'Open client work','active' => $quick === 'active_jobs','wire:click' => 'setQuick(\'active_jobs\')','ariaPressed' => ''.e($quick === 'active_jobs' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Active Jobs','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($summary['active_jobs'] ?? 0),'icon' => 'orders','tone' => 'green','caption' => 'Open client work','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($quick === 'active_jobs'),'wire:click' => 'setQuick(\'active_jobs\')','aria-pressed' => ''.e($quick === 'active_jobs' ? 'true' : 'false').'']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Needs attention','value' => $summary['attention'] ?? 0,'icon' => 'attention','tone' => 'red','caption' => 'Client work requiring action','active' => $quick === 'attention','wire:click' => 'setQuick(\'attention\')','ariaPressed' => ''.e($quick === 'attention' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Needs attention','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($summary['attention'] ?? 0),'icon' => 'attention','tone' => 'red','caption' => 'Client work requiring action','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($quick === 'attention'),'wire:click' => 'setQuick(\'attention\')','aria-pressed' => ''.e($quick === 'attention' ? 'true' : 'false').'']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.summary-card','data' => ['label' => 'Outstanding','value' => $summary['outstanding'] ?? 0,'displayValue' => '$'.number_format((float) ($summary['outstanding'] ?? 0), 0),'icon' => 'money','tone' => 'purple','caption' => 'Total outstanding balance','active' => $quick === 'outstanding','wire:click' => 'setQuick(\'outstanding\')','ariaPressed' => ''.e($quick === 'outstanding' ? 'true' : 'false').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.summary-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Outstanding','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($summary['outstanding'] ?? 0),'display-value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('$'.number_format((float) ($summary['outstanding'] ?? 0), 0)),'icon' => 'money','tone' => 'purple','caption' => 'Total outstanding balance','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($quick === 'outstanding'),'wire:click' => 'setQuick(\'outstanding\')','aria-pressed' => ''.e($quick === 'outstanding' ? 'true' : 'false').'']); ?>
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
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showArchived): ?>
                <div class="ft-list-filter-shell is-archived ft-archived-prototype-toolbar">
                    <div class="ft-list-filter-grid ft-client-filter-grid ft-archived-prototype-filter-grid">
                        <?php if (isset($component)) { $__componentOriginalf6ee3670073e124e2f361de392ee6597 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf6ee3670073e124e2f361de392ee6597 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-input','data' => ['property' => 'search','value' => $search,'placeholder' => 'Search archived clients']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['property' => 'search','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($search),'placeholder' => 'Search archived clients']); ?>
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
                        <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['label' => 'Archived date','property' => 'archivedDate','value' => $archivedDate,'placeholder' => 'All dates','options' => collect([
                                ['id'=>'7d','label'=>'Last 7 days'],
                                ['id'=>'30d','label'=>'Last 30 days'],
                                ['id'=>'90d','label'=>'Last 90 days'],
                                ['id'=>'year','label'=>'This year'],
                            ])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Archived date','property' => 'archivedDate','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($archivedDate),'placeholder' => 'All dates','options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect([
                                ['id'=>'7d','label'=>'Last 7 days'],
                                ['id'=>'30d','label'=>'Last 30 days'],
                                ['id'=>'90d','label'=>'Last 90 days'],
                                ['id'=>'year','label'=>'This year'],
                            ]))]); ?>
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
                        <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['label' => 'Created by','property' => 'createdBy','type' => 'users','context' => 'clients','value' => $createdBy,'placeholder' => 'Anyone','initialOptions' => $createdByFilterOptions]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Created by','property' => 'createdBy','type' => 'users','context' => 'clients','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($createdBy),'placeholder' => 'Anyone','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($createdByFilterOptions)]); ?>
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
                        <button type="button" class="ft-client-clear-filter" wire:click="clearFilters" <?php if(! $clientAnyFilterActive): echo 'disabled'; endif; ?>>× Clear filter</button>
                    </div>
                    <?php
                        $chips = collect();
                        if($search) $chips->push(['key'=>'search','label'=>'Search: '.$search]);
                        if($archivedDate) $chips->push(['key'=>'archivedDate','label'=>'Archived: '.(['7d'=>'Last 7 days','30d'=>'Last 30 days','90d'=>'Last 90 days','year'=>'This year'][$archivedDate] ?? $archivedDate)]);
                        if($createdBy) $chips->push(['key'=>'createdBy','label'=>'Created by: '.(collect($createdByFilterOptions)->firstWhere('id',(int)$createdBy)['label'] ?? 'Selected')]);
                    ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($chips->isNotEmpty()): ?>
                        <div class="ft-list-active-row">
                            <div class="ft-list-filter-chips"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $chips; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $chip): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><span class="ft-list-filter-chip"><?php echo e($chip['label']); ?><button type="button" wire:click="clearFilter('<?php echo e($chip['key']); ?>')">×</button></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ft-list-filter-shell">
                    <div class="ft-list-filter-grid ft-client-filter-grid">
                        <?php if (isset($component)) { $__componentOriginalf6ee3670073e124e2f361de392ee6597 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf6ee3670073e124e2f361de392ee6597 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-input','data' => ['property' => 'search','value' => $search,'placeholder' => 'Client, Job ID, country or manager…']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['property' => 'search','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($search),'placeholder' => 'Client, Job ID, country or manager…']); ?>
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
                        <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['label' => 'Account manager','property' => 'manager','type' => 'users','context' => 'clients','value' => $manager,'placeholder' => 'Anyone','initialOptions' => $managerFilterOptions]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Account manager','property' => 'manager','type' => 'users','context' => 'clients','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($manager),'placeholder' => 'Anyone','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($managerFilterOptions)]); ?>
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
                        <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['label' => 'Country','property' => 'country','type' => 'countries','context' => 'clients','value' => $country,'placeholder' => 'All countries','initialOptions' => $countryFilterOptions]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Country','property' => 'country','type' => 'countries','context' => 'clients','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($country),'placeholder' => 'All countries','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($countryFilterOptions)]); ?>
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
                        <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['label' => 'Outstanding','property' => 'outstanding','value' => $outstanding,'placeholder' => 'Any balance','options' => collect([['id'=>'positive','label'=>'Has balance'],['id'=>'high','label'=>'$10,000+'],['id'=>'zero','label'=>'No balance']])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Outstanding','property' => 'outstanding','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($outstanding),'placeholder' => 'Any balance','options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect([['id'=>'positive','label'=>'Has balance'],['id'=>'high','label'=>'$10,000+'],['id'=>'zero','label'=>'No balance']]))]); ?>
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
                        <button type="button" class="ft-client-clear-filter" wire:click="clearFilters" <?php if(! $clientAnyFilterActive): echo 'disabled'; endif; ?>>× Clear filter</button>
                    </div>
                    <?php
                        $chips = collect();
                        if($search) $chips->push(['key'=>'search','label'=>'Search: '.$search]);
                        if($manager) $chips->push(['key'=>'manager','label'=>'Manager: '.(collect($managerFilterOptions)->firstWhere('id',(int)$manager)['label'] ?? 'Selected')]);
                        if($country) $chips->push(['key'=>'country','label'=>'Country: '.$country]);
                        if($outstanding) $chips->push(['key'=>'outstanding','label'=>'Outstanding: '.(['positive'=>'Has balance','high'=>'$10,000+','zero'=>'No balance'][$outstanding] ?? $outstanding)]);
                    ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($chips->isNotEmpty()): ?>
                        <div class="ft-list-active-row">
                            <div class="ft-list-filter-chips"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $chips; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $chip): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><span class="ft-list-filter-chip"><?php echo e($chip['label']); ?><button type="button" wire:click="clearFilter('<?php echo e($chip['key']); ?>')">×</button></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


            <div class="ft-client-list-card">
                <div class="ft-client-table-scroll ft-results-refreshable" wire:loading.class="is-refreshing" wire:target="search,manager,country,outstanding,quick,archivedDate,createdBy">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showArchived): ?>
                    <table class="ft-client-table ft-archived-client-table">
                        <thead><tr><th>Client</th><th>Contact</th><th>Status</th><th>Archived</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $clients; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $clientRow): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <tr <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'archived-client-row-'.e($clientRow->id).''; ?>wire:key="archived-client-row-<?php echo e($clientRow->id); ?>">
                                <td data-label="Client">
                                    <div class="ft-client-identity"><?php if (isset($component)) { $__componentOriginalb7fdbb44e2f28c5f803966058155c072 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb7fdbb44e2f28c5f803966058155c072 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.client-logo','data' => ['client' => $clientRow,'name' => $clientRow->name,'size' => 34,'archived' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.client-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['client' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientRow),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientRow->name),'size' => 34,'archived' => true]); ?>
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
<?php endif; ?><span><b><?php echo e($clientRow->name); ?></b><small><?php echo e($clientRow->code); ?></small></span></div>
                                </td>
                                <td data-label="Contact"><span class="ft-archived-contact"><?php echo e($clientRow->email ?: ($clientRow->contact_name ?: '—')); ?></span></td>
                                <td data-label="Status"><span class="ft-archived-status">Archived</span></td>
                                <td data-label="Archived"><span class="ft-archived-date"><?php echo e(($clientRow->archived_at ?? $clientRow->updated_at)?->format('M j, Y') ?? '—'); ?></span></td>
                                <td data-label="Actions">
                                    <div class="ft-archived-actions">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canModule('clients','delete')): ?>
                                            <button type="button" class="ft-archive-restore" wire:click="restoreClient(<?php echo e($clientRow->id); ?>)" wire:confirm="Restore this client to the active client list?">Restore</button>
                                            <button type="button" class="ft-archive-delete" wire:click="openPermanentDeleteClient(<?php echo e($clientRow->id); ?>)">Delete</button>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                            <tr><td colspan="5" class="ft-client-empty">No archived clients match the selected filters.</td></tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <table class="ft-client-table">
                        <thead><tr><th>Client</th><th>Account manager</th><th>Jobs</th><th>Tasks</th><th>Next delivery</th><th>Outstanding</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $clients; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $clientRow): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <tr
                                <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'client-row-'.e($clientRow->id).''; ?>wire:key="client-row-<?php echo e($clientRow->id); ?>"
                                class="<?php echo e($showClientPreview && (int)$selectedClientId === (int)$clientRow->id ? 'selected' : ''); ?>"
                                wire:click="viewClient(<?php echo e($clientRow->id); ?>)"
                                wire:keydown.enter="viewClient(<?php echo e($clientRow->id); ?>)"
                                wire:keydown.space.prevent="viewClient(<?php echo e($clientRow->id); ?>)"
                                tabindex="0"
                                aria-label="Open client <?php echo e($clientRow->name); ?>"
                            >
                                <td data-label="Client"><div class="ft-client-identity"><?php if (isset($component)) { $__componentOriginalb7fdbb44e2f28c5f803966058155c072 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb7fdbb44e2f28c5f803966058155c072 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.client-logo','data' => ['client' => $clientRow,'name' => $clientRow->name,'size' => 34]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.client-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['client' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientRow),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientRow->name),'size' => 34]); ?>
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
<?php endif; ?><span><b><?php echo e($clientRow->name); ?></b><small><?php echo e($clientRow->country ?: '—'); ?></small></span></div></td>
                                <td data-label="Account manager"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($clientRow->accountManager): ?><div class="ft-client-person"><?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['user' => $clientRow->accountManager,'name' => $clientRow->accountManager->name,'size' => 26]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientRow->accountManager),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientRow->accountManager->name),'size' => 26]); ?>
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
<?php endif; ?><span><?php echo e($clientRow->accountManager->name); ?></span></div><?php else: ?><span class="muted">Unassigned</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></td>
                                <td data-label="Jobs"><b><?php echo e($clientRow->active_jobs_count); ?> / <?php echo e($clientRow->total_jobs_count); ?></b> active<div class="ft-mini-progress"><span style="width:<?php echo e($clientRow->total_jobs_count ? min(100,round(($clientRow->active_jobs_count/$clientRow->total_jobs_count)*100)) : 0); ?>%"></span></div></td>
                                <td data-label="Tasks">
                                    <b><?php echo e($clientRow->open_tasks_count); ?></b> open
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((int) $clientRow->overdue_tasks_count > 0): ?>
                                        <small class="ft-text-red"><?php echo e($clientRow->overdue_tasks_count); ?> overdue</small>
                                    <?php elseif((int) $clientRow->blocked_tasks_count > 0): ?>
                                        <small class="ft-text-purple"><?php echo e($clientRow->blocked_tasks_count); ?> blocked</small>
                                    <?php else: ?>
                                        <small class="ft-text-green">0 overdue</small>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </td>
                                <td data-label="Next delivery"><?php echo e($clientRow->next_delivery_at ? \Carbon\Carbon::parse($clientRow->next_delivery_at)->format('M j') : '—'); ?></td>
                                <td data-label="Outstanding"><b>$<?php echo e(number_format($clientRow->outstanding_balance,0)); ?></b></td>
                                <td data-label="Updated"><?php echo e($clientRow->updated_at?->diffForHumans(short:true)); ?></td>
                                <td
                                    data-label="Actions"
                                    class="ft-client-action-cell"
                                    x-data="window.FlowTrack.ui.floatingActionMenu()"
                                    x-on:resize.window="positionMenu()"
                                    x-on:scroll.window="positionMenu()"
                                >
                                    <button x-ref="trigger" type="button" class="ft-client-more" wire:click.stop="toggleClientMenu(<?php echo e($clientRow->id); ?>)" aria-label="Client actions">⋮</button>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($actionMenuClientId === (int)$clientRow->id): ?>
                                        <div
                                            x-ref="menu"
                                            x-cloak
                                            x-show="menuStyle !== ''"
                                            x-init="$nextTick(() => positionMenu())"
                                            x-bind:style="menuStyle"
                                            class="ft-client-action-menu"
                                            x-on:click.stop
                                        >
                                            <button type="button" wire:click.stop="viewClient(<?php echo e($clientRow->id); ?>)">View client</button>
                                            <?php
                                                $access = app(\App\Services\AccessControlService::class);
                                                $rowCanEdit = $access->isAdministrator(auth()->user()) || $access->canEditAll(auth()->user(),'clients') || ($access->canEditOwn(auth()->user(),'clients') && (int)$clientRow->account_manager_id === (int)auth()->id());
                                            ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$showArchived && $rowCanEdit): ?><button type="button" wire:click.stop="editClient(<?php echo e($clientRow->id); ?>)">Edit client</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canModule('clients','delete')): ?>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showArchived): ?>
                                                    <button type="button" wire:click.stop="restoreClient(<?php echo e($clientRow->id); ?>)" wire:confirm="Restore this client to the active client list?">Restore client</button>
                                                <?php else: ?>
                                                    <button type="button" class="danger" wire:click.stop="deleteClient(<?php echo e($clientRow->id); ?>)" wire:confirm="Archive this client? Existing history will be preserved and the client can be restored later.">Archive client</button>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </td>
                            </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                            <tr><td colspan="8" class="ft-client-empty"><?php echo e($showArchived ? 'No archived clients match the selected filters.' : 'No clients match the selected filters.'); ?></td></tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="ft-client-pagination">
                    <span>Showing <?php echo e($clients->firstItem() ?? 0); ?>–<?php echo e($clients->lastItem() ?? 0); ?> of <?php echo e($clients->total()); ?> <?php echo e($showArchived ? 'archived ' : ''); ?>clients</span>
                    <div><label>Rows per page:</label><select wire:model.live="perPage"><option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option></select><button type="button" wire:click="previousPage" <?php if($clients->onFirstPage()): echo 'disabled'; endif; ?>>Previous</button><span>Page <?php echo e($clients->currentPage()); ?> of <?php echo e(max(1,$clients->lastPage())); ?></span><button type="button" wire:click="nextPage" <?php if(!$clients->hasMorePages()): echo 'disabled'; endif; ?>>Next →</button></div>
                </div>
            </div>
        </section>

    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($deleteCandidate): ?>
        <div
            class="ft-client-delete-layer"
            <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'delete-archived-client-dialog-'.e($deleteCandidate->id).''; ?>wire:key="delete-archived-client-dialog-<?php echo e($deleteCandidate->id); ?>"
            x-data="{ acknowledged: false }"
            x-on:keydown.escape.window="$wire.closePermanentDeleteClient()"
        >
            <section class="ft-client-delete-dialog" role="alertdialog" aria-modal="true" aria-labelledby="ft-client-delete-title" aria-describedby="ft-client-delete-description">
                <header class="ft-client-delete-head">
                    <div class="ft-client-delete-title-wrap">
                        <span class="ft-client-delete-warning" aria-hidden="true">!</span>
                        <div>
                            <h2 id="ft-client-delete-title">Permanently delete client?</h2>
                            <p id="ft-client-delete-description">This action cannot be undone.</p>
                        </div>
                    </div>
                    <button type="button" class="ft-client-delete-close" wire:click="closePermanentDeleteClient" aria-label="Close">×</button>
                </header>

                <div class="ft-client-delete-summary">
                    <?php if (isset($component)) { $__componentOriginalb7fdbb44e2f28c5f803966058155c072 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb7fdbb44e2f28c5f803966058155c072 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.client-logo','data' => ['client' => $deleteCandidate,'name' => $deleteCandidate->name,'size' => 32,'archived' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.client-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['client' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($deleteCandidate),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($deleteCandidate->name),'size' => 32,'archived' => true]); ?>
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
<?php endif; ?>
                    <span>
                        <strong><?php echo e($deleteCandidate->name); ?></strong>
                        <small><?php echo e($deleteCandidate->code); ?> · Archived <?php echo e(($deleteCandidate->archived_at ?? $deleteCandidate->updated_at)?->format('M j, Y') ?? '—'); ?></small>
                    </span>
                </div>

                <div class="ft-client-delete-danger-note">
                    <strong>Permanent deletion</strong><br>
                    The client profile, contacts and stored client information will be permanently removed. Historical linked records must not be cascade-deleted.
                </div>

                <label class="ft-client-delete-check">
                    <input type="checkbox" x-model="acknowledged" wire:model="deleteArchivedClientConfirmed">
                    <span>I understand that this client cannot be recovered after deletion.</span>
                </label>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['deleteArchivedClientConfirmed'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="ft-client-delete-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                <footer class="ft-client-delete-actions">
                    <button type="button" class="ft-client-delete-cancel" wire:click="closePermanentDeleteClient">Cancel</button>
                    <button
                        type="button"
                        class="ft-client-delete-confirm"
                        x-bind:disabled="!acknowledged"
                        wire:click="permanentlyDeleteClient"
                        wire:loading.attr="disabled"
                        wire:target="permanentlyDeleteClient"
                    >
                        <span wire:loading.remove wire:target="permanentlyDeleteClient">Permanently delete</span>
                        <span wire:loading wire:target="permanentlyDeleteClient">Deleting…</span>
                    </button>
                </footer>
            </section>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/clients/sections/list.blade.php ENDPATH**/ ?>