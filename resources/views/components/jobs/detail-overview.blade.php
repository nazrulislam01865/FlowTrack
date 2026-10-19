@props([
    'job',
    'taskStatuses' => collect(),
    'users' => collect(),
    'mentionUsers' => collect(),
    'priorities' => collect(),
    'shipmentUrgencyOptions' => collect(),
    'overviewPhaseId' => null,
    'orderDetailContext' => [],
    'detailSectionsReady' => [],
    'products' => collect(),
    'categories' => collect(),
    'showAddJobProductForm' => false,
    'jobProductSearch' => '',
    'jobProductSearchResults' => collect(),
    'jobProductSearchSuppliers' => collect(),
    'jobProductResultTotal' => 0,
    'jobProductShowAllResults' => false,
    'jobProductSelectedProduct' => null,
    'jobProductSelectedSupplier' => null,
    'jobProductCategory' => '',
    'jobProductQuantity' => '1000',
    'jobProductUnitPrice' => '0.00',
    'jobProductSupplierId' => null,
    'jobProductSupplierLabel' => '',
    'jobProductSupplierSkipped' => false,
    'jobProductSupplierLocked' => false,
    'showEditOrderProductModal' => false, 'editOrderProductItemId' => null, 'editOrderProductName' => '', 'editOrderProductCode' => '',
    'editOrderProductCategory' => '', 'editOrderProductSearch' => '', 'editOrderProductSearchResults' => collect(),
    'editOrderProductSearchSuppliers' => collect(), 'editOrderProductResultTotal' => 0, 'editOrderProductSelectedProduct' => null,
    'editOrderProductSelectedSupplier' => null, 'editOrderProductShowAllResults' => false,
    'editOrderProductSupplierId' => null, 'editOrderProductSupplierLabel' => '', 'editOrderProductQuantity' => '1',
    'editOrderProductUnitPrice' => '0.00', 'editOrderProductNotes' => '',
    'jobTaskSearch' => '',
    'activityTab' => 'all',
    'activityPage' => 1,
    'focusComment' => null,
    'jobDocumentUploads' => [],
    'overviewTaskDocumentModalTask' => null,
    'overviewTaskAvailableDocuments' => collect(),
    'showOverviewTaskDocumentModal' => false,
    'overviewTaskDocumentSource' => 'upload',
    'overviewTaskDocumentUpload' => null,
    'overviewTaskRevisionUpload' => [],
    'overviewTaskStagedUploads' => [],
    'overviewTaskStagedRevisionUploads' => [],
    'overviewTaskExistingDocumentId' => null,
    'overviewTaskArtworkRevision' => [],
    'overviewTaskRevisionDocumentIds' => [],
    'overviewTaskLinkFormTaskId' => null,
    'showAddOrderTaskForm' => false,
    'newOrderTaskAssigneeId' => null,
    'showOrderWorkflowActionModal' => false,
    'orderWorkflowActionTaskId' => null,
    'orderWorkflowActionStep' => 'main',
    'orderWorkflowActionPayload' => [],
    'orderWorkflowActionModalPreview' => [],
    'orderWorkflowActionAttachment' => null,
    'orderWorkflowActionRevisionComments' => [],
    'orderWorkflowActionRevisionAttachments' => [],
    'orderWorkflowEmailFallback' => false,
    'orderWorkflowEmailFallbackMessage' => '',
    'orderWorkflowEmailFallbackAttempts' => 0,
    'showShipmentModal' => false,
    'shipmentModalTaskId' => null,
    'shipmentEditingId' => null,
    'shipmentModalMode' => 'same_address',
    'shipmentForm' => [],
    'shipmentInlineTaskId' => null,
    'shipmentInlineEditingId' => null,
    'shipmentInlineAddressMode' => \App\Services\OrderShipmentService::MODE_SAME_ADDRESS,
    'shipmentInlineForm' => [],
    'showShipmentDetailsModal' => false,
    'shipmentDetailsId' => null,
])
@php
    // The parent shell uses the materialized workflow summary only. Full task
    // relations are owned by the isolated Workflow child below.
    $workflowSummary = (array) ($orderDetailContext['workflowSummary'] ?? []);
    $canEditJob = (bool) ($orderDetailContext['canEditJob'] ?? false);
    $canChangeOwner = (bool) ($orderDetailContext['canChangeOwner'] ?? false);
@endphp
<div class="ft-order-prototype-overview">
    <x-jobs.order-detail.summary :job="$job" :summary="$workflowSummary" />

    <div class="overview-grid ft-order-overview-grid">
        <x-jobs.order-detail.overview-card :job="$job" :can-edit-job="$canEditJob" :mention-users="$mentionUsers" />
        <div class="overview-side ft-order-overview-side">
            <x-jobs.order-detail.planning
                :job="$job"
                :can-edit-job="$canEditJob"
                :can-change-owner="$canChangeOwner"
                :shipment-urgency-options="$shipmentUrgencyOptions"
                :context="$orderDetailContext"
                :remote-area="$orderDetailContext['remoteArea'] ?? null"
            />
            @if((bool) config('flowtrack.order_tracking_enabled', false))
                <x-jobs.order-detail.tracking :job="$job" />
            @endif
            <x-jobs.order-detail.shipping :job="$job" :can-edit-job="$canEditJob" />
        </div>
    </div>

    <livewire:jobs.order-products-section
        :order-id="$job->id"
        :key="'order-products-section-'.$job->id"
    />


    <livewire:jobs.order-workflow-section
        :order-id="$job->id"
        :key="'order-workflow-section-'.$job->id"
    />

    <livewire:jobs.order-attachments-section
        :order-id="$job->id"
        :key="'order-attachments-section-'.$job->id"
    />

    <livewire:jobs.order-activity-section
        :order-id="$job->id"
        :focus-comment="$focusComment"
        :key="'order-activity-section-'.$job->id"
    />
</div>
