<?php

namespace App\Services;

use App\Models\ClientContact;
use App\Models\CollectionUpdate;
use App\Models\FlowJob;
use App\Models\Invoice;
use App\Models\OrderCollection;
use App\Models\Payment;
use App\Models\User;
use App\Support\JobDetailPresenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderFinanceService
{
    private const REMOTE_AREA_SURCHARGE_PREFIX = 'Remote area surcharge';

    public function __construct(private readonly MasterDataService $masterData)
    {
    }

    public function summary(FlowJob $job): array
    {
        $invoices = $job->relationLoaded('invoices') ? $job->invoices : $job->invoices()->with('payments')->get();
        $payments = $job->relationLoaded('payments') ? $job->payments : $job->payments()->get();

        $counted = $invoices->reject(fn (Invoice $invoice) => in_array($invoice->status, ['draft', 'cancelled'], true));
        $totalInvoiced = (float) $counted->sum('total');
        $totalCollected = (float) $payments->sum('amount');
        $outstanding = max(0, $totalInvoiced - $totalCollected);
        $overdue = (float) $counted
            ->filter(fn (Invoice $invoice) => $invoice->due_date?->isBefore(today()) && $invoice->balanceAmount() > 0)
            ->sum(fn (Invoice $invoice) => $invoice->balanceAmount());

        return [
            'order_value' => (float) ($job->commercial_value ?? 0),
            'total_invoiced' => $totalInvoiced,
            'total_collected' => $totalCollected,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'collection_pct' => $totalInvoiced > 0 ? min(100, ($totalCollected / $totalInvoiced) * 100) : 0,
            'overdue_days' => $this->oldestOverdueDays($counted),
        ];
    }

    public function defaultInvoiceItems(FlowJob $job, ?float $targetTotal = null): array
    {
        // Use the same product source as Order Overview so the invoice popup
        // always mirrors the products the user can actually see on the order.
        // JobDetailPresenter also provides compatibility for older orders that
        // still store their product in the legacy flow_jobs.product columns.
        $items = JobDetailPresenter::products($job)
            ->filter(fn ($item) => !($item->is_removed ?? false))
            ->filter(fn ($item) => filled($item->product_name ?? null))
            ->values();

        $orderValue = max(0, $targetTotal ?? (float) ($job->commercial_value ?? 0));

        if ($items->isEmpty()) {
            return [[
                'description' => (string) ($job->title ?: 'Order'),
                'quantity' => max(1, (float) ($job->quantity ?: 1)),
                'unit_price' => $orderValue > 0
                    ? round($orderValue / max(1, (float) ($job->quantity ?: 1)), 2)
                    : 0,
            ]];
        }

        $totalQty = max(1, (float) $items->sum(fn ($item) => max(0.01, (float) ($item->quantity ?? 1))));
        $fallbackUnitPrice = $orderValue > 0 ? round($orderValue / $totalQty, 2) : 0;
        $hasLinePricing = $items->contains(fn ($item) => (float) ($item->unit_price ?? 0) > 0);

        return $items->map(fn ($item) => [
            'description' => trim((string) $item->product_name),
            'quantity' => max(0.01, (float) ($item->quantity ?? 1)),
            'unit_price' => $hasLinePricing
                ? round(max(0, (float) ($item->unit_price ?? 0)), 2)
                : $fallbackUnitPrice,
        ])->all();
    }

    public function createInvoice(
        FlowJob $job,
        User $actor,
        array $payload,
        array $items,
        mixed $supportingDocument = null,
        bool $draft = false,
    ): Invoice {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        return DB::transaction(function () use ($job, $actor, $payload, $items, $supportingDocument, $draft): Invoice {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $lockedJob->loadMissing('client');
            $sequence = ((int) Invoice::query()->where('flow_job_id', $job->id)->max('sequence')) + 1;
            $number = $this->invoiceNumber($lockedJob, $sequence);

            $contact = null;
            if (!empty($payload['billing_contact_id'])) {
                $contact = ClientContact::query()
                    ->where('client_id', $lockedJob->client_id)
                    ->findOrFail((int) $payload['billing_contact_id']);
            }

            // Re-resolve from the locked Order immediately before totals are
            // calculated. The browser/Livewire preview is informational only, so
            // a changed postal code or Master Data charge cannot be bypassed by a
            // stale or tampered client-side value.
            $items = $this->applyRemoteAreaSurcharge($lockedJob, $items);
            [$subtotal, $normalizedItems] = $this->normalizeInvoiceItems($items);
            $taxRate = max(0, min(100, (float) ($payload['tax_rate'] ?? 0)));
            $taxAmount = round($subtotal * ($taxRate / 100), 2);
            $grossTotal = round($subtotal + $taxAmount, 2);
            // Every invoice is its own financial document. Earlier invoices for the
            // same order and recorded payments must never reduce this invoice total.
            $total = $grossTotal;
            abort_if($total <= 0, 422, 'The invoice total must be greater than zero.');

            $invoice = Invoice::create([
                'flow_job_id' => $lockedJob->id,
                'sequence' => $sequence,
                'invoice_number' => $number,
                'type' => (string) ($payload['type'] ?? 'Final invoice'),
                'currency' => strtoupper((string) ($payload['currency'] ?? $lockedJob->currency ?? 'USD')),
                'issue_date' => $payload['issue_date'],
                'due_date' => $payload['due_date'],
                'billing_contact_id' => $contact?->id,
                'billing_contact_name' => $contact?->name ?: ($lockedJob->client?->contact_name ?? null),
                'billing_contact_email' => $contact?->email ?: ($lockedJob->client?->email ?? null),
                'purchase_order_reference' => $payload['purchase_order_reference'] ?: null,
                'notes' => $payload['notes'] ?: null,
                'company_snapshot' => app(CompanyProfileService::class)->invoiceSnapshot(),
                'client_snapshot' => app(ClientInvoiceProfileService::class)->invoiceSnapshot($lockedJob->client),
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'previously_invoiced' => 0, // Legacy compatibility only; never used in totals.
                'total' => $total,
                'status' => $draft ? 'draft' : 'sent',
                'sent_at' => $draft ? null : now(),
                'created_by' => $actor->id,
            ]);

            foreach ($normalizedItems as $index => $item) {
                $invoice->items()->create($item + ['sort_order' => $index]);
            }

            if ($supportingDocument) {
                $stored = app(SecureDocumentStorage::class)->store($supportingDocument, 'invoices/'.$lockedJob->id);
                $path = $stored['path'];
                $invoice->update([
                    'supporting_document_path' => $path,
                    'supporting_document_name' => $supportingDocument->getClientOriginalName(),
                ]);
            }

            $lockedJob->activities()->create([
                'user_id' => $actor->id,
                'event' => $draft ? 'job.invoice_drafted' : 'job.invoice_created',
                'description' => ($draft ? 'Invoice draft created: ' : 'Invoice created: ').$invoice->invoice_number,
                'meta' => ['invoice_id' => $invoice->id, 'amount' => $total, 'currency' => $invoice->currency],
            ]);

            $invoice = app(InvoicePdfService::class)->generate($invoice->load(['items', 'payments', 'billingContact']));

            return $invoice->load(['items', 'payments', 'billingContact']);
        }, 3);
    }

    public function recordPayment(FlowJob $job, User $actor, array $payload, ?UploadedFile $receipt = null): Payment
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        return DB::transaction(function () use ($job, $actor, $payload, $receipt): Payment {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()
                ->where('flow_job_id', $lockedJob->id)
                ->with('payments')
                ->lockForUpdate()
                ->findOrFail((int) $payload['invoice_id']);

            $amount = round((float) $payload['amount'], 2);
            abort_if($amount <= 0, 422, 'Payment amount must be greater than zero.');
            abort_if($amount > $invoice->balanceAmount() + 0.009, 422, 'Payment amount cannot exceed the invoice balance.');

            $sequence = ((int) Payment::query()->where('flow_job_id', $lockedJob->id)->max('sequence')) + 1;
            $receiptPath = null;
            $receiptName = null;
            if ($receipt) {
                $stored = app(SecureDocumentStorage::class)->store($receipt, 'payments/'.$lockedJob->id);
                $receiptPath = $stored['path'];
                $receiptName = $receipt->getClientOriginalName();
            }

            $payment = Payment::create([
                'flow_job_id' => $lockedJob->id,
                'invoice_id' => $invoice->id,
                'sequence' => $sequence,
                'payment_number' => $this->paymentNumber($lockedJob, $sequence),
                'payment_date' => $payload['payment_date'],
                'method' => (string) $payload['method'],
                'amount' => $amount,
                'reference' => $payload['reference'] ?: null,
                'received_account' => $payload['received_account'] ?: null,
                'receipt_path' => $receiptPath,
                'receipt_name' => $receiptName,
                'notes' => $payload['notes'] ?: null,
                'recorded_by' => $actor->id,
            ]);

            $refreshedInvoice = $invoice->refresh()->load('payments');
            if (($payload['mark_invoice_paid'] ?? true) || $refreshedInvoice->balanceAmount() > 0.009) {
                $this->refreshInvoiceStatus($refreshedInvoice);
            } elseif ($refreshedInvoice->status === 'paid') {
                $refreshedInvoice->update(['status' => 'sent']);
            }

            $lockedJob->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.payment_recorded',
                'description' => 'Payment recorded: '.$payment->payment_number.' for '.$invoice->invoice_number,
                'meta' => ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount],
            ]);

            return $payment->load(['invoice', 'recorder']);
        }, 3);
    }

    public function addCollectionUpdate(FlowJob $job, User $actor, array $payload, string $type = 'update'): OrderCollection
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        return DB::transaction(function () use ($job, $actor, $payload, $type): OrderCollection {
            $collection = OrderCollection::query()->firstOrCreate(
                ['flow_job_id' => $job->id],
                ['collection_owner_id' => $job->owner_id ?: $actor->id]
            );

            $followUpDate = $payload['follow_up_date'] ?? now()->toDateString();
            $collection->update([
                'collection_owner_id' => $payload['collection_owner_id'] ?: $collection->collection_owner_id,
                'last_follow_up_at' => $followUpDate,
                'next_follow_up_at' => $payload['next_follow_up_at'] ?: null,
                'latest_note' => trim((string) $payload['note']),
            ]);

            CollectionUpdate::create([
                'flow_job_collection_id' => $collection->id,
                'actor_id' => $actor->id,
                'follow_up_date' => $followUpDate,
                'next_follow_up_at' => $payload['next_follow_up_at'] ?: null,
                'note' => trim((string) $payload['note']),
                'type' => $type,
            ]);

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.collection_updated',
                'description' => $type === 'reminder' ? 'Payment reminder sent.' : 'Collection update added.',
            ]);

            return $collection->refresh()->load(['owner', 'updates.actor']);
        }, 3);
    }

    public function refreshInvoiceStatus(Invoice $invoice): Invoice
    {
        if (in_array($invoice->status, ['draft', 'cancelled'], true)) return $invoice;

        $balance = $invoice->balanceAmount();
        $collected = $invoice->collectedAmount();
        $status = 'sent';
        if ($balance <= 0.009) $status = 'paid';
        elseif ($invoice->due_date?->isBefore(today())) $status = 'overdue';
        elseif ($collected > 0) $status = 'partial';

        if ($invoice->status !== $status) $invoice->update(['status' => $status]);
        return $invoice;
    }

    public function syncStatuses(Collection $invoices): void
    {
        $invoices->each(fn (Invoice $invoice) => $this->refreshInvoiceStatus($invoice));
    }

    public function deleteSupportingDocument(Invoice $invoice): void
    {
        if ($invoice->flow_job_id) app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $invoice->flow_job_id);
        if ($invoice->supporting_document_path) app(SecureDocumentStorage::class)->delete($invoice->supporting_document_path);
    }

    /**
     * Append the authoritative Remote Area surcharge as an immutable invoice
     * line item. A matching area with no positive charge still displays its UI
     * flag, but intentionally does not change the invoice total.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function applyRemoteAreaSurcharge(FlowJob $job, array $items): array
    {
        // The surcharge description is a reserved system line. Strip any
        // browser-supplied copy first, even when the current postal code is no
        // longer remote. The authoritative line below is the only surcharge
        // allowed to affect the persisted invoice.
        $prefix = mb_strtolower(self::REMOTE_AREA_SURCHARGE_PREFIX);
        $items = array_values(array_filter($items, function (array $item) use ($prefix): bool {
            $description = mb_strtolower(trim((string) ($item['description'] ?? '')));
            return ! str_starts_with($description, $prefix);
        }));

        $remoteArea = $this->masterData->remoteAreaForPostalCode($job->shipping_postal_code);
        $charge = $remoteArea?->remoteAreaExtraCharge();
        if (! $remoteArea || $charge === null || $charge <= 0) return $items;

        $description = self::REMOTE_AREA_SURCHARGE_PREFIX;
        $areaName = trim((string) $remoteArea->name);
        $postalCode = $this->masterData->normalizePostalCode((string) $job->shipping_postal_code);
        if ($areaName !== '') $description .= ' - '.$areaName;
        if ($postalCode !== '') $description .= ' ('.$postalCode.')';

        $items[] = [
            // invoice_items.description is VARCHAR(255). Guard against a very
            // long Master Data name turning invoice generation into a DB error.
            'description' => mb_substr($description, 0, 255),
            'quantity' => 1,
            'unit_price' => round($charge, 2),
        ];

        return $items;
    }

    private function normalizeInvoiceItems(array $items): array
    {
        $normalized = [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') continue;
            $quantity = max(0.01, (float) ($item['quantity'] ?? 0));
            $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));
            $amount = round($quantity * $unitPrice, 2);
            $subtotal += $amount;
            $normalized[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'amount' => $amount,
            ];
        }
        abort_if($normalized === [], 422, 'Add at least one invoice item.');
        abort_if($subtotal <= 0, 422, 'Invoice total must be greater than zero.');
        return [round($subtotal, 2), $normalized];
    }

    private function invoiceNumber(FlowJob $job, int $sequence): string
    {
        return sprintf('INV-%05d-%02d', (int) $job->id, $sequence);
    }

    private function paymentNumber(FlowJob $job, int $sequence): string
    {
        return sprintf('PAY-%05d-%02d', (int) $job->id, $sequence);
    }

    private function oldestOverdueDays(Collection $invoices): int
    {
        $oldestDue = $invoices
            ->filter(fn (Invoice $invoice) => $invoice->due_date?->isBefore(today()) && $invoice->balanceAmount() > 0)
            ->min(fn (Invoice $invoice) => $invoice->due_date?->timestamp);
        if (!$oldestDue) return 0;
        return Carbon::createFromTimestamp($oldestDue)->startOfDay()->diffInDays(today());
    }
}
