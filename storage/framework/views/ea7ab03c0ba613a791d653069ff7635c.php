    <?php
        $orderToolbarAnyFilterActive = filled($searchFilter)
            || filled($clientFilter)
            || filled($ownerFilter)
            || $holdOnFilter
            || filled($phaseFilter)
            || filled($dateFrom)
            || filled($dateTo)
            || filled($metricFilter)
            || $dashboardScope
            || (int) $importFilterId > 0
            || $selectedStageFiltersActive;
        $orderToolbarAllActive = ! $orderToolbarAnyFilterActive;
    ?>

    <section class="list-card orders-table-card" aria-label="Orders">
        <div class="filter-toolbar ft-order-filter-layout">
            <div class="ft-order-filter-search-row">
                <label class="search-box">
                    <span aria-hidden="true">⌕</span>
                    <input
                        type="search"
                        autocomplete="off"
                        placeholder="Search order, reference, client or product"
                        wire:model.live.debounce.700ms="search"
                        aria-label="Search orders"
                    >
                </label>
            </div>

            <div class="ft-order-filter-controls-row ft-order-filter-main-row" aria-label="Order filters">
                <button
                    type="button"
                    class="ft-order-filter-chip <?php echo e($orderToolbarAllActive ? 'active' : ''); ?>"
                    wire:click="clearFilters"
                    aria-pressed="<?php echo e($orderToolbarAllActive ? 'true' : 'false'); ?>"
                >All</button>

                <label class="ft-order-filter-chip ft-order-hold-filter <?php echo e($holdOnFilter ? 'active' : ''); ?>">
                    <input type="checkbox" wire:model.live="holdOn" <?php if($holdOnFilter): echo 'checked'; endif; ?>>
                    <span>Hold on</span>
                </label>

                <select class="ft-order-native-filter" wire:model.live="phase" aria-label="Workflow stage filter">
                    <option value="">All stages</option>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $stages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $stage): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <option value="<?php echo e(data_get($stage, 'id')); ?>"><?php echo e(data_get($stage, 'name')); ?></option>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </select>

                <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-order-v5-search-select ft-order-v5-client-filter','label' => 'Client','property' => 'client','type' => 'clients','context' => 'jobs','value' => $clientFilter,'placeholder' => 'All clients','initialOptions' => $clientFilterOptions,'hideLabel' => true,'fixedMenu' => true,'menuWidth' => 300,'wire:key' => 'order-v5-client-filter-'.e(filled($clientFilter) ? $clientFilter : 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-order-v5-search-select ft-order-v5-client-filter','label' => 'Client','property' => 'client','type' => 'clients','context' => 'jobs','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientFilter),'placeholder' => 'All clients','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientFilterOptions),'hide-label' => true,'fixed-menu' => true,'menu-width' => 300,'wire:key' => 'order-v5-client-filter-'.e(filled($clientFilter) ? $clientFilter : 'all').'']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-order-v5-search-select ft-order-v5-owner-filter','label' => 'Owner','property' => 'owner','type' => 'users','context' => 'order-list-user-filter','value' => $ownerFilter,'placeholder' => 'All owners','initialOptions' => $ownerFilterOptions,'showAvatar' => true,'searchPlaceholder' => 'Search user...','footerMessage' => 'All active FlowTrack users are available.','hideLabel' => true,'fixedMenu' => true,'menuWidth' => 300,'action' => 'applyOwnerFilter','wire:key' => 'order-v5-owner-filter-'.e(filled($ownerFilter) ? $ownerFilter : 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-order-v5-search-select ft-order-v5-owner-filter','label' => 'Owner','property' => 'owner','type' => 'users','context' => 'order-list-user-filter','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($ownerFilter),'placeholder' => 'All owners','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($ownerFilterOptions),'show-avatar' => true,'search-placeholder' => 'Search user...','footer-message' => 'All active FlowTrack users are available.','hide-label' => true,'fixed-menu' => true,'menu-width' => 300,'action' => 'applyOwnerFilter','wire:key' => 'order-v5-owner-filter-'.e(filled($ownerFilter) ? $ownerFilter : 'all').'']); ?>
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

                <?php if (isset($component)) { $__componentOriginal6e32424d5df2e7bdda9ad721db0b2c8d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6e32424d5df2e7bdda9ad721db0b2c8d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.date-range','data' => ['class' => 'ft-order-list-date-range','fromProperty' => 'dateFrom','toProperty' => 'dateTo','fromValue' => $dateFrom,'toValue' => $dateTo,'label' => ''.e($dashboardScope ? 'Activity date range' : 'Created date range').'','fromLabel' => 'From','toLabel' => 'To']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.date-range'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-order-list-date-range','from-property' => 'dateFrom','to-property' => 'dateTo','from-value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dateFrom),'to-value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dateTo),'label' => ''.e($dashboardScope ? 'Activity date range' : 'Created date range').'','from-label' => 'From','to-label' => 'To']); ?>
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

                <?php if (isset($component)) { $__componentOriginal6f21a7d61664ddbb53ab0f97f87e5266 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f21a7d61664ddbb53ab0f97f87e5266 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.filter-reset','data' => ['class' => 'chip ft-order-filter-reset ft-order-clear-filter','action' => 'clearFilters','label' => 'Clear','icon' => '×','disabled' => ! $orderToolbarAnyFilterActive,'ariaLabel' => 'Clear active order filters']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.filter-reset'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'chip ft-order-filter-reset ft-order-clear-filter','action' => 'clearFilters','label' => 'Clear','icon' => '×','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(! $orderToolbarAnyFilterActive),'aria-label' => 'Clear active order filters']); ?>
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

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($dashboardScope): ?>
            <div class="import-filter-line" role="status">
                <b>Dashboard period:</b>
                <?php echo e($dashboardRangeDays === 1 ? 'Today' : ($dashboardRangeDays > 0 ? $dashboardRangeDays.' days' : $dateFrom.' to '.$dateTo)); ?>

                <span>· Orders active in this stage and updated during the selected period</span>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($dashboardTeamLabel)): ?><span>· Team: <?php echo e($dashboardTeamLabel); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedStage): ?>
            <div class="stage-inline-controls">
                <span class="stage-inline-label"><?php echo e($stageName); ?></span>
                <div class="stage-inline-quick" role="group" aria-label="<?php echo e($stageName); ?> status filters">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $stageQuickFilters; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <?php
                            $quickColor = (string) data_get($stageQuickMeta, $key.'.color', '#0F8F7C');
                        ?>
                        <button
                            type="button"
                            class="stage-inline-chip <?php echo e($stageQuick === $key ? 'active' : ''); ?>"
                            style="--quick-color:<?php echo e($quickColor); ?>"
                            wire:click="setStageQuick('<?php echo e($key); ?>')"
                            aria-pressed="<?php echo e($stageQuick === $key ? 'true' : 'false'); ?>"
                        >
                            <span class="stage-inline-check" aria-hidden="true">✓</span>
                            <span><?php echo e($label); ?></span>
                        </button>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </div>
                <span class="stage-view-note">Row colors match <?php echo e(strtolower($stageName)); ?> status</span>
                <div class="stage-inline-selects">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(in_array($sequence, [1,2,3,4], true)): ?>
                        <div class="stage-filter-field">
                            <span class="stage-filter-caption">Supplier</span>
                            <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-order-v5-stage-search-select ft-order-v5-supplier-filter','label' => 'Supplier','property' => 'stageSupplier','type' => 'suppliers','context' => 'order-list','value' => $stageSupplier,'placeholder' => 'All suppliers','initialOptions' => $supplierFilterOptions,'searchPlaceholder' => 'Search supplier...','footerMessage' => 'Type 2 characters to search suppliers.','hideLabel' => true,'fixedMenu' => true,'menuWidth' => 320,'wire:key' => 'order-v5-stage-supplier-'.e($sequence).'-'.e(filled($stageSupplier) ? $stageSupplier : 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-order-v5-stage-search-select ft-order-v5-supplier-filter','label' => 'Supplier','property' => 'stageSupplier','type' => 'suppliers','context' => 'order-list','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageSupplier),'placeholder' => 'All suppliers','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($supplierFilterOptions),'search-placeholder' => 'Search supplier...','footer-message' => 'Type 2 characters to search suppliers.','hide-label' => true,'fixed-menu' => true,'menu-width' => 320,'wire:key' => 'order-v5-stage-supplier-'.e($sequence).'-'.e(filled($stageSupplier) ? $stageSupplier : 'all').'']); ?>
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

                        <?php
                            $stageAssigneeLabel = $sequence === 1 ? 'Order owner' : $stageName.' assignee';
                            $stageAssigneePlaceholder = $sequence === 1
                                ? 'All order owners'
                                : 'All '.strtolower($stageName).' assignees';
                        ?>
                        <div class="stage-filter-field stage-filter-field-user">
                            <span class="stage-filter-caption"><?php echo e($stageAssigneeLabel); ?></span>
                            <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-order-v5-stage-search-select ft-order-v5-stage-assignee-filter','label' => $stageAssigneeLabel,'property' => 'stageAssignee','type' => 'users','context' => 'order-list-user-filter','value' => $stageAssignee,'placeholder' => $stageAssigneePlaceholder,'initialOptions' => $stageAssigneeOptions,'showAvatar' => true,'searchPlaceholder' => 'Search user...','footerMessage' => 'All active FlowTrack users are available.','hideLabel' => true,'fixedMenu' => true,'menuWidth' => 340,'wire:key' => 'order-v5-stage-assignee-'.e($sequence).'-'.e(filled($stageAssignee) ? $stageAssignee : 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-order-v5-stage-search-select ft-order-v5-stage-assignee-filter','label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssigneeLabel),'property' => 'stageAssignee','type' => 'users','context' => 'order-list-user-filter','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssignee),'placeholder' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssigneePlaceholder),'initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssigneeOptions),'show-avatar' => true,'search-placeholder' => 'Search user...','footer-message' => 'All active FlowTrack users are available.','hide-label' => true,'fixed-menu' => true,'menu-width' => 340,'wire:key' => 'order-v5-stage-assignee-'.e($sequence).'-'.e(filled($stageAssignee) ? $stageAssignee : 'all').'']); ?>
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
                    <?php elseif($sequence === 5): ?>
                        <label class="stage-filter-field">
                            <span class="stage-filter-caption">Shipping urgency</span>
                            <select wire:model.live="stageUrgency" aria-label="Shipping urgency filter">
                                <option value="">All shipping urgency</option>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $shipmentUrgencyOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                                    <option value="<?php echo e($option->id); ?>"><?php echo e($option->name); ?></option>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                            </select>
                        </label>
                        <label class="stage-filter-field">
                            <span class="stage-filter-caption">Carrier</span>
                            <select wire:model.live="stageCarrier" aria-label="Carrier filter">
                                <option value="">All carrier</option>
                                <option>UPS</option><option>FedEx</option><option>DHL</option><option>Other</option>
                            </select>
                        </label>
                    <?php elseif(in_array($sequence, [6,7], true)): ?>
                        <div class="stage-filter-field">
                            <span class="stage-filter-caption">Client</span>
                            <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-order-v5-stage-search-select ft-order-v5-stage-client-filter','label' => 'Client','property' => 'stageClient','type' => 'clients','context' => 'jobs','value' => $stageClient,'placeholder' => 'All clients','initialOptions' => $stageClientFilterOptions,'searchPlaceholder' => 'Search client...','hideLabel' => true,'fixedMenu' => true,'menuWidth' => 320,'wire:key' => 'order-v5-stage-client-'.e($sequence).'-'.e(filled($stageClient) ? $stageClient : 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-order-v5-stage-search-select ft-order-v5-stage-client-filter','label' => 'Client','property' => 'stageClient','type' => 'clients','context' => 'jobs','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageClient),'placeholder' => 'All clients','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageClientFilterOptions),'search-placeholder' => 'Search client...','hide-label' => true,'fixed-menu' => true,'menu-width' => 320,'wire:key' => 'order-v5-stage-client-'.e($sequence).'-'.e(filled($stageClient) ? $stageClient : 'all').'']); ?>
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

                        <div class="stage-filter-field stage-filter-field-user">
                            <span class="stage-filter-caption">Finance owner</span>
                            <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-order-v5-stage-search-select ft-order-v5-stage-assignee-filter','label' => 'Finance owner','property' => 'stageAssignee','type' => 'users','context' => 'order-list-user-filter','value' => $stageAssignee,'placeholder' => 'All finance owners','initialOptions' => $stageAssigneeOptions,'showAvatar' => true,'searchPlaceholder' => 'Search user...','footerMessage' => 'All active FlowTrack users are available.','hideLabel' => true,'fixedMenu' => true,'menuWidth' => 340,'wire:key' => 'order-v5-finance-owner-'.e($sequence).'-'.e(filled($stageAssignee) ? $stageAssignee : 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-order-v5-stage-search-select ft-order-v5-stage-assignee-filter','label' => 'Finance owner','property' => 'stageAssignee','type' => 'users','context' => 'order-list-user-filter','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssignee),'placeholder' => 'All finance owners','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssigneeOptions),'show-avatar' => true,'search-placeholder' => 'Search user...','footer-message' => 'All active FlowTrack users are available.','hide-label' => true,'fixed-menu' => true,'menu-width' => 340,'wire:key' => 'order-v5-finance-owner-'.e($sequence).'-'.e(filled($stageAssignee) ? $stageAssignee : 'all').'']); ?>
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
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($importFilterId): ?>
            <div class="import-filter-line"><b>Imported batch:</b> <?php echo e($importFilterLabel ?: '#'.$importFilterId); ?></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <div class="active-filter-line">
            <span><?php echo e(number_format($jobs->total())); ?> <?php echo e(\Illuminate\Support\Str::plural('order', $jobs->total())); ?></span>
            <span><?php echo e($selectedStage ? $stageName.' filter'.($dashboardScope ? ' · dashboard period applied' : ' · same Orders page') : 'Showing all workflow stages'); ?></span>
        </div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/orders/list/filters.blade.php ENDPATH**/ ?>