<div wire:key="order-workflow-workspace-{{ $orderId }}">
    @if(! $ready)
        <x-ui.progressive-section-loader
            section="workflow"
            method="loadWorkflowSection"
            key-prefix="order-workflow-isolated"
            context-type="order"
            :context-id="$orderId"
            queue-group="order-detail-{{ $orderId }}"
            :queue-priority="20"
            :settle-delay="180"
            :rows="5"
            message="Loading workflow and tasks when needed…"
            root-margin="120px 0px"
        />
    @else
        <x-jobs.order-detail.workflow
            :job="$job"
            :overview-phase-id="$overviewPhaseId"
            :task-statuses="$taskStatuses"
            :context="$context"
            :overview-task-link-form-task-id="$overviewTaskLinkFormTaskId"
            :show-shipment-modal="$showShipmentModal"
            :show-shipment-saved-address-picker="$showShipmentSavedAddressPicker"
            :shipment-modal-task-id="$shipmentModalTaskId"
            :shipment-editing-id="$shipmentEditingId"
            :shipment-modal-mode="$shipmentModalMode"
            :shipment-form="$shipmentForm"
            :shipment-inline-task-id="$shipmentInlineTaskId"
            :shipment-inline-editing-id="$shipmentInlineEditingId"
            :shipment-inline-address-mode="$shipmentInlineAddressMode"
            :shipment-inline-form="$shipmentInlineForm"
            :show-shipment-details-modal="$showShipmentDetailsModal"
            :shipment-details-id="$shipmentDetailsId"
        />

        @if($showOrderWorkflowActionModal && $orderWorkflowActionTaskId)
            @php
                $workflowActionTask = $job->tasks->firstWhere('id', (int) $orderWorkflowActionTaskId);
                $workflowActionModal = data_get($context, 'taskActionModals.'.(int) $orderWorkflowActionTaskId, []);
            @endphp
            @if($workflowActionTask)
                <x-jobs.order-detail.workflow-action-modal
                    :job="$job"
                    :task="$workflowActionTask"
                    :config="$workflowActionModal"
                    :step="$orderWorkflowActionStep"
                    :payload="$orderWorkflowActionPayload"
                    :modal-preview="$orderWorkflowActionModalPreview"
                    :attachment="$orderWorkflowActionAttachment"
                    :revision-comments="$orderWorkflowActionRevisionComments"
                    :revision-attachments="$orderWorkflowActionRevisionAttachments"
                    :mention-users="$mentionUsers"
                    :email-fallback="$orderWorkflowEmailFallback"
                    :email-fallback-message="$orderWorkflowEmailFallbackMessage"
                    :email-fallback-attempts="$orderWorkflowEmailFallbackAttempts"
                />
            @endif
        @endif

        @if($showOverviewTaskDocumentModal && $overviewTaskDocumentModalTask)
            <x-jobs.order-detail.document-modal
                :job="$job"
                :task="$overviewTaskDocumentModalTask"
                :available-documents="$overviewTaskAvailableDocuments"
                :source="$overviewTaskDocumentSource"
                :upload="$overviewTaskDocumentUpload"
                :revision-upload="$overviewTaskRevisionUpload"
                :staged-uploads="$overviewTaskStagedUploads"
                :staged-revision-uploads="$overviewTaskStagedRevisionUploads"
                :existing-document-id="$overviewTaskExistingDocumentId"
                :artwork-revision="$overviewTaskArtworkRevision"
                :revision-document-ids="$overviewTaskRevisionDocumentIds"
                :context="$context"
            />
        @endif
    @endif
</div>
