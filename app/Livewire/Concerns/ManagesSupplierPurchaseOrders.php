<?php

namespace App\Livewire\Concerns;

use App\Models\SupplierPurchaseOrder;
use App\Models\Task;
use App\Services\AccessControlService;
use App\Services\Orders\SupplierPurchaseOrderService;
use App\Services\OrderWorkflowActionService;
use App\Services\TaskService;
use Illuminate\Validation\ValidationException;

/** Shared by the Orders list and Order Details workflow action dialogs. */
trait ManagesSupplierPurchaseOrders
{
    protected function supplierPoActionTask(): Task
    {
        $taskId = (int) ($this->orderWorkflowActionTaskId ?? 0);
        abort_unless($this->showOrderWorkflowActionModal && $taskId > 0, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['setupTemplate', 'job.phase'])
            ->findOrFail($taskId);
        abort_unless(app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_CREATE_SUPPLIER_PO', 422);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        $hostOrderId = property_exists($this, 'listActionOrderId')
            ? (int) $this->listActionOrderId : (int) $this->selectedJobId;
        abort_unless((int) $task->flow_job_id === $hostOrderId, 403);
        abort_if(strcasecmp((string) $task->job->status, 'Cancelled') === 0, 422, 'Purchase orders cannot be changed on cancelled Orders.');
        return $task;
    }

    protected function prepareSupplierPoModal(Task $task): void
    {
        $service = app(SupplierPurchaseOrderService::class);
        $rows = $service->rows($task);
        $this->orderWorkflowActionModalPreview['supplier_po'] = [
            // Product lookup is only needed before creating PDFs, not on every
            // email or selected-PO rerender.
            'products' => $rows ? [] : $service->products($task),
            'rows' => $rows,
        ];
        $this->orderWorkflowActionPayload['supplier_po_mode'] = 'single';
        $this->orderWorkflowActionPayload['supplier_po_item_ids'] = [];
        $this->orderWorkflowActionPayload['supplier_po_template'] = [];
        $this->orderWorkflowActionPayload['supplier_po_selected_id'] = (int) ($rows[0]['id'] ?? 0);
        $this->orderWorkflowActionPayload['supplier_po_recipients'] = collect($rows)->mapWithKeys(function (array $row) {
            $recipients = $row['recipients'] ?: array_filter([(string) $row['email']]);
            return [(int) $row['id'] => array_values($recipients) ?: ['']];
        })->all();
    }

    public function chooseSupplierPurchaseOrderMode(string $mode): void
    {
        abort_unless(in_array($mode, ['single', 'split'], true), 422);
        $this->orderWorkflowActionPayload['supplier_po_mode'] = $mode;
        $this->resetValidation('orderWorkflowActionPayload.supplier_po_item_ids');
    }

    /** Switch between generated PDFs using the already-loaded modal snapshot. */
    public function selectSupplierPurchaseOrder(int $poId): void
    {
        $ids = array_column((array) data_get($this->orderWorkflowActionModalPreview, 'supplier_po.rows', []), 'id');
        abort_unless(in_array($poId, array_map('intval', $ids), true), 422);
        $this->orderWorkflowActionPayload['supplier_po_selected_id'] = $poId;
    }

    public function addSupplierPoRecipient(int $poId): void
    {
        $this->selectSupplierPurchaseOrder($poId);
        $recipients = (array) ($this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] ?? ['']);
        if (count($recipients) < 20) {
            $recipients[] = '';
            $this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] = array_values($recipients);
        }
    }

    public function removeSupplierPoRecipient(int $poId, int $index): void
    {
        $this->selectSupplierPurchaseOrder($poId);
        $recipients = (array) ($this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] ?? ['']);
        if ($index > 0 && array_key_exists($index, $recipients)) {
            unset($recipients[$index]);
            $this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] = array_values($recipients);
        }
    }

    private function supplierPoRecipientEmails(int $poId): string
    {
        return implode(', ', array_map(
            fn ($value) => trim((string) $value),
            (array) ($this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] ?? []),
        ));
    }

    /** Open already-generated POs after the Artwork task has been completed. */
    public function openGeneratedSupplierPurchaseOrders(int $taskId): void
    {
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['setupTemplate', 'job.phase'])
            ->findOrFail($taskId);
        abort_unless(app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_CREATE_SUPPLIER_PO', 422);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(SupplierPurchaseOrder::query()->where('task_id', $task->id)->whereNull('archived_at')->exists(), 422, 'Create the Purchase Order first.');
        if (property_exists($this, 'listActionOrderId')) {
            $this->listActionOrderId = (int) $task->flow_job_id;
        } else {
            abort_unless((int) $this->selectedJobId === (int) $task->flow_job_id, 403);
        }
        $this->orderWorkflowActionTaskId = $taskId;
        $this->orderWorkflowActionStep = 'main';
        $this->orderWorkflowActionPayload = [];
        $this->orderWorkflowActionModalPreview = [];
        $this->prepareSupplierPoModal($task);
        $this->showOrderWorkflowActionModal = true;
        $this->resetValidation();
    }

    public function createSupplierPurchaseOrders(): void
    {
        $task = $this->supplierPoActionTask();
        $this->validate([
            'orderWorkflowActionPayload.supplier_po_mode' => ['required', 'in:single,split'],
            'orderWorkflowActionPayload.supplier_po_item_ids' => ['array'],
            'orderWorkflowActionPayload.supplier_po_item_ids.*' => ['integer', 'distinct'],
            'orderWorkflowActionPayload.supplier_po_template' => ['array'],
            'orderWorkflowActionPayload.supplier_po_template.*' => ['nullable', 'string', 'max:255'],
        ]);
        $service = app(SupplierPurchaseOrderService::class);
        try {
            // PDF generation is not task completion. The user decides whether
            // to email or explicitly complete without email.
            $service->generate(
                $task,
                auth()->user(),
                (string) $this->orderWorkflowActionPayload['supplier_po_mode'],
                (array) ($this->orderWorkflowActionPayload['supplier_po_item_ids'] ?? []),
                (array) ($this->orderWorkflowActionPayload['supplier_po_template'] ?? []),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) $this->addError($field, $message);
            }
            return;
        }
        $this->prepareSupplierPoModal($task);
        session()->flash('success', 'Purchase Order PDFs are ready. Send them or complete the task without email.');
    }

    /** Complete the generated-PO task even if some supplier emails were skipped. */
    public function completeSupplierPurchaseOrdersWithoutEmail(): void
    {
        $task = $this->supplierPoActionTask();
        $this->finishSupplierPurchaseOrderTask($task);
    }

    private function finishSupplierPurchaseOrderTask(Task $task): void
    {
        if (!$task->completed_at) {
            app(OrderWorkflowActionService::class)->perform($task, auth()->user(), 'confirm');
        }
        $orderId = (int) $task->flow_job_id;
        $this->closeOrderWorkflowAction();
        if (method_exists($this, 'syncOverviewWorkflowSelectionToCurrentPhase')) {
            $this->syncOverviewWorkflowSelectionToCurrentPhase();
        }
        $this->dispatch('order-runtime-refreshed', orderId: $orderId);
        session()->flash('success', 'Purchase Order task completed. The next task is now available.');
    }

    /** Auto-finish only when every active PO was sent successfully. */
    private function finishSupplierPoIfAllSent(Task $task, array $rows): void
    {
        if ($rows && collect($rows)->every(fn (array $po) => $po['status'] === 'sent')) {
            $this->finishSupplierPurchaseOrderTask($task);
        }
    }

    /** Send all generated supplier POs, each to its own editable recipient list. */
    public function sendAllSupplierPurchaseOrders(): void
    {
        $task = $this->supplierPoActionTask();
        $purchaseOrders = SupplierPurchaseOrder::query()
            ->where('task_id', $task->id)->where('flow_job_id', $task->flow_job_id)
            ->whereNull('archived_at')->whereIn('email_status', ['not_sent', 'failed'])->orderBy('id')->get();
        abort_if($purchaseOrders->isEmpty(), 422, 'All purchase order emails have already been sent.');
        $service = app(SupplierPurchaseOrderService::class);
        $emails = [];
        $hasErrors = false;
        $firstInvalidId = null;

        // Validate *every* recipient field before sending any email to avoid
        // partial dispatch caused by a typo in the last supplier address.
        foreach ($purchaseOrders as $po) {
            $raw = $this->supplierPoRecipientEmails((int) $po->id);
            try {
                $service->validateSend($task, $po, $raw);
                $emails[$po->id] = $raw;
            } catch (ValidationException $exception) {
                $hasErrors = true;
                $firstInvalidId ??= (int) $po->id;
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) $this->addError($field, $message);
                }
            }
        }
        if ($hasErrors) {
            // Show the first incorrect address even when another PO was open.
            $this->selectSupplierPurchaseOrder($firstInvalidId);
            return;
        }

        $sent = 0;
        $failed = 0;
        $firstFailedId = null;
        foreach ($purchaseOrders as $po) {
            $result = $service->send($task, (int) $po->id, auth()->user(), $emails[$po->id]);
            if ($result->email_status === 'sent') $sent++;
            else {
                $failed++;
                $firstFailedId ??= (int) $po->id;
                $this->addError(
                    'orderWorkflowActionPayload.supplier_po_emails.'.$po->id,
                    'Sending failed. Please check email settings and retry this PO.'
                );
            }
        }
        $currentRows = $service->rows($task);
        $this->orderWorkflowActionModalPreview['supplier_po']['rows'] = $currentRows;
        if ($failed === 0) $this->finishSupplierPoIfAllSent($task, $currentRows);
        if ($firstFailedId !== null) $this->selectSupplierPurchaseOrder($firstFailedId);
        session()->flash($failed > 0 ? 'error' : 'success',
            $sent.' purchase order(s) emailed'.($failed > 0 ? '; '.$failed.' failed and can be retried.' : '.'));
    }

    public function sendSupplierPurchaseOrder(int $poId): void
    {
        $task = $this->supplierPoActionTask();
        $row = SupplierPurchaseOrder::query()
            ->where('task_id', $task->id)->where('flow_job_id', $task->flow_job_id)->whereNull('archived_at')->findOrFail($poId);
        $emails = $this->supplierPoRecipientEmails($poId);
        try {
            $updated = app(SupplierPurchaseOrderService::class)->send($task, $row->id, auth()->user(), $emails);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) $this->addError($field, $message);
            }
            return;
        }
        $this->resetValidation('orderWorkflowActionPayload.supplier_po_emails.'.$poId);
        // Refresh data but do not overwrite user-entered recipients on other POs.
        $service = app(SupplierPurchaseOrderService::class);
        $this->orderWorkflowActionModalPreview['supplier_po']['rows'] = $service->rows($task);
        if ($updated->email_status === 'sent') {
            $this->finishSupplierPoIfAllSent($task, $this->orderWorkflowActionModalPreview['supplier_po']['rows']);
            session()->flash('success', $updated->po_number.' sent successfully.');
        } else {
            $this->addError('orderWorkflowActionPayload.supplier_po_emails.'.$poId, 'Sending failed. Check your email provider configuration and retry.');
        }
    }
}
