<div class="ft-orders-index-livewire-root">
    <x-orders.prototype-list
        :jobs="$jobs"
        :rows="$orderRows"
        :stages="$orderStages"
        :metrics="$orderMetrics"
        :selected-stage="$selectedStage"
        :stage-quick-filters="$stageQuickFilters"
        :search-filter="$search"
        :client-filter="$client"
        :owner-filter="$owner"
        :hold-on-filter="$holdOn"
        :phase-filter="$phase"
        :date-from="$dateFrom"
        :date-to="$dateTo"
        :metric-filter="$metricFilter"
        :stage-quick="$stageQuick"
        :stage-supplier="$stageSupplier"
        :stage-assignee="$stageAssignee"
        :stage-urgency="$stageUrgency"
        :stage-carrier="$stageCarrier"
        :stage-client="$stageClient"
        :client-filter-options="$clientFilterOptions"
        :owner-filter-options="$ownerFilterOptions"
        :stage-assignee-options="$stageAssigneeOptions"
        :stage-client-filter-options="$stageClientFilterOptions"
        :supplier-filter-options="$supplierFilterOptions"
        :shipment-urgency-options="$shipmentUrgencyOptions"
        :dashboard-scope="$dashboardScope"
        :dashboard-range-days="$dashboardRangeDays"
        :dashboard-team-label="$dashboardTeamLabel"
        :import-filter-id="$importBatchId"
        :import-filter-label="$importBatchLabel"
        wire:key="orders-prototype-v5"
    />

    {{-- CHANGE 2026-08-24:
         IMPORTANT: keep the list and its inline action modals inside one stable
         Livewire root. Previously the modal host was a second top-level sibling,
         so the action request completed but Livewire could not reliably morph
         the newly-rendered modal into the managed DOM. --}}
    @if($listActionOrder && $listActionTask)
        <div
            class="ft-order-prototype-detail ft-order-list-action-modal-host"
            wire:key="order-list-action-modal-host-{{ $listActionOrder->id }}-{{ $listActionTask->id }}"
        >
            @if($showOrderWorkflowActionModal)
                <x-jobs.order-detail.workflow-action-modal
                    :job="$listActionOrder"
                    :task="$listActionTask"
                    :config="$listActionWorkflowModal"
                    :step="$orderWorkflowActionStep"
                    :payload="$orderWorkflowActionPayload"
                    :modal-preview="$orderWorkflowActionModalPreview"
                    :attachment="$orderWorkflowActionAttachment"
                    :revision-comments="$orderWorkflowActionRevisionComments"
                    :revision-attachments="$orderWorkflowActionRevisionAttachments"
                    :email-fallback="$orderWorkflowEmailFallback"
                    :email-fallback-message="$orderWorkflowEmailFallbackMessage"
                    :email-fallback-attempts="$orderWorkflowEmailFallbackAttempts"
                />
            @endif

            @if($showOverviewTaskDocumentModal)
                <x-jobs.order-detail.document-modal
                    :job="$listActionOrder"
                    :task="$listActionTask"
                    :available-documents="$listActionAvailableDocuments"
                    :source="$overviewTaskDocumentSource"
                    :upload="$overviewTaskDocumentUpload"
                    :revision-upload="$overviewTaskRevisionUpload"
                    :staged-uploads="$overviewTaskStagedUploads"
                    :staged-revision-uploads="$overviewTaskStagedRevisionUploads"
                    :existing-document-id="$overviewTaskExistingDocumentId"
                    :artwork-revision="$listActionArtworkRevision"
                    :revision-document-ids="$overviewTaskRevisionDocumentIds"
                    :context="$listActionContext"
                />
            @endif
        </div>
    @endif
</div>
