<?php

namespace App\Livewire\Jobs\Concerns;

use App\Exceptions\EmailDeliveryException;
use App\Queries\Orders\VisibleOrderQuery;
use App\Models\FlowJob;
use App\Models\Task;
use App\Services\AccessControlService;
use App\Services\Orders\OrderInvoiceWorkflowEmailService;
use App\Services\Orders\OrderWorkflowEmailService;
use App\Services\TaskService;
use App\Support\AttachmentUpload;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Json;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderWorkflow
{
    public function toggleJobPhase(int $phaseId): void
    {
        if (in_array($phaseId, $this->expandedPhaseIds, true)) {
            $this->expandedPhaseIds = array_values(array_filter($this->expandedPhaseIds, fn ($id) => (int) $id !== $phaseId));
        } else {
            $this->expandedPhaseIds[] = $phaseId;
            $this->expandedPhaseIds = array_values(array_unique(array_map('intval', $this->expandedPhaseIds)));
        }
    }

    public function expandAllJobPhases(): void
    {
        if (!$this->selectedJobId) return;

        $job = app(VisibleOrderQuery::class)->scoped(
            auth()->user(),
            $this->selectedJobId,
            ['workflow.phases:id,workflow_id'],
            ['id', 'workflow_id'],
        );

        $this->expandedPhaseIds = $job->workflow->phases->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function collapseAllJobPhases(): void { $this->expandedPhaseIds = []; }

    public function openOrderWorkflowAction(int $taskId): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.client', 'job.items', 'job.phase', 'setupTemplate', 'documents', 'links'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);

        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        $key = $workflowActions->automationKey($task);
        $editingCompletedShipmentInformation = $key === 'SHIP_CONFIRM_INFO'
            && \App\Support\OrderDetailPresenter::isCompletedTask($task);

        // A slow Livewire request can be followed by a duplicate click from the
        // old DOM after the first request has already completed this task. When
        // that completion also advanced the Order to the next stage, running the
        // stale request through the normal sequencing guard would incorrectly
        // surface "This task is locked until its workflow stage is active."
        // Treat an already-completed action as an idempotent replay and simply
        // resync the component to the authoritative current phase.
        if (! $editingCompletedShipmentInformation && $this->ignoreCompletedWorkflowReplay($task)) {
            return;
        }

        if ($editingCompletedShipmentInformation) {
            abort_if(strcasecmp((string) $task->job?->status, 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');
        } else {
            app(\App\Services\OrderTaskSequenceService::class)->assertStatusActionable($task);
        }

        $hasEvidence = $task->documents->isNotEmpty() || $task->links->isNotEmpty();
        $descriptor = $workflowActions->descriptor($task, $hasEvidence);
        // Only file-backed prototype actions open the document picker. Other
        // actions (courier label, invoice preparation, QC, etc.) have their own
        // purpose-built interactions and must not be replaced by the generic
        // document modal just because the Task Pack also records a document
        // category. Completion requirements are still validated server-side.
        if (($descriptor['interaction'] ?? $descriptor['type'] ?? null) === 'document') {
            $this->openOverviewTaskDocumentModal($taskId);
            return;
        }

        if (($descriptor['interaction'] ?? null) === 'direct') {
            $decision = ($descriptor['key'] ?? null) === 'SHIP_LABEL' ? 'generate' : 'confirm';
            $workflowActions->perform($task, auth()->user(), $decision);
            $this->dispatchTaskAssigneeSync($task->id);
            $this->syncOverviewWorkflowSelectionToCurrentPhase();
            session()->flash('success', 'Order workflow updated.');
            return;
        }

        if ($key === 'BILL_SEND') {
            // Older prepared Billing tasks stored only activity metadata. Make
            // sure Task 6.2 always opens with the canonical generated PDF.
            app(OrderInvoiceWorkflowEmailService::class)
                ->ensurePreparedInvoice($task->job, auth()->user());
        }

        $this->orderWorkflowActionTaskId = $taskId;
        $this->orderWorkflowActionComment = '';
        $this->orderWorkflowActionAttachment = null;
        $this->orderWorkflowActionRevisionComments = [];
        $this->orderWorkflowActionRevisionAttachments = [];
        $this->orderWorkflowActionStep = 'main';
        $this->orderWorkflowActionPayload = $workflowActions->initialPayload($task, $task->job);
        $this->orderWorkflowActionModalPreview = app(\App\Services\Orders\OrderWorkflowActionModalPreviewService::class)
            ->snapshot($task, $this->orderWorkflowActionPayload, false);
        $this->resetOrderWorkflowEmailFallbackState();

        if (in_array($descriptor['key'] ?? null, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
            $failure = $this->orderWorkflowEmailFallbackMarker($task);
            if ($failure) {
                $this->showOrderWorkflowEmailFallback($descriptor['key'], (int) ($failure['attempts'] ?? 3));
            }
        }

        $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionAttachment', 'orderWorkflowActionRevisionComments', 'orderWorkflowActionRevisionAttachments', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
        $this->showOrderWorkflowActionModal = true;
    }

    public function closeOrderWorkflowAction(): void
    {
        $this->showOrderWorkflowActionModal = false;
        $this->orderWorkflowActionTaskId = null;
        $this->orderWorkflowActionComment = '';
        $this->orderWorkflowActionAttachment = null;
        $this->orderWorkflowActionRevisionComments = [];
        $this->orderWorkflowActionRevisionAttachments = [];
        $this->orderWorkflowActionStep = 'main';
        $this->orderWorkflowActionPayload = [];
        $this->orderWorkflowActionModalPreview = [];
        $this->resetOrderWorkflowEmailFallbackState();
        $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionAttachment', 'orderWorkflowActionRevisionComments', 'orderWorkflowActionRevisionAttachments', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
    }

    /**
     * Complete the exact email body preview after the modal shell is visible.
     * Only email/invoice workflow actions call this via wire:init.
     */
    public function loadOrderWorkflowActionEmailPreview(): void
    {
        if (! $this->showOrderWorkflowActionModal || ! $this->selectedJobId || ! $this->orderWorkflowActionTaskId) {
            return;
        }

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['setupTemplate', 'job.client', 'job.owner', 'job.coordinator', 'job.items'])
            ->where('flow_job_id', (int) $this->selectedJobId)
            ->find((int) $this->orderWorkflowActionTaskId);

        if (! $task) {
            return;
        }

        $previewService = app(\App\Services\Orders\OrderWorkflowActionModalPreviewService::class);
        if (! $previewService->requiresEmailPreview($this->orderWorkflowActionModalPreview)) {
            return;
        }

        $this->orderWorkflowActionModalPreview = $previewService
            ->snapshot($task, $this->orderWorkflowActionPayload, true);
    }

    /**
     * Preserve the existing live email preview behavior without regenerating
     * expensive email/PDF preview data for unrelated modal field updates.
     */
    public function updatedOrderWorkflowActionPayload(mixed $value, mixed $key = null): void
    {
        if (! in_array((string) $key, ['to_email', 'cc_emails', 'to_emails', 'customer_comment'], true)) {
            return;
        }

        $this->loadOrderWorkflowActionEmailPreview();
    }

    public function confirmShipmentDetailsWithoutChanges(int $taskId): void
    {
        $task = $this->shipmentActionTask($taskId, 'SHIP_CONFIRM_INFO');
        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        $payload = $workflowActions->initialPayload($task, $task->job);

        try {
            $workflowActions->perform($task, auth()->user(), 'confirm', null, $payload);
        } catch (ValidationException $exception) {
            $this->orderWorkflowActionTaskId = $taskId;
            $this->orderWorkflowActionStep = 'main';
            $this->orderWorkflowActionPayload = $payload;
            $this->showOrderWorkflowActionModal = true;
            foreach ($exception->errors() as $field => $messages) {
                foreach ((array) $messages as $message) $this->addError($field, $message);
            }
            return;
        }

        $this->dispatchTaskAssigneeSync($task->id);
        $this->refreshShipmentWorkflowSelection();
        session()->flash('success', 'Shipment details confirmed. Tracking setup is now available.');
    }

    public function generateShipmentCourierLabel(int $taskId, string $carrier, string $trackingNumber): void
    {
        $task = $this->shipmentActionTask($taskId, 'SHIP_LABEL');
        $carrier = trim($carrier);
        $trackingNumber = trim($trackingNumber);
        $this->resetValidation('shipmentLabel');

        if ($carrier === '' || $trackingNumber === '') {
            $this->addError('shipmentLabel', 'Select a courier and enter the tracking number first.');
            return;
        }

        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        $payload = $workflowActions->initialPayload($task, $task->job);
        $payload['carrier'] = $carrier;
        $payload['tracking_number'] = $trackingNumber;
        $workflowActions->perform($task, auth()->user(), 'generate', null, $payload);

        $this->dispatchTaskAssigneeSync($task->id);
        $this->refreshShipmentWorkflowSelection();
        session()->flash('success', 'Courier label generated. Review and print it to continue.');
    }

    public function completeShipmentTrackingTask(int $taskId, string $carrier, string $trackingNumber): void
    {
        $task = $this->shipmentActionTask($taskId, 'SHIP_LABEL');
        $carrier = trim($carrier);
        $trackingNumber = trim($trackingNumber);
        $this->resetValidation('shipmentLabel');

        if ($carrier === '' || $trackingNumber === '') {
            $this->addError('shipmentLabel', 'Select a courier and enter the tracking number first.');
            return;
        }

        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        $payload = $workflowActions->initialPayload($task, $task->job);
        $payload['carrier'] = $carrier;
        $payload['tracking_number'] = $trackingNumber;
        $workflowActions->perform($task, auth()->user(), 'complete', null, $payload);

        $this->dispatchTaskAssigneeSync($task->id);
        $this->refreshShipmentWorkflowSelection();
        session()->flash('success', 'Tracking details saved. Dispatch shipment is now available.');
    }

    public function updateShipmentTrackingDetails(int $taskId, string $carrier, string $trackingNumber): void
    {
        $task = $this->shipmentEditableTask($taskId, 'SHIP_LABEL');
        abort_unless(\App\Support\OrderDetailPresenter::isCompletedTask($task), 422, 'Complete the tracking task before editing it.');

        $this->resetValidation('shipmentLabel');

        try {
            app(\App\Services\OrderWorkflowActionService::class)
                ->updateCompletedShipmentTracking($task, auth()->user(), $carrier, $trackingNumber);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ((array) $messages as $message) $this->addError($field, $message);
            }
            return;
        }

        $this->refreshShipmentWorkflowSelection();
        $this->dispatch(
            'shipment-tracking-updated',
            taskId: $taskId,
            carrier: trim($carrier),
            tracking: trim($trackingNumber),
        );
        session()->flash('success', 'Courier and tracking details updated.');
    }

    public function dispatchShipment(int $taskId): void
    {
        $task = $this->shipmentActionTask($taskId, 'SHIP_PACKAGE');
        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        $payload = $workflowActions->initialPayload($task, $task->job);
        $this->resetValidation('shipmentDispatch');

        if (trim((string) ($payload['carrier'] ?? '')) === '' || trim((string) ($payload['tracking_number'] ?? '')) === '') {
            $this->addError('shipmentDispatch', 'Generate the courier label with a tracking number before dispatching the shipment.');
            return;
        }

        $workflowActions->perform($task, auth()->user(), 'confirm', null, $payload);
        $this->dispatchTaskAssigneeSync($task->id);
        $this->refreshShipmentWorkflowSelection();
        session()->flash('success', 'Shipment marked as dispatched.');
    }

    public function selectShipmentContact(string $selection): void
    {
        abort_unless($this->selectedJobId && $this->orderWorkflowActionTaskId, 422);
        $options = collect($this->orderWorkflowActionPayload['contact_options'] ?? []);
        $option = $options->firstWhere('value', $selection);
        if (! $option) return;

        $this->orderWorkflowActionPayload['contact_selection'] = $selection;
        $this->orderWorkflowActionPayload['contact_name'] = (string) ($option['name'] ?? '');
        $this->orderWorkflowActionPayload['contact_type'] = (string) ($option['contact_type'] ?? 'middle_client');
        $this->orderWorkflowActionPayload['phone_country_code'] = (string) ($option['country_code'] ?? '');
        $this->orderWorkflowActionPayload['phone_number'] = (string) ($option['phone'] ?? '');
        $this->orderWorkflowActionPayload['recipient'] = (string) ($option['name'] ?? '');
        $this->orderWorkflowActionPayload['contact'] = trim((string) (($option['country_code'] ?? '').' '.($option['phone'] ?? '')));
        $this->resetValidation([
            'orderWorkflowActionPayload.contact_name',
            'orderWorkflowActionPayload.phone_country_code',
            'orderWorkflowActionPayload.phone_number',
        ]);
    }

    public function useShipmentSavedAddress(string $selection = ''): void
    {
        abort_unless($this->selectedJobId && $this->orderWorkflowActionTaskId, 422);
        $options = collect($this->orderWorkflowActionPayload['address_options'] ?? []);
        if ($options->isEmpty()) return;

        $option = $selection !== '' ? $options->firstWhere('value', $selection) : null;
        $option ??= $options->firstWhere('is_default', true) ?: $options->first();
        if (! $option) return;

        $this->orderWorkflowActionPayload['address_selection'] = (string) ($option['value'] ?? '');
        $this->orderWorkflowActionPayload['address'] = (string) ($option['address'] ?? '');
        $this->orderWorkflowActionPayload['city'] = (string) ($option['city'] ?? '');
        $this->orderWorkflowActionPayload['state'] = (string) ($option['state'] ?? '');
        $this->orderWorkflowActionPayload['country'] = (string) ($option['country'] ?? '');
        $this->orderWorkflowActionPayload['postal_code'] = (string) ($option['postal_code'] ?? '');
        $this->resetValidation([
            'orderWorkflowActionPayload.address',
            'orderWorkflowActionPayload.country',
            'orderWorkflowActionPayload.postal_code',
        ]);
    }

    public function resetShipmentActionDetails(): void
    {
        abort_unless($this->selectedJobId && $this->orderWorkflowActionTaskId, 422);
        $task = $this->shipmentEditableTask((int) $this->orderWorkflowActionTaskId, 'SHIP_CONFIRM_INFO');
        $this->orderWorkflowActionPayload = app(\App\Services\OrderWorkflowActionService::class)->initialPayload($task, $task->job);
        $this->resetValidation('orderWorkflowActionPayload');
    }

    /**
     * Save the inline Production monitor form shown by the PROD_ISSUE Task Pack
     * item. The task identity, ordering, required/optional behavior, assignee,
     * document requirements and phase progression remain owned by Task Pack /
     * Order workflow services; this method only adapts the prototype inline UI
     * to the existing workflow action contract.
     */
    public function saveProductionMonitorTask(int $taskId, string $supplierDeliveryDate, string $productionIssueNote = ''): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.client', 'job.items', 'job.phase', 'setupTemplate'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);

        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        abort_unless($workflowActions->automationKey($task) === 'PROD_ISSUE', 422, 'This task does not use the Production monitor form.');

        $errorPrefix = 'productionMonitor.'.$taskId;
        $this->resetValidation([
            $errorPrefix.'.supplier_delivery_date',
            $errorPrefix.'.production_issue_note',
            $errorPrefix.'.task',
        ]);

        try {
            $workflowActions->perform(
                $task,
                auth()->user(),
                'confirm',
                $productionIssueNote,
                ['supplier_delivery_date' => $supplierDeliveryDate],
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $target = match ($field) {
                    'orderWorkflowActionPayload.supplier_delivery_date' => $errorPrefix.'.supplier_delivery_date',
                    'orderWorkflowActionComment' => $errorPrefix.'.production_issue_note',
                    default => $errorPrefix.'.task',
                };

                foreach ((array) $messages as $message) $this->addError($target, $message);
            }
            return;
        }

        $this->dispatchTaskAssigneeSync($task->id);
        $this->syncOverviewWorkflowSelectionToCurrentPhase();
        session()->flash('success', 'Production task completed.');
    }

    public function clearProductionMonitorErrors(int $taskId): void
    {
        $prefix = 'productionMonitor.'.$taskId;
        $this->resetValidation([
            $prefix.'.supplier_delivery_date',
            $prefix.'.production_issue_note',
            $prefix.'.task',
        ]);
    }

    #[Json]
    public function updateCompletedProductionMonitorSupplierDate(int $taskId, mixed $supplierDeliveryDate): array
    {
        $savedValue = '';
        $savedDisplay = '';

        $result = $this->persistInlineEdit('supplier delivery date', function () use ($taskId, $supplierDeliveryDate, &$savedValue, &$savedDisplay) {
            $task = $this->editableCompletedProductionMonitorTask($taskId);
            $date = trim((string) $supplierDeliveryDate);

            validator(
                ['supplier_delivery_date' => $date],
                ['supplier_delivery_date' => ['nullable', 'date_format:Y-m-d']],
                [],
                ['supplier_delivery_date' => 'supplier delivery date'],
            )->validate();

            $parsedDate = null;
            if ($date !== '') {
                $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                    throw ValidationException::withMessages([
                        'supplier_delivery_date' => 'Enter a valid supplier delivery date.',
                    ]);
                }
            }

            $job = $task->job;
            $currentDetails = $this->productionMonitorSavedDetailsForTask($job, (int) $task->id);
            $note = (string) ($currentDetails['production_issue_note'] ?? '');

            $job->update(['supplier_delivery_date' => $date !== '' ? $date : null]);
            $this->recordCompletedProductionMonitorEdit($task, $date, $note, 'supplier_delivery_date');

            $savedValue = $date;
            $savedDisplay = $parsedDate?->format('d/m/Y') ?? '—';
        });

        if (($result['ok'] ?? false) === true) {
            $result['value'] = $savedValue;
            $result['display'] = $savedDisplay;
        }

        return $result;
    }

    #[Json]
    public function updateCompletedProductionMonitorIssueNote(int $taskId, mixed $productionIssueNote): array
    {
        $savedValue = '';
        $savedDisplay = '—';

        $result = $this->persistInlineEdit('production issue note', function () use ($taskId, $productionIssueNote, &$savedValue, &$savedDisplay) {
            $task = $this->editableCompletedProductionMonitorTask($taskId);
            $note = trim((string) $productionIssueNote);

            validator(
                ['production_issue_note' => $note],
                ['production_issue_note' => ['nullable', 'string', 'max:10000']],
                [],
                ['production_issue_note' => 'production issue note'],
            )->validate();

            $job = $task->job;
            $currentDetails = $this->productionMonitorSavedDetailsForTask($job, (int) $task->id);
            $date = trim((string) ($currentDetails['supplier_delivery_date'] ?? ''));
            if ($date === '' && $job->supplier_delivery_date) {
                $date = $job->supplier_delivery_date->format('Y-m-d');
            }

            $this->recordCompletedProductionMonitorEdit($task, $date, $note, 'production_issue_note');

            $savedValue = $note;
            $savedDisplay = $note !== '' ? $note : '—';
        });

        if (($result['ok'] ?? false) === true) {
            $result['value'] = $savedValue;
            $result['display'] = $savedDisplay;
        }

        return $result;
    }

    private function editableCompletedProductionMonitorTask(int $taskId): Task
    {
        abort_unless($this->selectedJobId, 422);

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job', 'setupTemplate'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);

        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(app(\App\Services\OrderWorkflowActionService::class)->automationKey($task) === 'PROD_ISSUE', 422, 'This task does not use Production monitor details.');
        abort_unless(\App\Support\OrderDetailPresenter::isCompletedTask($task), 422, 'Complete this task before editing the saved Production monitor details.');
        abort_if(strcasecmp((string) ($task->job?->status ?? ''), 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);

        return $task;
    }

    private function productionMonitorSavedDetailsForTask(FlowJob $job, int $taskId): array
    {
        $activity = $job->activities()
            ->where('event', 'job.supplier_delivery_date_set')
            ->latest('id')
            ->get()
            ->first(function ($activity) use ($taskId): bool {
                $meta = is_array($activity->meta) ? $activity->meta : [];
                $activityTaskId = (int) ($meta['task_id'] ?? 0);

                return $activityTaskId === 0 || $activityTaskId === $taskId;
            });

        $meta = is_array($activity?->meta) ? $activity->meta : [];
        $date = trim((string) ($meta['supplier_delivery_date'] ?? ''));
        if ($date === '' && $job->supplier_delivery_date) {
            $date = $job->supplier_delivery_date->format('Y-m-d');
        }

        $note = trim(app(\App\Services\RichTextService::class)->plainText((string) ($meta['production_issue_note'] ?? '')));

        return [
            'supplier_delivery_date' => $date,
            'production_issue_note' => $note,
        ];
    }

    private function recordCompletedProductionMonitorEdit(Task $task, string $supplierDeliveryDate, string $productionIssueNote, string $changedField): void
    {
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $supplierDeliveryDate);
        $description = $changedField === 'supplier_delivery_date'
            ? ($supplierDeliveryDate !== ''
                ? 'Supplier delivery date updated to '.($parsedDate?->format('M j, Y') ?: $supplierDeliveryDate).'.'
                : 'Supplier delivery date cleared.')
            : ($productionIssueNote !== '' ? 'Production issue note updated.' : 'Production issue note cleared.');

        $task->job->activities()->create([
            'user_id' => auth()->id(),
            'event' => 'job.supplier_delivery_date_set',
            'description' => $description,
            'meta' => [
                'supplier_delivery_date' => $supplierDeliveryDate,
                'production_issue_note' => $productionIssueNote !== '' ? $productionIssueNote : null,
                'task_id' => (int) $task->id,
                'edited_after_completion' => true,
                'changed_field' => $changedField,
            ],
        ]);
    }

    public function submitOrderWorkflowAction(string $decision = 'confirm'): void
    {
        // A submit can arrive after the modal has already been closed by the
        // immediately preceding request (for example, a rapid double-click or
        // submit/cancel race on a slow connection). There is no valid workflow
        // action left to execute in that state, so treat the stale request as a
        // no-op instead of turning it into a user-facing HTTP 422 page.
        if (! $this->showOrderWorkflowActionModal || ! $this->selectedJobId || ! $this->orderWorkflowActionTaskId) {
            return;
        }

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.client', 'job.items', 'job.phase', 'setupTemplate'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail((int) $this->orderWorkflowActionTaskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        $key = $workflowActions->automationKey($task);

        if ($key === 'SHIP_CONFIRM_INFO' && \App\Support\OrderDetailPresenter::isCompletedTask($task)) {
            try {
                $workflowActions->updateCompletedShipmentInformation($task, auth()->user(), $this->orderWorkflowActionPayload);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ((array) $messages as $message) $this->addError($field, $message);
                }
                return;
            }

            $this->closeOrderWorkflowAction();
            session()->flash('success', 'Shipment details updated.');
            return;
        }

        // Preserve the prototype's nested dialogs instead of collapsing every
        // action into one generic confirmation screen.
        if ($this->orderWorkflowActionStep === 'main' && $decision === 'revise'
            && in_array($key, ['ART_INTERNAL_REVIEW', 'ART_CLIENT_ERP_DECISION'], true)) {
            $this->orderWorkflowActionStep = 'revision';
            $this->orderWorkflowActionComment = '';
            $this->orderWorkflowActionAttachment = null;
            $this->orderWorkflowActionRevisionComments = [];
            $this->orderWorkflowActionRevisionAttachments = [];
            $this->orderWorkflowActionPayload['revision_document_ids'] = [];
            $this->orderWorkflowActionPayload['revision_items'] = [];
            $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionAttachment', 'orderWorkflowActionRevisionComments', 'orderWorkflowActionRevisionAttachments', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
            return;
        }
        if ($this->orderWorkflowActionStep === 'main' && $decision === 'cancel_artwork'
            && $key === 'ART_INTERNAL_REVIEW') {
            $this->orderWorkflowActionStep = 'cancel_artwork';
            $this->orderWorkflowActionComment = '';
            $this->orderWorkflowActionPayload['cancel_artwork_document_ids'] = [];
            $this->orderWorkflowActionPayload['cancel_product_item_ids'] = [];
            $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
            return;
        }
        if ($this->orderWorkflowActionStep === 'main' && $decision === 'issue'
            && in_array($key, ['PROD_ISSUE', 'QC_CHECK'], true)) {
            $this->orderWorkflowActionStep = 'issue';
            $this->orderWorkflowActionComment = '';
            $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
            return;
        }

        // The prototype intentionally uses two dialogs here: first record the
        // client artwork decision, then ask whether a sample/swatch is required.
        if ($key === 'ART_CLIENT_ERP_DECISION' && $decision === 'approved' && $this->orderWorkflowActionStep === 'main') {
            $this->orderWorkflowActionStep = 'sample';
            $this->orderWorkflowActionComment = '';
            $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
            return;
        }
        if ($key === 'ART_CLIENT_ERP_DECISION' && $this->orderWorkflowActionStep === 'sample') {
            // Only the buttons rendered by the sample step may resolve this
            // decision. A duplicate/stale click from the previous Approved
            // button must not be reinterpreted as "No sample" and complete the
            // task accidentally.
            if (! in_array($decision, ['sample_yes', 'sample_no'], true)) {
                return;
            }

            $decision = $decision === 'sample_yes' ? 'sample' : 'confirm';
        }

        if (in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
            // A fresh click retries delivery from attempt one again, so clear
            // the older manual-fallback marker before starting the new cycle.
            $this->resetOrderWorkflowEmailFallbackState();
            $this->forgetOrderWorkflowEmailFallbackMarker($task);
        }

        $isArtworkRevisionSubmission = $this->orderWorkflowActionStep === 'revision'
            && $decision === 'revise'
            && in_array($key, ['ART_INTERNAL_REVIEW', 'ART_CLIENT_ERP_DECISION'], true);
        $isArtworkCancellationSubmission = $this->orderWorkflowActionStep === 'cancel_artwork'
            && $decision === 'cancel_artwork'
            && $key === 'ART_INTERNAL_REVIEW';
        $revisionAttachments = [];
        if ($isArtworkRevisionSubmission) {
            $this->validate([
                'orderWorkflowActionPayload.revision_document_ids' => ['required', 'array', 'min:1'],
                'orderWorkflowActionPayload.revision_document_ids.*' => ['integer', 'distinct'],
            ], [
                'orderWorkflowActionPayload.revision_document_ids.required' => 'Select at least one artwork file that needs revision.',
                'orderWorkflowActionPayload.revision_document_ids.min' => 'Select at least one artwork file that needs revision.',
            ]);

            $revisionIds = collect($this->orderWorkflowActionPayload['revision_document_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values();
            $rules = [];
            $messages = [];
            foreach ($revisionIds as $documentId) {
                $rules['orderWorkflowActionRevisionComments.'.$documentId] = ['required', 'string', 'max:10000'];
                $rules['orderWorkflowActionRevisionAttachments.'.$documentId] = ['nullable', 'array', 'max:50'];
                $rules['orderWorkflowActionRevisionAttachments.'.$documentId.'.*'] = AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 409600);
                $messages['orderWorkflowActionRevisionComments.'.$documentId.'.required'] = 'Describe the required change for this artwork.';
                $messages['orderWorkflowActionRevisionAttachments.'.$documentId.'.max'] = 'You can attach a maximum of 50 supporting files to each artwork.';
                $messages['orderWorkflowActionRevisionAttachments.'.$documentId.'.*.max'] = 'Each supporting file must be 400 MB or smaller.';
            }
            if ($rules !== []) $this->validate($rules, $messages);

            $this->orderWorkflowActionPayload['revision_items'] = $revisionIds->map(fn ($documentId) => [
                'document_id' => $documentId,
                'comment' => (string) ($this->orderWorkflowActionRevisionComments[$documentId] ?? ''),
            ])->all();
            $revisionAttachments = $revisionIds->mapWithKeys(fn ($documentId) => [
                $documentId => array_values(array_filter((array) ($this->orderWorkflowActionRevisionAttachments[$documentId] ?? []))),
            ])->all();
        }

        if ($isArtworkCancellationSubmission) {
            $this->validate([
                'orderWorkflowActionPayload.cancel_artwork_document_ids' => ['required', 'array', 'min:1'],
                'orderWorkflowActionPayload.cancel_artwork_document_ids.*' => ['integer', 'distinct'],
                'orderWorkflowActionPayload.cancel_product_item_ids' => ['nullable', 'array'],
                'orderWorkflowActionPayload.cancel_product_item_ids.*' => ['integer', 'distinct'],
                'orderWorkflowActionComment' => ['required', 'string', 'max:2000'],
            ], [
                'orderWorkflowActionPayload.cancel_artwork_document_ids.required' => 'Select at least one artwork file to cancel.',
                'orderWorkflowActionPayload.cancel_artwork_document_ids.min' => 'Select at least one artwork file to cancel.',
                'orderWorkflowActionComment.required' => 'Enter a cancellation reason.',
            ]);
        }

        try {
            $workflowActions->perform(
                $task,
                auth()->user(),
                $decision,
                $this->orderWorkflowActionComment,
                $this->orderWorkflowActionPayload,
                $isArtworkRevisionSubmission ? $revisionAttachments : [],
            );
        } catch (HttpExceptionInterface $exception) {
            if (! $isArtworkRevisionSubmission || $exception->getStatusCode() !== 422) {
                throw $exception;
            }

            $message = trim((string) $exception->getMessage());
            $this->addError(
                'orderWorkflowActionRevisionAttachments',
                $message !== '' ? $message : 'One of the supporting files could not be verified. Re-export it and try again.',
            );
            return;
        } catch (EmailDeliveryException $exception) {
            if ($key === 'BILL_SEND') {
                $reference = '';
                if (preg_match('/Reference:\s*([A-Za-z0-9-]+)/', $exception->getMessage(), $matches) === 1) {
                    $reference = trim((string) ($matches[1] ?? ''));
                }
                $message = 'The invoice email could not be sent. Please try again.';
                if ($reference !== '') $message .= ' Reference: '.$reference;
                $this->addError('orderWorkflowActionEmail', $message);
                return;
            }

            if (! in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
                throw $exception;
            }

            $preview = app(OrderWorkflowEmailService::class)->preview($task, auth()->user(), $this->orderWorkflowActionPayload);
            $trackingId = '';
            if (preg_match('/Reference:\s*([A-Za-z0-9-]+)/', $exception->getMessage(), $matches) === 1) {
                $trackingId = (string) ($matches[1] ?? '');
            }
            $previewPrimary = collect($preview['recipients'] ?? [])->first();
            $previewCc = collect($preview['cc_recipients'] ?? []);
            $failure = [
                'task_id' => (int) $task->id,
                'flow_job_id' => (int) $task->flow_job_id,
                'handoff_key' => (string) $key,
                'document_id' => (int) ($preview['document_id'] ?? 0),
                'document_name' => (string) ($preview['document_name'] ?? ''),
                'attempts' => 3,
                'tracking_id' => $trackingId,
                'primary_recipient_user_id' => ($previewPrimary && ! ($previewPrimary['external'] ?? false))
                    ? (int) ($previewPrimary['id'] ?? 0)
                    : 0,
                'assignment_user_id' => (int) ($preview['assignment_user_id'] ?? 0),
                'external_primary_recipient' => ($previewPrimary && ($previewPrimary['external'] ?? false))
                    ? [
                        'name' => trim((string) ($previewPrimary['name'] ?? 'External recipient')),
                        'email' => trim((string) ($previewPrimary['email'] ?? '')),
                    ]
                    : null,
                'cc_recipient_user_ids' => $previewCc
                    ->filter(fn ($recipient) => ! ($recipient['external'] ?? false))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->values()
                    ->all(),
                'external_cc_emails' => $previewCc
                    ->filter(fn ($recipient) => (bool) ($recipient['external'] ?? false))
                    ->pluck('email')
                    ->filter()
                    ->values()
                    ->implode(', '),
                'failed_at' => now()->toIso8601String(),
            ];
            session()->put($this->orderWorkflowEmailFallbackSessionKey($task), $failure);

            $this->showOrderWorkflowEmailFallback($key, 3);
            $this->resetValidation('orderWorkflowActionEmail');

            return;
        }

        if (in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
            $this->forgetOrderWorkflowEmailFallbackMarker($task);
        }

        $this->dispatchTaskAssigneeSync($task->id);

        if ($key === 'ART_INTERNAL_REVIEW' && $decision === 'confirm') {
            $this->dispatch('order-runtime-refreshed', orderId: (int) $task->flow_job_id);
        }

        $invoiceDeliveryStatus = $key === 'BILL_SEND'
            ? strtolower(trim((string) data_get(
                app(OrderInvoiceWorkflowEmailService::class)->deliveryStatus($task),
                'status',
                '',
            )))
            : '';

        $successMessage = $key === 'ART_INTERNAL_REVIEW' && $decision === 'cancel_artwork'
            ? 'Selected artwork cancelled. Any selected products were removed from the active Order.'
            : ($key === 'ART_INTERNAL_REVIEW' && $decision === 'confirm'
                ? 'Artwork confirmed. The tracking QR and confirmed artwork PDF were generated.'
                : match ($key) {
                'NEW_SEND_PO_ARTWORK' => 'Purchase Order emailed to the Artwork Team.',
                'ART_SEND_ORDER_TEAM' => 'Artwork emailed to the Order Team.',
                'BILL_PREPARE' => 'Invoice generated and ready to send.',
                'BILL_SEND' => match ($invoiceDeliveryStatus) {
                    'failed' => 'Send Invoice task completed, but email delivery failed. Use Resend on the completed task to try again.',
                    'not_sent' => 'Send Invoice task completed without email delivery. Use Resend when Order Email is available.',
                    default => 'Invoice emailed to the client.',
                },
                default => 'Order workflow updated.',
            });

        // A missing supplier email must never interrupt the completed artwork workflow.
        if ($key === 'ART_INTERNAL_REVIEW' && $decision === 'confirm') {
            try {
                $warning = app(\App\Services\Reminders\SupplierArtworkReminderService::class)
                    ->missingSupplierEmailWarning(app(\App\Services\SetupContext::class)->workspaceId(), (int) $task->flow_job_id);
                if ($warning) $successMessage .= ' '.$warning;
            } catch (\Throwable $exception) {
                report($exception); // Reminder feedback must not roll back artwork confirmation.
            }
        }

        $this->closeOrderWorkflowAction();
        $this->syncOverviewWorkflowSelectionToCurrentPhase();
        session()->flash('success', $successMessage);
    }

    public function removeOrderWorkflowActionAttachment(): void
    {
        $this->orderWorkflowActionAttachment = null;
        $this->resetValidation('orderWorkflowActionAttachment');
    }

    public function removeOrderWorkflowActionRevisionAttachment(int $documentId, int $index): void
    {
        if (! isset($this->orderWorkflowActionRevisionAttachments[$documentId][$index])) return;

        unset($this->orderWorkflowActionRevisionAttachments[$documentId][$index]);
        $this->orderWorkflowActionRevisionAttachments[$documentId] = array_values(
            $this->orderWorkflowActionRevisionAttachments[$documentId],
        );
        $this->resetValidation([
            'orderWorkflowActionRevisionAttachments.'.$documentId,
            'orderWorkflowActionRevisionAttachments.'.$documentId.'.*',
        ]);
    }

    private function shipmentEditableTask(int $taskId, string $expectedKey): Task
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.client', 'job.items', 'job.phase', 'setupTemplate', 'documents', 'links'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(app(\App\Services\OrderWorkflowActionService::class)->automationKey($task) === $expectedKey, 422);
        abort_if(strcasecmp((string) $task->job?->status, 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');

        $isCompleted = \App\Support\OrderDetailPresenter::isCompletedTask($task);
        $isCurrentShipmentStage = (int) $task->workflow_phase_id === (int) $task->job?->workflow_phase_id;
        abort_unless($isCompleted || $isCurrentShipmentStage, 422, 'Shipment details are available when the Shipment stage becomes active.');

        return $task;
    }

    private function shipmentActionTask(int $taskId, string $expectedKey): Task
    {
        $task = $this->shipmentEditableTask($taskId, $expectedKey);
        app(\App\Services\OrderTaskSequenceService::class)->assertStatusActionable($task);

        return $task;
    }

    protected function syncOverviewWorkflowSelectionToCurrentPhase(): ?int
    {
        if (! $this->selectedJobId) return null;

        $currentPhaseId = FlowJob::query()->whereKey($this->selectedJobId)->value('workflow_phase_id');
        if (! $currentPhaseId) return null;

        $currentPhaseId = (int) $currentPhaseId;
        $this->overviewPhaseId = $currentPhaseId;
        $this->lastOverviewWorkflowPhaseId = $currentPhaseId;

        return $currentPhaseId;
    }

    private function refreshShipmentWorkflowSelection(): void
    {
        $this->syncOverviewWorkflowSelectionToCurrentPhase();
    }

    /**
     * Ignore a replay of an Order workflow action that another request already
     * completed. The task completion is the proof that the original action was
     * applied; no sequencing rule is relaxed for incomplete/historical tasks.
     */
    private function ignoreCompletedWorkflowReplay(Task $task): bool
    {
        if (! \App\Support\OrderDetailPresenter::isCompletedTask($task)) {
            return false;
        }

        $this->syncOverviewWorkflowSelectionToCurrentPhase();

        return true;
    }

    public function resendCompletedArtworkEmail(int $taskId): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.client', 'job.items', 'job.phase', 'setupTemplate'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        abort_unless($workflowActions->automationKey($task) === 'ART_SEND_ORDER_TEAM', 422, 'Only the Send Artwork to Order Team task supports this resend action.');
        abort_unless($task->completed_at || strcasecmp(trim((string) $task->status), 'Completed') === 0, 422, 'Complete the handoff task before using resend.');

        // Clear any previous result immediately. wire:loading supplies the visible
        // "Sending..." state while this synchronous resend is running.
        unset($this->orderWorkflowEmailResendFeedback[$taskId]);

        try {
            app(OrderWorkflowEmailService::class)->resendCompletedArtworkHandoff($task, auth()->user());
        } catch (EmailDeliveryException $exception) {
            $message = 'Artwork email could not be delivered. Check the email service/provider and try Resend again.';
            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message, 'failed');
            session()->flash('error', $message);
            return;
        } catch (ValidationException $exception) {
            $message = (string) (collect($exception->errors())->flatten()->first() ?: 'The artwork email could not be resent.');
            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message);
            session()->flash('error', $message);
            return;
        } catch (HttpExceptionInterface $exception) {
            $message = trim((string) $exception->getMessage());

            if ($exception->getStatusCode() === 422 && str_contains(mb_strtolower($message), 'email sending is currently disabled')) {
                $message = 'Artwork email was not sent because Order Email is disabled. Enable Order Email in Email Settings, then click Resend again.';
            } elseif ($message === '') {
                $message = 'The artwork email could not be resent. Check the email settings and saved recipients, then try again.';
            }

            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message);
            session()->flash('error', $message);
            return;
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Artwork email failed to send. Check the Order Email configuration or provider connection, then try Resend again.';
            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message, 'failed');
            session()->flash('error', $message);
            return;
        }

        $message = 'Artwork email sent successfully to the saved Order Team recipients.';
        $this->setOrderWorkflowEmailResendFeedback($taskId, 'success', $message, 'sent');
        session()->flash('success', $message);
    }

    public function resendCompletedInvoiceEmail(int $taskId): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.client', 'job.items', 'job.phase', 'setupTemplate'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(
            app(\App\Services\OrderWorkflowActionService::class)->automationKey($task) === 'BILL_SEND',
            422,
            'Only the Send Invoice task supports this resend action.',
        );
        abort_unless($task->completed_at || strcasecmp(trim((string) $task->status), 'Completed') === 0, 422, 'Complete the Send Invoice task before using resend.');

        unset($this->orderWorkflowEmailResendFeedback[$taskId]);

        try {
            app(OrderInvoiceWorkflowEmailService::class)->resendCompleted($task, auth()->user());
        } catch (EmailDeliveryException $exception) {
            $message = 'Invoice email could not be delivered. Check the email service/provider and try Resend again.';
            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message, 'failed');
            session()->flash('error', $message);
            return;
        } catch (ValidationException $exception) {
            $message = (string) (collect($exception->errors())->flatten()->first() ?: 'The invoice email could not be resent.');
            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message);
            session()->flash('error', $message);
            return;
        } catch (HttpExceptionInterface $exception) {
            $message = trim((string) $exception->getMessage());
            if ($exception->getStatusCode() === 422 && str_contains(mb_strtolower($message), 'email sending is currently disabled')) {
                $message = 'Invoice email was not sent because Order Email is disabled. Enable Order Email in Email Settings, then click Resend again.';
            } elseif ($message === '') {
                $message = 'The invoice email could not be resent. Check the email settings and saved recipient, then try again.';
            }

            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message);
            session()->flash('error', $message);
            return;
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Invoice email failed to send. Check the Order Email configuration or provider connection, then try Resend again.';
            $this->setOrderWorkflowEmailResendFeedback($taskId, 'error', $message, 'failed');
            session()->flash('error', $message);
            return;
        }

        $message = 'Invoice email sent successfully to the saved client recipient.';
        $this->setOrderWorkflowEmailResendFeedback($taskId, 'success', $message, 'sent');
        session()->flash('success', $message);
    }

    private function setOrderWorkflowEmailResendFeedback(int $taskId, string $type, string $message, ?string $emailStatus = null): void
    {
        $this->orderWorkflowEmailResendFeedback[$taskId] = [
            'type' => $type,
            'message' => $message,
            'email_status' => $emailStatus,
        ];
    }

    public function completeOrderWorkflowEmailTaskAfterFailure(): void
    {
        abort_unless($this->selectedJobId && $this->orderWorkflowActionTaskId, 422);

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.client', 'job.items', 'job.phase', 'setupTemplate'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail((int) $this->orderWorkflowActionTaskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
        $key = $workflowActions->automationKey($task);
        abort_unless(in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true), 422);

        $failure = $this->orderWorkflowEmailFallbackMarker($task);
        if (! $failure) {
            $this->resetOrderWorkflowEmailFallbackState();
            $this->addError('orderWorkflowActionEmail', 'Manual completion is available only after the email service has failed three delivery attempts.');
            return;
        }

        $workflowActions->completeEmailHandoffAfterFailure($task, auth()->user(), $failure);
        $this->dispatchTaskAssigneeSync($task->id);
        $this->forgetOrderWorkflowEmailFallbackMarker($task);

        $attachmentLabel = $key === 'ART_SEND_ORDER_TEAM' ? 'artwork' : 'Purchase Order';
        $this->closeOrderWorkflowAction();
        $this->syncOverviewWorkflowSelectionToCurrentPhase();
        session()->flash('success', 'Task completed manually. Please send the '.$attachmentLabel.' outside FlowTrack using the downloaded file.');
    }

    private function showOrderWorkflowEmailFallback(?string $key, int $attempts = 3): void
    {
        $attempts = max(3, $attempts);
        $attachmentLabel = $key === 'ART_SEND_ORDER_TEAM' ? 'artwork' : 'Purchase Order';

        $this->orderWorkflowEmailFallback = true;
        $this->orderWorkflowEmailFallbackAttempts = $attempts;
        $this->orderWorkflowEmailFallbackMessage = 'Due to some technical issue, the email could not be sent after '.$attempts.' attempts. Please download the '.$attachmentLabel.' and send it manually. After sending it manually, you can complete this task to continue the workflow.';
    }

    /** @return array<string,mixed>|null */
    private function orderWorkflowEmailFallbackMarker(Task $task): ?array
    {
        $value = session()->get($this->orderWorkflowEmailFallbackSessionKey($task));

        return is_array($value) ? $value : null;
    }

    private function forgetOrderWorkflowEmailFallbackMarker(Task $task): void
    {
        session()->forget($this->orderWorkflowEmailFallbackSessionKey($task));
    }

    private function orderWorkflowEmailFallbackSessionKey(Task $task): string
    {
        return 'order_workflow_email_fallback.'.(int) auth()->id().'.'.(int) $task->id;
    }

    private function resetOrderWorkflowEmailFallbackState(): void
    {
        $this->orderWorkflowEmailFallback = false;
        $this->orderWorkflowEmailFallbackMessage = '';
        $this->orderWorkflowEmailFallbackAttempts = 0;
    }

}
