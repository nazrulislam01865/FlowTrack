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
use App\Services\OrderWorkflowActionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Supplier-facing procurement POs; intentionally separate from the customer PO upload. */
final class SupplierPurchaseOrderService
{
    /** @var array<int, MasterRecord|null> */
    private array $supplierCache = [];

    private function assertTask(Task $task): void
    {
        abort_unless(app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_CREATE_SUPPLIER_PO', 422);
    }

    public function rows(Task $task): array
    {
        $this->assertTask($task);
        return SupplierPurchaseOrder::query()
            ->where('flow_job_id', $task->flow_job_id)->where('task_id', $task->id)
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
            ])->all();
    }

    public function products(Task $task): array
    {
        $this->assertTask($task);
        $items = FlowJobItem::query()->where('flow_job_id', $task->flow_job_id)
            ->where('is_removed', false)->with(['supplier', 'catalogProduct'])->orderBy('sort_order')->orderBy('id')->get();
        return $items->map(fn (FlowJobItem $item) => [
            'id' => $item->id,
            'name' => $item->product_name,
            'quantity' => (int) $item->quantity,
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
    public function generate(Task $task, User $actor, string $mode, array $splitItemIds, array $templateDetails = []): array
    {
        $this->assertTask($task);
        abort_unless(in_array($mode, ['single', 'split'], true), 422);
        app(OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $templateKeys = [
            'carton_buyer', 'carton_style', 'carton_colour', 'carton_size', 'carton_qty', 'carton_gross_weight',
            'carton_net_weight', 'carton_measurement', 'carton_other', 'carton_mark_type',
            'carton_strength', 'carton_strength_other', 'inner_packing', 'pieces_per_inner', 'inner_packing_other',
            'payment_method', 'deposit_percent', 'balance_percent', 'payment_other',
            'payment_term', 'beneficiary', 'bank_name', 'bank_address', 'account_number', 'swift_code',
        ];
        $templateDetails = array_intersect_key($templateDetails, array_flip($templateKeys));
        $templateDetails = array_map(fn ($value) => trim((string) $value), $templateDetails);

        $job = FlowJob::query()->with(['client', 'items.supplier', 'items.catalogProduct'])->findOrFail($task->flow_job_id);
        $items = $job->items->reject(fn (FlowJobItem $item) => $item->is_removed)->values();
        if ($items->isEmpty()) throw ValidationException::withMessages(['orderWorkflowActionPayload' => 'Add at least one active Order product before creating a PO.']);

        $knownIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $splitIds = array_map('intval', $splitItemIds);
        if (count($splitIds) !== count(array_unique($splitIds)) || array_diff($splitIds, $knownIds)) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_item_ids' => 'Select valid products from this Order.']);
        }
        if ($mode === 'split' && (count($items) < 2 || !$splitIds)) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.supplier_po_item_ids' => 'Select at least one product to split from the other Order products.']);
        }

        // Each selected product receives its own PO. Keep every unselected
        // product as well, grouped by supplier so dispatch can pick a real
        // supplier email instead of mixing unrelated suppliers on a PO.
        $groups = $mode === 'single' ? [$items] : [];
        if ($mode === 'split') {
            foreach ($splitIds as $id) $groups[] = $items->filter(fn ($item) => $item->id === $id)->values();
            $remaining = $items->reject(fn ($item) => in_array($item->id, $splitIds, true))->values();
            foreach ($remaining->groupBy(fn (FlowJobItem $item) => 'supplier-'.($this->supplierFor($item)?->id ?: 'unassigned')) as $supplierItems) {
                $groups[] = $supplierItems->values();
            }
        }

        // Exactly one active PO generation per artwork revision cycle. A new
        // revision archives prior instructions before allowing regeneration.
        $writtenPaths = [];
        try {
            return DB::transaction(function () use ($task, $actor, $job, $groups, $mode, $templateDetails, &$writtenPaths): array {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            // One indexed read checks whether the latest generation is still
            // active and supplies a unique revision suffix for regenerated POs.
            $previous = SupplierPurchaseOrder::query()->where('task_id', $locked->id)
                ->orderByDesc('id')->first(['po_number', 'archived_at']);
            abort_unless(!$previous || $previous->archived_at, 422, 'Purchase orders have already been generated.');
            // Easy-to-read revision numbering: original, R2, R3, ...
            $lastRevision = 1;
            if ($previous && preg_match('/-R(\d+)-\d+$/', (string) $previous->po_number, $matches)) {
                $lastRevision = (int) $matches[1];
            }
            $numberSuffix = $previous ? '-R'.($lastRevision + 1) : '';
            // Existing Orders may have progressed beyond Artwork before this
            // required task was introduced. The binding service marks newly
            // inserted past-stage tasks completed to protect history; allow
            // their PO documents to be created retroactively, without
            // reopening or advancing any already-completed workflow stage.
            if (!$locked->completed_at) {
                app(\App\Services\OrderTaskSequenceService::class)->assertStatusActionable($locked);
            }
            $batch = (string) Str::uuid();
            $created = [];
            foreach ($groups as $index => $group) {
                $groupSuppliers = $group->map(fn (FlowJobItem $item) => $this->supplierFor($item));
                $suppliers = $groupSuppliers->map(fn (?MasterRecord $supplier) => $supplier?->id)->unique();
                $supplierId = $suppliers->count() === 1 ? $suppliers->first() : null;
                $supplierName = $supplierId ? (string) ($groupSuppliers->first(fn (?MasterRecord $supplier) => $supplier?->id === $supplierId)?->name ?: '') : '';
                $snapshot = $group->map(fn (FlowJobItem $item) => [
                    'item_id' => (int) $item->id,
                    'name' => (string) $item->product_name,
                    'sku' => (string) ($item->catalogProduct?->code ?: ''),
                    'quantity' => max(0, (int) $item->quantity),
                    // Never reveal client selling price on a supplier purchase order.
                    'supplier_unit_cost' => $item->supplier_unit_price === null ? null : (float) $item->supplier_unit_price,
                    'supplier_id' => $this->supplierFor($item)?->id,
                ])->values()->all();
                $number = 'SPO-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT).$numberSuffix.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $po = SupplierPurchaseOrder::query()->create([
                    'flow_job_id' => $job->id, 'task_id' => $locked->id,
                    'supplier_id' => $supplierId, 'created_by' => $actor->id,
                    'po_number' => $number, 'batch_uuid' => $batch,
                    'mode' => $mode, 'items_snapshot' => $snapshot, 'template_details' => $templateDetails,
                    'pdf_path' => 'supplier-purchase-orders/'.$job->id.'/'.$batch.'/'.$number.'.pdf',
                    'pdf_name' => $number.'.pdf',
                ]);
                $bytes = $this->pdf($po, $job, $supplierName);
                $writtenPaths[] = $po->pdf_path;
                if (!Storage::disk('local')->put($po->pdf_path, $bytes)) throw new \RuntimeException('Unable to save the Purchase Order PDF.');
                $document = $job->documents()->create([
                    'task_id' => $locked->id,
                    'client_id' => $job->client_id,
                    'uploaded_by' => $actor->id,
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
                'user_id' => $actor->id, 'event' => 'job.supplier_purchase_orders_generated',
                'description' => count($created).' supplier purchase order(s) generated.',
                'meta' => ['task_id' => $locked->id, 'po_ids' => collect($created)->pluck('id')->all(), 'mode' => $mode],
            ]);
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

    /** Archive the prior PO generation without deleting its PDFs or send audit. */
    public function archiveForArtworkRevision(Task $task): void
    {
        $this->assertTask($task);
        $active = SupplierPurchaseOrder::query()->where('task_id', $task->id)
            ->where('flow_job_id', $task->flow_job_id)->whereNull('archived_at');
        $rows = (clone $active)->get(['id', 'document_id']);
        if ($rows->isEmpty()) return;
        $active->update(['archived_at' => now()]);
        $documentIds = $rows->pluck('document_id')->filter();
        if ($documentIds->isNotEmpty()) {
            // Keep historical documents visible in a separate Archived section.
            Document::query()->whereIn('id', $documentIds)->where('task_id', $task->id)
                ->update(['category' => 'Archived Supplier Purchase Order']);
        }
    }

    private function pdf(SupplierPurchaseOrder $po, FlowJob $job, string $supplierName): string
    {
        return app(SupplierPurchaseOrderTemplatePdf::class)->render(
            $po->po_number,
            $supplierName,
            strtoupper((string) ($job->currency ?: '')),
            (array) $po->items_snapshot,
            ($job->supplier_delivery_date ?: $job->delivery_date)
                ? \Illuminate\Support\Carbon::parse($job->supplier_delivery_date ?: $job->delivery_date)->format('d M Y')
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
    public function validateSend(Task $task, SupplierPurchaseOrder $po, string $emails): array
    {
        $this->assertTask($task);
        abort_unless((int) $po->flow_job_id === (int) $task->flow_job_id
            && (int) $po->task_id === (int) $task->id, 403);
        app(OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
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

    public function send(Task $task, int $poId, User $actor, string $emails): SupplierPurchaseOrder
    {
        $this->assertTask($task);
        $po = SupplierPurchaseOrder::query()->with(['supplier', 'job'])->where('task_id', $task->id)
            ->where('flow_job_id', $task->flow_job_id)->whereNull('archived_at')->findOrFail($poId);
        // Never send a second supplier instruction after confirmed delivery.
        if ($po->email_status === 'sent') return $po;
        $recipients = $this->validateSend($task, $po, $emails);
        $claimed = SupplierPurchaseOrder::query()->whereKey($po->id)->whereNull('archived_at')
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
            'user_id' => $actor->id, 'event' => $event,
            'description' => $description,
            'meta' => ['task_id' => $task->id, 'supplier_purchase_order_id' => $po->id, 'recipient_count' => count($recipients)],
        ]);
        return $po->refresh();
    }
}
