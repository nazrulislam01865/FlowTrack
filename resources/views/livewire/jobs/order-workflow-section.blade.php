<div wire:key="order-workflow-workspace-{{ $orderId }}" data-flowtrack-order-section="workflow" data-flowtrack-section-ready="{{ $ready ? '1' : '0' }}">
    <x-jobs.order-detail.artwork-address-book-notice :status="$artworkAddressBookNotice" />
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
        @if((bool) config('flowtrack.workflow_display_query_enabled', false)
            && (bool) config('flowtrack.order_three_level_loading_enabled', false))
            <span hidden aria-hidden="true"
                data-workflow-stage-prefetch
                data-order-id="{{ $orderId }}"
                data-prefetch-url="{{ route('orders.workflow.stage-prefetch', ['orderId' => $orderId]) }}"
                data-active-phase="{{ (int) ($overviewPhaseId ?: $job->workflow_phase_id) }}"></span>
        @endif
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
                    :shipment-methods="$context['shipmentMethods'] ?? collect()"
                    :shipment-urgencies="$context['shipmentUrgencies'] ?? collect()"
                    :artwork-recipient-emails="$artworkRecipientEmails"
                    :artwork-saved-recipient-suggestions="$artworkSavedRecipientSuggestions"
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

{{-- This request is intentionally outside Livewire's request/update pipeline.
     No background response can morph the active stage, reset form values, or
     authorize an action. It starts only after the active stage has painted. --}}
@script
<script>
(() => {
    const root = $wire.$el;
    const marker = root?.querySelector('[data-workflow-stage-prefetch]');
    if (!marker || !window.fetch) return;

    const orderId = Number(marker.dataset.orderId);
    const activePhase = Number(marker.dataset.activePhase);
    const endpoint = new URL(marker.dataset.prefetchUrl, window.location.origin);
    endpoint.searchParams.set('active_phase_id', String(activePhase));
    const controller = new AbortController();
    let started = false;
    const start = () => {
        if (started || !root.isConnected || document.visibilityState === 'hidden') return;
        started = true;
        fetch(endpoint.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' },
            signal: controller.signal,
        }).then(response => response.ok ? response.json() : null)
          .then(data => {
              if (!data || !root.isConnected || Number(data.order_id) !== orderId) return;
              // A passive, bounded read model. Interactive task rows always
              // re-read authoritative records on phase selection/action.
              root.__flowtrackPrefetchedStages = data.phases;
              root.dispatchEvent(new CustomEvent('flowtrack-stages-prefetched', {
                  detail: { orderId, phaseCount: data.phases.length },
              }));
          }).catch(() => { /* Prefetch failure cannot block current work. */ });
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(start, { timeout: 1700 });
    } else {
        window.setTimeout(start, 450);
    }
    document.addEventListener('visibilitychange', start, { once: true });
    // A navigation destroys the old workspace: never retain task data from
    // another order or render a response into a different Livewire instance.
    root.addEventListener('livewire:navigating', () => controller.abort(), { once: true });
})();
</script>
@endscript
