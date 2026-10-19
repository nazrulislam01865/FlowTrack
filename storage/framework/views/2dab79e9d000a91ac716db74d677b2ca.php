<div class="ft-orders-index-livewire-root">
    <?php if (isset($component)) { $__componentOriginalb820d5fe85c4577f7cdb6df79296ced0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb820d5fe85c4577f7cdb6df79296ced0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.orders.prototype-list','data' => ['jobs' => $jobs,'rows' => $orderRows,'stages' => $orderStages,'metrics' => $orderMetrics,'selectedStage' => $selectedStage,'stageQuickFilters' => $stageQuickFilters,'searchFilter' => $search,'clientFilter' => $client,'ownerFilter' => $owner,'holdOnFilter' => $holdOn,'phaseFilter' => $phase,'dateFrom' => $dateFrom,'dateTo' => $dateTo,'metricFilter' => $metricFilter,'stageQuick' => $stageQuick,'stageSupplier' => $stageSupplier,'stageAssignee' => $stageAssignee,'stageUrgency' => $stageUrgency,'stageCarrier' => $stageCarrier,'stageClient' => $stageClient,'clientFilterOptions' => $clientFilterOptions,'ownerFilterOptions' => $ownerFilterOptions,'stageAssigneeOptions' => $stageAssigneeOptions,'stageClientFilterOptions' => $stageClientFilterOptions,'supplierFilterOptions' => $supplierFilterOptions,'shipmentUrgencyOptions' => $shipmentUrgencyOptions,'dashboardScope' => $dashboardScope,'dashboardRangeDays' => $dashboardRangeDays,'dashboardTeamLabel' => $dashboardTeamLabel,'importFilterId' => $importBatchId,'importFilterLabel' => $importBatchLabel,'wire:key' => 'orders-prototype-v5']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('orders.prototype-list'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['jobs' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($jobs),'rows' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderRows),'stages' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderStages),'metrics' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderMetrics),'selected-stage' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedStage),'stage-quick-filters' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageQuickFilters),'search-filter' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($search),'client-filter' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($client),'owner-filter' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($owner),'hold-on-filter' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($holdOn),'phase-filter' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($phase),'date-from' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dateFrom),'date-to' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dateTo),'metric-filter' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($metricFilter),'stage-quick' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageQuick),'stage-supplier' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageSupplier),'stage-assignee' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssignee),'stage-urgency' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageUrgency),'stage-carrier' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageCarrier),'stage-client' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageClient),'client-filter-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientFilterOptions),'owner-filter-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($ownerFilterOptions),'stage-assignee-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageAssigneeOptions),'stage-client-filter-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($stageClientFilterOptions),'supplier-filter-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($supplierFilterOptions),'shipment-urgency-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shipmentUrgencyOptions),'dashboard-scope' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dashboardScope),'dashboard-range-days' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dashboardRangeDays),'dashboard-team-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dashboardTeamLabel),'import-filter-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($importBatchId),'import-filter-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($importBatchLabel),'wire:key' => 'orders-prototype-v5']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb820d5fe85c4577f7cdb6df79296ced0)): ?>
