<?php

namespace App\Services\Orders;

use App\DTOs\Email\EmailMessage;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\MasterRecord;
use App\Models\SupplierPurchaseOrder;
use App\Models\Task;
use App\Models\User;
use App\Services\Email\EmailService;
use App\Services\Email\ModuleEmailControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Supplier-facing procurement POs; intentionally separate from the customer PO upload. */
final class SupplierPurchaseOrderService
{
    /** @var array<int, MasterRecord|null> */
    private array $supplierCache = [];

    public function rows(FlowJob $job): array
    {
        return SupplierPurchaseOrder::query()
            ->where('flow_job_id', $job->id)
            ->whereNull('archived_at')->with(['supplier:id,name,metadata', 'job:id,job_number'])
            ->orderBy('id')->get()->map(fn (SupplierPurchaseOrder $po) => [
                'id' => $po->id,
                'number' => $po->po_number,
                'supplier' => $po->supplier?->name ?: 'Supplier not assigned',
                'email' => $po->supplier ? $this->supplierEmail($po->supplier) : '',
                'recipients' => (array) ($po->recipient_emails ?? []),
                'status' => $po->email_status,
                'sent_at' => $po->sent_at?->format('d M Y, H:i'),
                'error' => $po->email_error,
                'document_id' => $po->document_id,
                'pdf_name' => $po->pdf_name,
                'subject' => $this->subject($po),
                'body' => $this->body($po),
                'items' => collect($po->items_snapshot)->pluck('name')->implode(', '),
                'item_rows' => (array) $po->items_snapshot,
                'delivery_date' => $po->po_delivery_date?->format('Y-m-d')
                    ?: ($job->supplier_delivery_date ?: $job->delivery_date)?->format('Y-m-d'),
                'revision' => (int) $po->revision,
            ])->all();
    }

    public function products(FlowJob $job): array
    {
        $items = FlowJobItem::query()->where('flow_job_id', $job->id)
            ->where('is_removed', false)->with(['supplier', 'catalogProduct'])->orderBy('sort_order')->orderBy('id')->get();
        return $items->map(fn (FlowJobItem $item) => [
            'id' => $item->id,
            'name' => $item->product_name,
            'quantity' => (int) $item->quantity,
            'sku' => (string) ($item->catalogProduct?->code ?: ''),
            'supplier_unit_cost' => $item->supplier_unit_price === null ? null : (float) $item->supplier_unit_price,
            'supplier' => $this->supplierFor($item)?->name ?: 'Not assigned',
        ])->all();
    }

    private function supplierFor(FlowJobItem $item): ?MasterRecord
    {
        if ($item->supplier_id) return $item->supplier;
        $catalog = $item->catalogProduct;
        $supplierId = $catalog && method_exists($catalog, 'productSupplierId') ? (int) $catalog->productSupplierId() : 0;
        if ($supplierId < 1) return null;
        if (!array_key_exists($supplierId, $this->supplierCache)) {
            $this->supplierCache[$supplierId] = MasterRecord::query()
                ->where('type', 'supplier')->find($supplierId);
        }
        return $this->supplierCache[$supplierId];
    }