<?php $attributes = $__attributesOriginalb820d5fe85c4577f7cdb6df79296ced0; ?>
<?php unset($__attributesOriginalb820d5fe85c4577f7cdb6df79296ced0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb820d5fe85c4577f7cdb6df79296ced0)): ?>
<?php $component = $__componentOriginalb820d5fe85c4577f7cdb6df79296ced0; ?>
<?php unset($__componentOriginalb820d5fe85c4577f7cdb6df79296ced0); ?>
<?php endif; ?>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($listActionOrder && $listActionTask): ?>
        <div
            class="ft-order-prototype-detail ft-order-list-action-modal-host"
            <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'order-list-action-modal-host-'.e($listActionOrder->id).'-'.e($listActionTask->id).''; ?>wire:key="order-list-action-modal-host-<?php echo e($listActionOrder->id); ?>-<?php echo e($listActionTask->id); ?>"
        >
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showOrderWorkflowActionModal): ?>
                <?php if (isset($component)) { $__componentOriginal8e43f3521a8e6328e588de4039a01fc1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8e43f3521a8e6328e588de4039a01fc1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.jobs.order-detail.workflow-action-modal','data' => ['job' => $listActionOrder,'task' => $listActionTask,'config' => $listActionWorkflowModal,'step' => $orderWorkflowActionStep,'payload' => $orderWorkflowActionPayload,'modalPreview' => $orderWorkflowActionModalPreview,'attachment' => $orderWorkflowActionAttachment,'revisionComments' => $orderWorkflowActionRevisionComments,'revisionAttachments' => $orderWorkflowActionRevisionAttachments,'emailFallback' => $orderWorkflowEmailFallback,'emailFallbackMessage' => $orderWorkflowEmailFallbackMessage,'emailFallbackAttempts' => $orderWorkflowEmailFallbackAttempts]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('jobs.order-detail.workflow-action-modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['job' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionOrder),'task' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionTask),'config' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionWorkflowModal),'step' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowActionStep),'payload' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowActionPayload),'modal-preview' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowActionModalPreview),'attachment' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowActionAttachment),'revision-comments' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowActionRevisionComments),'revision-attachments' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowActionRevisionAttachments),'email-fallback' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowEmailFallback),'email-fallback-message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowEmailFallbackMessage),'email-fallback-attempts' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderWorkflowEmailFallbackAttempts)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8e43f3521a8e6328e588de4039a01fc1)): ?>
<?php $attributes = $__attributesOriginal8e43f3521a8e6328e588de4039a01fc1; ?>
<?php unset($__attributesOriginal8e43f3521a8e6328e588de4039a01fc1); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8e43f3521a8e6328e588de4039a01fc1)): ?>
<?php $component = $__componentOriginal8e43f3521a8e6328e588de4039a01fc1; ?>
<?php unset($__componentOriginal8e43f3521a8e6328e588de4039a01fc1); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showOverviewTaskDocumentModal): ?>
                <?php if (isset($component)) { $__componentOriginal75144a7262080e4edb332b7df7a76a92 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal75144a7262080e4edb332b7df7a76a92 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.jobs.order-detail.document-modal','data' => ['job' => $listActionOrder,'task' => $listActionTask,'availableDocuments' => $listActionAvailableDocuments,'source' => $overviewTaskDocumentSource,'upload' => $overviewTaskDocumentUpload,'revisionUpload' => $overviewTaskRevisionUpload,'stagedUploads' => $overviewTaskStagedUploads,'stagedRevisionUploads' => $overviewTaskStagedRevisionUploads,'existingDocumentId' => $overviewTaskExistingDocumentId,'artworkRevision' => $listActionArtworkRevision,'revisionDocumentIds' => $overviewTaskRevisionDocumentIds,'context' => $listActionContext]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('jobs.order-detail.document-modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['job' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionOrder),'task' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionTask),'available-documents' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionAvailableDocuments),'source' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($overviewTaskDocumentSource),'upload' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($overviewTaskDocumentUpload),'revision-upload' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($overviewTaskRevisionUpload),'staged-uploads' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($overviewTaskStagedUploads),'staged-revision-uploads' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($overviewTaskStagedRevisionUploads),'existing-document-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($overviewTaskExistingDocumentId),'artwork-revision' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionArtworkRevision),'revision-document-ids' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($overviewTaskRevisionDocumentIds),'context' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($listActionContext)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal75144a7262080e4edb332b7df7a76a92)): ?>
<?php $attributes = $__attributesOriginal75144a7262080e4edb332b7df7a76a92; ?>
<?php unset($__attributesOriginal75144a7262080e4edb332b7df7a76a92); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal75144a7262080e4edb332b7df7a76a92)): ?>
<?php $component = $__componentOriginal75144a7262080e4edb332b7df7a76a92; ?>
<?php unset($__componentOriginal75144a7262080e4edb332b7df7a76a92); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/orders/index.blade.php ENDPATH**/ ?>