    private function supplierEmail(MasterRecord $supplier): string
    {
        foreach (['email', 'contact_email', 'supplier_email'] as $field) {
            $email = trim((string) data_get($supplier->metadata, $field, ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
        }
        return '';
    }

    /** @return list<SupplierPurchaseOrder> */
    public function generate(FlowJob $job, ?User $actor, string $mode, array $splitItemIds, array $templateDetails = [], array $itemOverrides = [], ?string $deliveryDate = null, ?int $onlySupplierId = null, bool $artworkTaskAction = false): array
    {
        abort_unless(config('flowtrack.supplier_po_creation_enabled', false), 403, 'Supplier PO creation is temporarily disabled.');
        abort_unless(in_array($mode, ['single', 'split', 'by_supplier'], true), 422);
        // Only the signed Supplier Portal action or the explicit Artwork task may create a PO.
        // A direct Livewire call from the order list/workspace must not generate one.
        abort_unless($artworkTaskAction || $onlySupplierId !== null, 403,
            'Create the Purchase Order using the optional Artwork task.');
        app(OrderHoldService::class)->assertNotHeld((int) $job->id);
        $templateKeys = [
            'carton_buyer', 'carton_style', 'carton_colour', 'carton_size', 'carton_qty', 'carton_gross_weight',
            'carton_net_weight', 'carton_measurement', 'carton_other', 'carton_mark_type',
            'carton_strength', 'carton_strength_other', 'inner_packing', 'pieces_per_inner', 'inner_packing_other',
            'payment_method', 'deposit_percent', 'balance_percent', 'payment_other',
            'payment_term', 'beneficiary', 'bank_name', 'bank_address', 'account_number', 'swift_code',
        ];
        $templateDetails = array_intersect_key($templateDetails, array_flip($templateKeys));
        $templateDetails = array_map(fn ($value) => trim((string) $value), $templateDetails);

        $deliveryDate = $this->validatedDeliveryDate($job, $deliveryDate);
        $job->loadMissing(['client', 'items.supplier', 'items.catalogProduct']);
        $items = $job->items->reject(fn (FlowJobItem $item) => $item->is_removed)
            ->when($onlySupplierId !== null, fn ($list) => $list->filter(
                fn (FlowJobItem $item) => (int) ($this->supplierFor($item)?->id ?? 0) === $onlySupplierId
            ))->values();
        if ($items->isEmpty()) throw ValidationException::withMessages(['orderWorkflowActionPayload' => 'Add at least one active Order product before creating a PO.']);

        $knownIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $splitIds = array_map('intval', $splitItemIds);
        if (count($splitIds) !== count(array_unique($splitIds)) || array_diff($splitIds, $knownIds)) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_item_ids' => 'Select valid products from this Order.']);
        }
        if ($mode === 'split' && (count($items) < 2 || !$splitIds)) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_item_ids' => 'Select at least one product to split from the other Order products.']);
        }

        if (array_diff(array_map('intval', array_keys($itemOverrides)), $knownIds)) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_items' => 'The selected PO contains an unknown Order product.']);
        }

        // Each selected product receives its own PO. Keep every unselected
        // product as well, grouped by supplier so dispatch can pick a real
        // supplier email instead of mixing unrelated suppliers on a PO.
        $groups = $mode === 'single' ? [$items] : [];
        if ($mode === 'by_supplier') {
            // The one-click Artwork action uses existing supplier assignments.
            // Never combine two suppliers' product details in the same PDF.
            $groups = $items->groupBy(fn (FlowJobItem $item) => (int) ($this->supplierFor($item)?->id ?? 0))
                ->values()->map(fn ($group) => $group->values())->all();
        }
        if ($mode === 'split') {
            foreach ($splitIds as $id) $groups[] = $items->filter(fn ($item) => $item->id === $id)->values();
            $remaining = $items->reject(fn ($item) => in_array($item->id, $splitIds, true))->values();
            foreach ($remaining->groupBy(fn (FlowJobItem $item) => 'supplier-'.($this->supplierFor($item)?->id ?: 'unassigned')) as $supplierItems) {
                $groups[] = $supplierItems->values();
            }
        }

        // One active PO batch per order. Artwork revision archives an old batch.
        $writtenPaths = [];
        try {
            return DB::transaction(function () use ($actor, $job, $groups, $mode, $templateDetails, $itemOverrides, $deliveryDate, $onlySupplierId, &$writtenPaths): array {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedJob->workflow_phase_id === (int) $job->workflow_phase_id, 422, 'The Order stage changed. Reload the Order before creating a PO.');
            $this->assertOptionalArtworkStepReady($lockedJob, $actor, $onlySupplierId !== null);
            // A remote supplier can create only its own assigned products.
            if ($onlySupplierId !== null) {
                abort_unless($groups !== [] && collect($groups)->every(fn ($group) => $group->every(
                    fn (FlowJobItem $item) => (int) ($this->supplierFor($item)?->id ?? 0) === $onlySupplierId
                )), 403);
            }
            // One indexed read checks whether the latest generation is still
            // active and supplies a unique revision suffix for regenerated POs.
            $previous = SupplierPurchaseOrder::query()->where('flow_job_id', $job->id)->orderByDesc('id')->first(['po_number', 'archived_at']);
            abort_unless(!$previous || $previous->archived_at, 422, 'Purchase orders have already been generated.');
            // Easy-to-read revision numbering: original, R2, R3, ...
            $lastRevision = 1;
            if ($previous && preg_match('/-R(\d+)-\d+$/', (string) $previous->po_number, $matches)) {
                $lastRevision = (int) $matches[1];
            }
            $numberSuffix = $previous ? '-R'.($lastRevision + 1) : '';
            $batch = (string) Str::uuid();
            $created = [];
            foreach ($groups as $index => $group) {
                $groupSuppliers = $group->map(fn (FlowJobItem $item) => $this->supplierFor($item));
                $suppliers = $groupSuppliers->map(fn (?MasterRecord $supplier) => $supplier?->id)->unique();
                if ($suppliers->count() !== 1 || ! $suppliers->first()) {
                    throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_mode' => 'Each supplier PO must contain products from one assigned supplier. Use Split PO for different suppliers.']);
                }
                $supplierId = $suppliers->first();
                $supplierName = $supplierId ? (string) ($groupSuppliers->first(fn (?MasterRecord $supplier) => $supplier?->id === $supplierId)?->name ?: '') : '';
                $snapshot = $group->map(fn (FlowJobItem $item) => $this->validatedItem([
                    'item_id' => (int) $item->id,
                    'name' => (string) $item->product_name,
                    'sku' => (string) ($item->catalogProduct?->code ?: ''),
                    'quantity' => max(0, (int) $item->quantity),
                    // Never reveal client selling price on a supplier purchase order.
                    'supplier_unit_cost' => $item->supplier_unit_price === null ? null : (float) $item->supplier_unit_price,
                    'supplier_id' => $this->supplierFor($item)?->id,
                ], (array) ($itemOverrides[$item->id] ?? [])))->values()->all();
                $number = 'SPO-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT).$numberSuffix.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $po = SupplierPurchaseOrder::query()->create([
                    'flow_job_id' => $job->id, 'task_id' => null,
                    'supplier_id' => $supplierId, 'created_by' => $actor?->id,
                    'po_number' => $number, 'batch_uuid' => $batch,
                    'mode' => $mode, 'items_snapshot' => $snapshot, 'template_details' => $templateDetails,
                    'po_delivery_date' => $deliveryDate, 'revision' => 1,
                    'pdf_path' => 'supplier-purchase-orders/'.$job->id.'/'.$batch.'/'.$number.'.pdf',
                    'pdf_name' => $number.'.pdf',
                ]);
                $bytes = $this->pdf($po, $job, $supplierName);
                $writtenPaths[] = $po->pdf_path;
                if (!Storage::disk('local')->put($po->pdf_path, $bytes)) throw new \RuntimeException('Unable to save the Purchase Order PDF.');
                $document = $job->documents()->create([
                    'task_id' => null,
                    'client_id' => $job->client_id,
                    'uploaded_by' => $actor?->id,
                    'document_number' => 'SPO-DOC-'.$po->id,
                    'category' => 'Supplier Purchase Order',
                    'name' => $po->pdf_name, 'path' => $po->pdf_path,
                    'mime_type' => 'application/pdf', 'size' => strlen($bytes),
                    'version' => 1, 'is_final' => true,
                    'note' => 'Generated supplier purchase order '.$po->po_number,
                ]);
                $po->update(['document_id' => $document->id]);
                $created[] = $po;
            }
            $job->activities()->create([
                'user_id' => $actor?->id, 'event' => 'job.supplier_purchase_orders_generated',
                'description' => count($created).' supplier purchase order(s) generated.',
                'meta' => ['po_ids' => collect($created)->pluck('id')->all(), 'mode' => $mode,
                    'origin' => $onlySupplierId !== null ? 'supplier_portal' : 'main_erp',
                    'supplier_id' => $onlySupplierId],
            ]);
            // Keep task completion and PO creation in the same transaction.
            // A failure must not leave a generated PO with an unfinished step.
            $this->completeOptionalArtworkStep($lockedJob, $actor, $onlySupplierId);
            $this->notifySupplierAfterCommit((int) $job->id);
            return $created;
            }, 3);
        } catch (\Throwable $exception) {
            // Database rollback does not roll back files on disk. Each attempt
            // uses a unique batch directory to prevent deleting concurrent POs.
            foreach ($writtenPaths as $path) {
                try { Storage::disk('local')->delete($path); } catch (\Throwable) {}
            }
            throw $exception;
        }
    }

    /** The optional Artwork step is the only entry point for a new PO batch. */
    private function assertOptionalArtworkStepReady(FlowJob $job, ?User $actor, bool $supplierSignedRequest = false): void
    {
        $job->loadMissing('phase');
        if (strcasecmp((string) ($job->phase?->name ?: ''), 'Artwork') !== 0) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload' => 'A new Purchase Order can be generated only in the Artwork stage after the sample decision.']);
        }
        $task = Task::query()->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $job->workflow_phase_id)
            ->whereHas('setupTemplate', fn ($q) => $q->where('automation_key', 'ART_SUPPLIER_PO_OPTIONAL'))
            ->first();
        if (!$task) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload' => 'The optional Purchase Order task is not yet configured on this Order. Refresh its Artwork workflow.']);
        }
        if (! $supplierSignedRequest) {
            abort_unless($actor !== null, 403);
            abort_unless(app(\App\Services\AccessControlService::class)->canEditTask($actor, $task), 403);
        }
        if ($task->completed_at || strcasecmp((string) $task->status, 'Skipped') === 0) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload' => 'The optional Purchase Order task has already been finished.']);
        }
        // A skipped or completed sample is sufficient. The required client
        // decision remains unfinished while a selected sample is in progress.
        if (Task::query()->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $job->workflow_phase_id)
            ->whereHas('setupTemplate', fn ($q) => $q->where('is_required', true))
            ->whereNull('completed_at')->where('status', '!=', 'Completed')->exists()) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload' => 'Complete the client artwork approval and any required sample handoff before creating the Purchase Order.']);
        }
    }

    /** Complete only the matching, still-active optional task after user-driven generation. */
    public function completeOptionalArtworkStep(FlowJob $job, ?User $actor, ?int $supplierId = null): void
    {
        $task = Task::query()->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $job->workflow_phase_id)
            ->whereNull('completed_at')
            ->whereHas('setupTemplate', fn ($q) => $q->where('automation_key', 'ART_SUPPLIER_PO_OPTIONAL'))
            ->first();
        if (!$task || strcasecmp((string) $task->status, 'Skipped') === 0) return;
        if ($supplierId !== null) {
            // Supplier POs are already authorized by the signed bridge and its
            // supplier-scoped task assignment. Keep the supplier assignee intact:
            // using moveStatus() here would silently reassign this task to an
            // ERP employee. The assignment's existing FlowTracker delegate is
            // used only for the canonical phase authorization and progression.
            $assignment = \App\Models\SupplierTaskAssignment::query()
                ->where('flow_job_id', $job->id)
                ->where('task_id', $task->id)
                ->where('supplier_id', $supplierId)
                ->first(['assigned_by']);
            abort_unless($assignment && (int) $assignment->assigned_by > 0, 403,
                'The supplier task assignment is no longer valid.');
            $delegate = User::query()->where('is_active', true)
                ->findOrFail((int) $assignment->assigned_by);
            abort_unless(app(\App\Services\AccessControlService::class)->canEditTask($delegate, $task), 403);

            $completedStatus = app(\App\Services\OrderTaskFlagService::class)->completedStatus();
            $task->update([
                'status' => $completedStatus,
                'order_task_status_id' => app(\App\Services\OrderTaskFlagService::class)
                    ->statusRecord($completedStatus, false)?->id,
                'completed_at' => now(),
                'progress' => 100,
            ]);
            // Run precisely the same task-sequence, progress and auto-advance
            // hooks as a normal FlowTracker task completion. This stays inside
            // the PO transaction, so a failed transition rolls the PO back.
            app(\App\Services\TaskService::class)
                ->reconcileAfterVerifiedSupplierCompletion($task, $delegate);
            return;
        }
        abort_unless($actor !== null, 403);
        app(\App\Services\TaskService::class)->moveStatus($task, app(\App\Services\OrderTaskFlagService::class)->completedStatus(), $actor);
    }

    /** Validate only fields visible in the PO editor, never the Order's selling price. */
    private function validatedItem(array $original, array $edits, string $collection = 'supplier_po_items'): array
    {
        $id = (int) $original['item_id'];
        if (array_diff(array_keys($edits), ['name', 'sku', 'quantity', 'supplier_unit_cost'])) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.'.$collection => 'Only PO product details, quantities and supplier prices can be edited.']);
        }
        $name = trim((string) ($edits['name'] ?? $original['name']));
        $sku = trim((string) ($edits['sku'] ?? ($original['sku'] ?? '')));
        $quantity = $edits['quantity'] ?? $original['quantity'];
        $cost = array_key_exists('supplier_unit_cost', $edits) ? $edits['supplier_unit_cost'] : ($original['supplier_unit_cost'] ?? null);
        $prefix = 'orderWorkflowActionPayload.'.$collection.'.'.$id;
        if ($name === '' || mb_strlen($name) > 255 || mb_strlen($sku) > 100) {
            throw ValidationException::withMessages([$prefix.'.name' => 'Enter a product name (maximum 255 characters) and a model of at most 100 characters.']);
        }
        if (!filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999999999]])) {
            throw ValidationException::withMessages([$prefix.'.quantity' => 'Quantity must be a positive whole number.']);
        }
        if ($cost === '') $cost = null;
        if ($cost !== null && (!is_numeric($cost) || (float) $cost < 0 || (float) $cost > 999999999.99
                || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', (string) $cost))) {
            throw ValidationException::withMessages([$prefix.'.supplier_unit_cost' => 'Supplier price must be zero or greater, with up to two decimal places.']);
        }
        return array_replace($original, [
            'name' => $name,
            'sku' => $sku,
            'quantity' => (int) $quantity,
            'supplier_unit_cost' => $cost === null ? null : (float) $cost,
        ]);
    }

    private function validatedDeliveryDate(FlowJob $job, ?string $input, string $field = 'supplier_po_delivery_date'): ?string
    {
        $date = trim((string) $input);
        if ($date === '') return ($job->supplier_delivery_date ?: $job->delivery_date)?->format('Y-m-d');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.'.$field => 'Enter a valid PO delivery date.']);
        }
        $orderDate = $job->supplier_delivery_date ?: $job->delivery_date;
        if ($orderDate && $date > $orderDate->format('Y-m-d')) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.'.$field => 'The PO delivery date must be on or before the Order delivery date.']);
        }
        return $date;
    }

    /**
     * Revise an existing PO without touching original order lines, PDFs or email audit.
     * Revision lock prevents a stale editor from silently overwriting newer data.
     */
    public function update(FlowJob $job, int $poId, ?User $actor, array $editedItems, ?string $deliveryDate, int $expectedRevision): SupplierPurchaseOrder
    {
        abort_unless(config('flowtrack.supplier_po_creation_enabled', false), 403);
        app(OrderHoldService::class)->assertNotHeld((int) $job->id);
        $writtenPath = null;
        try {
            return DB::transaction(function () use ($job, $poId, $actor, $editedItems, $deliveryDate, $expectedRevision, &$writtenPath): SupplierPurchaseOrder {
                $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
                $po = SupplierPurchaseOrder::query()->where('flow_job_id', $job->id)
                    ->whereNull('archived_at')->with('supplier:id,name')->lockForUpdate()->findOrFail($poId);
                if ((int) $po->revision !== $expectedRevision) {
                    throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_edit' => 'This Purchase Order was updated elsewhere. Reopen it to load the latest revision.']);
                }
                if ($po->email_status === 'sending') {
                    throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_edit' => 'This PO is being emailed. Reopen the editor when sending has finished.']);
                }
                $snapshot = (array) $po->items_snapshot;
                $knownIds = array_map('intval', array_column($snapshot, 'item_id'));
                if (count($snapshot) === 0 || array_diff(array_map('intval', array_keys($editedItems)), $knownIds)) {
                    throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_edit' => 'The PO contains unknown or missing product lines.']);
                }
                $updatedItems = array_map(fn (array $row) => $this->validatedItem($row, (array) ($editedItems[$row['item_id']] ?? []), 'supplier_po_edit_items'), $snapshot);
                $date = $this->validatedDeliveryDate($lockedJob, $deliveryDate, 'supplier_po_edit_delivery_date');
                if ($snapshot === $updatedItems && ($po->po_delivery_date?->format('Y-m-d') ?: null) === $date) {
                    return $po; // An unchanged Save does no file or database writes.
                }

                $revision = $expectedRevision + 1;
                $pdfName = $po->po_number.'-V'.$revision.'.pdf';
                $writtenPath = 'supplier-purchase-orders/'.$job->id.'/'.$po->batch_uuid.'/'.$pdfName;
                $po->items_snapshot = $updatedItems;
                $po->po_delivery_date = $date;
                $po->revision = $revision;
                $po->pdf_path = $writtenPath;
                $po->pdf_name = $pdfName;
                $bytes = $this->pdf($po, $lockedJob, (string) ($po->supplier?->name ?: ''));
                if (!Storage::disk('local')->put($writtenPath, $bytes)) {
                    throw new \RuntimeException('Unable to save the revised Purchase Order PDF.');
                }

                $oldDocumentId = $po->document_id;
                $document = $job->documents()->create([
                    'task_id' => null,
                    'client_id' => $job->client_id,
                    'uploaded_by' => $actor?->id,
                    'document_number' => 'SPO-DOC-'.$po->id.'-V'.$revision,
                    'category' => 'Supplier Purchase Order',
                    'name' => $pdfName, 'path' => $writtenPath,
                    'mime_type' => 'application/pdf', 'size' => strlen($bytes),
                    'version' => $revision, 'is_final' => true,
                    'note' => 'Revised supplier purchase order '.$po->po_number.' version '.$revision,
                ]);
                if ($oldDocumentId) {
                    Document::query()->whereKey($oldDocumentId)->where('flow_job_id', $job->id)
                        ->update(['category' => 'Archived Supplier Purchase Order', 'is_final' => false]);
                }
                $po->document_id = $document->id;
                // Revised sent POs are not silently emailed again; an explicit
                // Send action is required for the new PDF version.
                $po->email_status = 'not_sent';
                $po->sent_at = null;
                $po->email_tracking_id = null;
                $po->email_error = null;
                $po->save();
                $this->notifySupplierAfterCommit((int) $job->id);
                $job->activities()->create([
                    'user_id' => $actor?->id,
                    'event' => 'job.supplier_purchase_order_revised',
                    'description' => $po->po_number.' revised to version '.$revision.'.',
                    'meta' => ['po_id' => $po->id, 'revision' => $revision, 'previous_document_id' => $oldDocumentId,
                        'origin' => $actor === null ? 'supplier_portal' : 'main_erp', 'supplier_id' => $po->supplier_id],
                ]);
                return $po;
            }, 3);
        } catch (\Throwable $exception) {
            if ($writtenPath !== null) {
                try { Storage::disk('local')->delete($writtenPath); } catch (\Throwable) {}
            }
            throw $exception;
        }
    }

    /** Archive the prior PO generation without deleting its PDFs or send audit. */
    public function archiveForArtworkRevision(FlowJob $job): void
    {
        $active = SupplierPurchaseOrder::query()->where('flow_job_id', $job->id)->whereNull('archived_at');
        $rows = (clone $active)->get(['id', 'document_id']);
        if ($rows->isEmpty()) return;
        $active->update(['archived_at' => now()]);
        $this->notifySupplierAfterCommit((int) $job->id);
        $documentIds = $rows->pluck('document_id')->filter();
        if ($documentIds->isNotEmpty()) {
            // Keep historical documents visible in a separate Archived section.
            Document::query()->whereIn('id', $documentIds)->where('flow_job_id', $job->id)
                ->update(['category' => 'Archived Supplier Purchase Order']);
        }
    }

    /** Existing outbox + critical queue + scheduled retry handle realtime portal delivery. */
    private function notifySupplierAfterCommit(int $orderId): void
    {
        if (! config('supplier_bridge.enabled')) return;
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(static fn () => app(\App\Services\SupplierBridge\QueueChangedOrder::class)->mark($orderId));
        } else {
            app(\App\Services\SupplierBridge\QueueChangedOrder::class)->mark($orderId);
        }
    }

    private function pdf(SupplierPurchaseOrder $po, FlowJob $job, string $supplierName): string
    {
        return app(SupplierPurchaseOrderTemplatePdf::class)->render(
            $po->po_number,
            $supplierName,
            strtoupper((string) ($job->currency ?: '')),
            (array) $po->items_snapshot,
            ($po->po_delivery_date ?: $job->supplier_delivery_date ?: $job->delivery_date)
                ? \Illuminate\Support\Carbon::parse($po->po_delivery_date ?: $job->supplier_delivery_date ?: $job->delivery_date)->format('d M Y')
                : null,
            'Step Promo & Accessories Co Ltd.',
            (array) ($po->template_details ?? []),
        );
    }

    private function subject(SupplierPurchaseOrder $po): string
    {
        return 'Purchase Order '.$po->po_number.' - '.$po->job->displayOrderNumber();
    }

    private function body(SupplierPurchaseOrder $po): string
    {
        $supplier = $po->supplier?->name ?: 'Supplier';
        return "Dear {$supplier},\n\nPlease find attached Purchase Order {$po->po_number} for order {$po->job->displayOrderNumber()}.\n\nPlease review the products and quantities in the attached PDF and confirm availability, pricing and estimated delivery.\n\nKind regards,\nOrder Team";
    }

    /** @return list<string> */
    public function validateSend(FlowJob $job, SupplierPurchaseOrder $po, string $emails): array
    {
        abort_unless((int) $po->flow_job_id === (int) $job->id, 403);
        app(OrderHoldService::class)->assertNotHeld((int) $job->id);
        $recipients = collect(preg_split('/[,;\s]+/', trim($emails)) ?: [])
            ->map(fn ($email) => trim($email))->filter()->unique(fn ($email) => strtolower($email))->values()->all();
        $error = 'orderWorkflowActionPayload.supplier_po_emails.'.$po->id;
        if (!$recipients || count($recipients) > 20 || collect($recipients)->contains(fn ($email) => !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages([$error => 'Enter one or more valid email addresses (maximum 20).']);
        }
        if (!Storage::disk('local')->exists($po->pdf_path)) {
            throw ValidationException::withMessages([$error => 'The PDF is unavailable. Contact the administrator before sending.']);
        }
        if (!app(ModuleEmailControlService::class)->orderEnabled()) {
            throw ValidationException::withMessages([$error => 'Order email is disabled in email settings. The generated PDF remains available.']);
        }
        return $recipients;
    }

    public function send(FlowJob $job, int $poId, User $actor, string $emails): SupplierPurchaseOrder
    {
        $po = SupplierPurchaseOrder::query()->with(['supplier', 'job'])->where('flow_job_id', $job->id)->whereNull('archived_at')->findOrFail($poId);
        // Never send a second supplier instruction after confirmed delivery.
        if ($po->email_status === 'sent') return $po;
        $recipients = $this->validateSend($job, $po, $emails);
        $claimed = SupplierPurchaseOrder::query()->whereKey($po->id)->whereNull('archived_at')
            ->where('revision', (int) $po->revision)
            ->whereIn('email_status', ['not_sent', 'failed'])->update(['email_status' => 'sending']);
        if ($claimed !== 1) return $po->refresh();
        $subject = $this->subject($po);
        try {
            $message = new EmailMessage(
                to: $recipients, subject: $subject, text: $this->body($po),
                replyTo: filter_var($actor->email, FILTER_VALIDATE_EMAIL) ? [$actor->email] : [],
                attachments: [EmailMessage::storageAttachment('local', $po->pdf_path, $po->pdf_name, 'application/pdf')],
                context: ['type' => 'order', 'flow_job_id' => $po->flow_job_id, 'supplier_purchase_order_id' => $po->id],
            );
            // Synchronous send: do not claim "Sent" until the transport confirms.
            $trackingId = app(EmailService::class)->sendNow($message);
            $po->update([
                'email_status' => 'sent', 'recipient_emails' => $recipients,
                'email_subject' => $subject, 'email_tracking_id' => $trackingId,
                'email_error' => null, 'sent_at' => now(),
            ]);
            $event = 'job.supplier_purchase_order_sent';
            $description = $po->po_number.' emailed to '.count($recipients).' recipient(s).';
        } catch (\Throwable $e) {
            $po->update(['email_status' => 'failed', 'recipient_emails' => $recipients, 'email_subject' => $subject, 'email_error' => 'Email delivery failed. Please retry.']);
            $event = 'job.supplier_purchase_order_email_failed';
            $description = $po->po_number.' supplier email failed.';
        }
        $po->job->activities()->create([
            'user_id' => $actor?->id, 'event' => $event,
            'description' => $description,
            'meta' => ['supplier_purchase_order_id' => $po->id, 'recipient_count' => count($recipients)],
        ]);
        return $po->refresh();
    }
}
