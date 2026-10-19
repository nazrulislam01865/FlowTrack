<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\EmailOrderInvoice;
use App\DTOs\Email\EmailMessage;
use App\Queries\Orders\VisibleOrderQuery;
use App\Models\Invoice;
use App\Models\MasterRecord;
use App\Services\AccessControlService;
use App\Services\Email\EmailService;
use App\Services\Email\ModuleEmailControlService;
use App\Services\MasterDataService;
use App\Services\OrderFinanceService;
use App\Support\AttachmentUpload;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderFinance
{
    public function openCreateInvoice(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'finance', 422);
        $user = auth()->user();
        abort_unless(app(AccessControlService::class)->can($user, 'finance', 'create'), 403);

        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        $job->load(['items', 'client.contacts']);

        $master = app(MasterDataService::class);
        $invoiceTypes = $master->active('invoice_type');
        $currencies = $master->active('currency');
        $paymentTerms = $master->active('payment_term');
        $remoteArea = $master->remoteAreaForPostalCode($job->shipping_postal_code);

        $this->invoiceType = (string) ($invoiceTypes->firstWhere('name', 'Final invoice')?->name ?: $invoiceTypes->first()?->name ?: '');
        $jobCurrency = strtoupper((string) ($job->currency ?: 'USD'));
        $currencyValues = $currencies->mapWithKeys(fn (MasterRecord $currency) => [$currency->id => $master->currencyValue($currency)]);
        $this->invoiceCurrency = (string) ($currencyValues->first(fn ($value) => $value === $jobCurrency) ?: $currencyValues->filter()->first() ?: '');
        $this->invoiceIssueDate = now()->toDateString();
        $this->invoicePaymentTerms = (string) ($paymentTerms->firstWhere('name', 'Net 15 days')?->name ?: $paymentTerms->first()?->name ?: '');
        $this->syncInvoiceDueDate();
        $this->invoiceBillingContactId = $job->client?->contacts?->firstWhere('is_primary', true)?->id
            ?: $job->client?->contacts?->first()?->id;
        $this->invoiceLineItems = app(OrderFinanceService::class)->defaultInvoiceItems($job);
        $this->invoicePurchaseOrderReference = (string) ($job->order_number ?? '');
        $this->invoiceNotes = 'Please include the invoice number with your payment.';
        $this->invoiceTaxRate = '0';
        // These values are for the Livewire preview only. createInvoice() resolves
        // the Remote Area again from the locked Order before persisting totals.
        $this->invoiceRemoteAreaCharge = max(0, (float) ($remoteArea?->remoteAreaExtraCharge() ?? 0));
        $this->invoiceRemoteAreaName = trim((string) ($remoteArea?->name ?? ''));
        $this->invoiceRemoteAreaPostalCode = $remoteArea
            ? $master->normalizePostalCode((string) $job->shipping_postal_code)
            : '';
        $this->invoiceSupportingDocument = null;
        $this->invoiceEmailAfterCreation = false;
        $this->showCreateInvoiceModal = true;
        $this->resetValidation();
    }

    public function closeCreateInvoice(): void
    {
        $this->showCreateInvoiceModal = false;
        $this->invoiceSupportingDocument = null;
        $this->invoiceRemoteAreaCharge = 0.0;
        $this->invoiceRemoteAreaName = '';
        $this->invoiceRemoteAreaPostalCode = '';
        $this->resetValidation();
    }

    public function clearInvoiceSupportingDocument(): void
    {
        $this->invoiceSupportingDocument = null;
        $this->resetValidation('invoiceSupportingDocument');
    }

    public function updatedInvoicePaymentTerms(): void
    {
        $this->syncInvoiceDueDate();
    }

    public function updatedInvoiceIssueDate(): void
    {
        $this->syncInvoiceDueDate();
    }

    public function addInvoiceLineItem(): void
    {
        abort_unless($this->showCreateInvoiceModal, 422);
        $this->invoiceLineItems[] = ['description' => '', 'quantity' => 1, 'unit_price' => 0];
    }

    public function removeInvoiceLineItem(int $index): void
    {
        abort_unless($this->showCreateInvoiceModal, 422);
        if (count($this->invoiceLineItems) <= 1) return;
        unset($this->invoiceLineItems[$index]);
        $this->invoiceLineItems = array_values($this->invoiceLineItems);
        $this->resetValidation('invoiceLineItems');
    }

    public function createInvoice(bool $draft = false): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'finance', 422);
        $user = auth()->user();
        abort_unless(app(AccessControlService::class)->can($user, 'finance', 'create'), 403);
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        $job->load('client');

        $validated = $this->validate([
            'invoiceType' => ['required', Rule::in($this->financeMasterNames('invoice_type'))],
            'invoiceCurrency' => ['required', Rule::in($this->financeCurrencyCodes())],
            'invoicePaymentTerms' => ['required', Rule::in($this->financeMasterNames('payment_term'))],
            'invoiceIssueDate' => ['required', 'date'],
            'invoiceDueDate' => ['required', 'date', 'after_or_equal:invoiceIssueDate'],
            'invoiceBillingContactId' => ['nullable', 'integer', Rule::exists('client_contacts', 'id')->where('client_id', $job->client_id)],
            'invoicePurchaseOrderReference' => ['nullable', 'string', 'max:255'],
            'invoiceNotes' => ['nullable', 'string', 'max:5000'],
            'invoiceTaxRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'invoiceLineItems' => ['required', 'array', 'min:1'],
            'invoiceLineItems.*.description' => ['required', 'string', 'max:255'],
            'invoiceLineItems.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'invoiceLineItems.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'invoiceSupportingDocument' => AttachmentUpload::nullableRules(AttachmentUpload::FINANCE, 10240),
        ]);

        try {
            $invoice = app(OrderFinanceService::class)->createInvoice(
                $job,
                $user,
                [
                    'type' => $validated['invoiceType'],
                    'currency' => $validated['invoiceCurrency'],
                    'issue_date' => $validated['invoiceIssueDate'],
                    'due_date' => $validated['invoiceDueDate'],
                    'billing_contact_id' => $validated['invoiceBillingContactId'],
                    'purchase_order_reference' => $validated['invoicePurchaseOrderReference'] ?? '',
                    'notes' => $validated['invoiceNotes'] ?? '',
                    'tax_rate' => $validated['invoiceTaxRate'],
                ],
                $validated['invoiceLineItems'],
                $this->invoiceSupportingDocument,
                $draft,
            );
        } catch (Throwable $e) {
            $this->addError('invoiceForm', $e->getMessage());
            return;
        }

        $this->closeCreateInvoice();
        session()->flash('success', $draft ? 'Invoice saved as draft.' : 'Invoice created successfully.');
    }

    public function openRecordPayment(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'finance', 422);
        $user = auth()->user();
        abort_unless(app(AccessControlService::class)->can($user, 'finance', 'create'), 403);

        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        app(VisibleOrderQuery::class)->loadTab($job, $user, 'finance');
        $invoice = $job->invoices->first(fn ($candidate) => !in_array($candidate->status, ['draft', 'cancelled', 'paid'], true) && $candidate->balanceAmount() > 0);

        $master = app(MasterDataService::class);
        $paymentMethods = $master->active('payment_method');
        $receivedAccounts = $master->active('received_account');
        $preferredAccount = ($invoice?->currency ?: 'USD').' Operating Account';

        $this->paymentInvoiceId = $invoice?->id;
        $this->paymentDate = now()->toDateString();
        $this->paymentMethod = (string) ($paymentMethods->firstWhere('name', 'Bank transfer')?->name ?: $paymentMethods->first()?->name ?: '');
        $this->paymentAmount = $invoice ? number_format($invoice->balanceAmount(), 2, '.', '') : '';
        $this->paymentReference = '';
        $this->paymentReceivedAccount = (string) ($receivedAccounts->firstWhere('name', $preferredAccount)?->name ?: $receivedAccounts->first()?->name ?: '');
        $this->paymentReceipt = null;
        $this->paymentNotes = '';
        $this->paymentMarkInvoicePaid = true;
        $this->showRecordPaymentModal = true;
        $this->resetValidation();
    }

    public function updatedPaymentInvoiceId(): void
    {
        if (!$this->selectedJobId || !$this->paymentInvoiceId) return;
        $invoice = Invoice::query()->where('flow_job_id', $this->selectedJobId)->with('payments')->find($this->paymentInvoiceId);
        if ($invoice) {
            $this->paymentAmount = number_format($invoice->balanceAmount(), 2, '.', '');
            $receivedAccounts = app(MasterDataService::class)->active('received_account');
            $preferredAccount = ($invoice->currency ?: 'USD').' Operating Account';
            $this->paymentReceivedAccount = (string) ($receivedAccounts->firstWhere('name', $preferredAccount)?->name ?: $receivedAccounts->first()?->name ?: '');
        }
    }

    public function closeRecordPayment(): void
    {
        $this->showRecordPaymentModal = false;
        $this->paymentReceipt = null;
        $this->resetValidation();
    }

    public function clearPaymentReceipt(): void
    {
        $this->paymentReceipt = null;
        $this->resetValidation('paymentReceipt');
    }

    public function recordPayment(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'finance', 422);
        $user = auth()->user();
        abort_unless(app(AccessControlService::class)->can($user, 'finance', 'create'), 403);
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);

        $validated = $this->validate([
            'paymentInvoiceId' => ['required', 'integer', Rule::exists('invoices', 'id')->where('flow_job_id', $job->id)],
            'paymentDate' => ['required', 'date'],
            'paymentMethod' => ['required', Rule::in($this->financeMasterNames('payment_method'))],
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
            'paymentReference' => ['nullable', 'string', 'max:255'],
            'paymentReceivedAccount' => ['nullable', Rule::in($this->financeMasterNames('received_account'))],
            'paymentReceipt' => AttachmentUpload::nullableRules(AttachmentUpload::FINANCE, 10240),
            'paymentNotes' => ['nullable', 'string', 'max:3000'],
            'paymentMarkInvoicePaid' => ['boolean'],
        ]);

        try {
            app(OrderFinanceService::class)->recordPayment($job, $user, [
                'invoice_id' => $validated['paymentInvoiceId'],
                'payment_date' => $validated['paymentDate'],
                'method' => $validated['paymentMethod'],
                'amount' => $validated['paymentAmount'],
                'reference' => $validated['paymentReference'] ?? '',
                'received_account' => $validated['paymentReceivedAccount'] ?? '',
                'notes' => $validated['paymentNotes'] ?? '',
                'mark_invoice_paid' => (bool) ($validated['paymentMarkInvoicePaid'] ?? true),
            ], $this->paymentReceipt);
            $this->closeRecordPayment();
            session()->flash('success', 'Payment recorded successfully.');
        } catch (Throwable $e) {
            $this->addError('paymentForm', $e->getMessage());
        }
    }

    public function openCollectionUpdate(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'finance', 422);
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        abort_unless(app(AccessControlService::class)->canEditParentRecordModule($user, 'finance', $job), 403);
        app(VisibleOrderQuery::class)->loadTab($job, $user, 'finance');

        $this->collectionOwnerId = $job->collection?->collection_owner_id ?: $job->owner_id ?: $user->id;
        $this->collectionFollowUpDate = now()->toDateString();
        $this->collectionNextFollowUpDate = $job->collection?->next_follow_up_at?->format('Y-m-d') ?? '';
        $this->collectionNote = '';
        $this->showCollectionUpdateModal = true;
        $this->resetValidation();
    }

    public function closeCollectionUpdate(): void
    {
        $this->showCollectionUpdateModal = false;
        $this->resetValidation();
    }

    public function saveCollectionUpdate(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'finance', 422);
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        abort_unless(app(AccessControlService::class)->canEditParentRecordModule($user, 'finance', $job), 403);

        $validated = $this->validate([
            'collectionOwnerId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'collectionFollowUpDate' => ['required', 'date'],
            'collectionNextFollowUpDate' => ['nullable', 'date', 'after_or_equal:collectionFollowUpDate'],
            'collectionNote' => ['required', 'string', 'max:3000'],
        ]);

        app(OrderFinanceService::class)->addCollectionUpdate($job, $user, [
            'collection_owner_id' => $validated['collectionOwnerId'],
            'follow_up_date' => $validated['collectionFollowUpDate'],
            'next_follow_up_at' => $validated['collectionNextFollowUpDate'] ?? '',
            'note' => $validated['collectionNote'],
        ]);

        $this->closeCollectionUpdate();
        session()->flash('success', 'Collection update saved.');
    }

    public function sendPaymentReminder(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'finance', 422);
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        abort_unless(app(AccessControlService::class)->canEditParentRecordModule($user, 'finance', $job), 403);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        app(VisibleOrderQuery::class)->loadTab($job, $user, 'finance');

        $summary = app(OrderFinanceService::class)->summary($job);
        $invoice = $job->invoices->first(fn ($candidate) => $candidate->status === 'overdue' && $candidate->balanceAmount() > 0)
            ?: $job->invoices->first(fn ($candidate) => !in_array($candidate->status, ['draft', 'cancelled', 'paid'], true) && $candidate->balanceAmount() > 0);
        $email = $invoice?->billing_contact_email
            ?: $job->client?->contacts?->firstWhere('is_primary', true)?->email
            ?: $job->client?->email;

        if (!$email) {
            $this->addError('collectionForm', 'No billing email is available for this client.');
            return;
        }

        if (! app(ModuleEmailControlService::class)->orderEnabled()) {
            $this->addError('collectionForm', 'Order email service is currently disabled by an administrator.');
            return;
        }

        try {
            app(EmailService::class)->sendNow(EmailMessage::text(
                $email,
                'Payment reminder · '.$job->displayOrderNumber(),
                'Payment reminder for '.$job->displayOrderNumber().'. Outstanding balance: '.$job->currency.' '.number_format((float) $summary['outstanding'], 2).'.',
                [
                    'type' => 'payment_reminder',
                    'order_id' => (int) $job->id,
                    'invoice_id' => $invoice?->id ? (int) $invoice->id : null,
                ],
            ));
            app(OrderFinanceService::class)->addCollectionUpdate($job, $user, [
                'collection_owner_id' => $job->collection?->collection_owner_id ?: $job->owner_id ?: $user->id,
                'follow_up_date' => now()->toDateString(),
                'next_follow_up_at' => $job->collection?->next_follow_up_at?->format('Y-m-d') ?? '',
                'note' => 'Payment reminder sent to '.$email.'.',
            ], 'reminder');
            session()->flash('success', 'Payment reminder sent.');
        } catch (Throwable $e) {
            $this->addError('collectionForm', 'The reminder could not be sent: '.$e->getMessage());
        }
    }

    private function syncInvoiceDueDate(): void
    {
        if (!$this->invoiceIssueDate || trim($this->invoicePaymentTerms) === '') return;

        $days = $this->paymentTermDays($this->invoicePaymentTerms);
        if ($days === null) return;

        try {
            $this->invoiceDueDate = \Illuminate\Support\Carbon::parse($this->invoiceIssueDate)
                ->addDays($days)
                ->toDateString();
        } catch (Throwable) {
            // Validation will report an invalid date without mutating another field.
        }
    }

    /** @return array<int,string> */
    private function financeMasterNames(string $type): array
    {
        return app(MasterDataService::class)->active($type)
            ->pluck('name')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int,string> */
    private function financeCurrencyCodes(): array
    {
        $master = app(MasterDataService::class);

        return $master->active('currency')
            ->map(fn (MasterRecord $currency) => $master->currencyValue($currency))
            ->filter(fn ($value) => (bool) preg_match('/^[A-Z]{3}$/', (string) $value))
            ->unique()
            ->values()
            ->all();
    }

    private function paymentTermDays(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') return null;

        $record = app(MasterDataService::class)->active('payment_term')
            ->first(fn ($term) => trim((string) $term->name) === $value || trim((string) $term->code) === $value);
        if (!$record) return null;

        $configured = data_get($record->metadata, 'days');
        if (is_numeric($configured)) return max(0, (int) $configured);
        if (preg_match('/(\d+)/', (string) $record->name, $matches)) return max(0, (int) $matches[1]);
        if (str_contains(strtolower((string) $record->name), 'receipt')) return 0;

        return null;
    }

    private function closeFinanceModals(): void
    {
        $this->showCreateInvoiceModal = false;
        $this->showRecordPaymentModal = false;
        $this->showCollectionUpdateModal = false;
        $this->invoiceSupportingDocument = null;
    }

    private function emailInvoice(Invoice $invoice): void
    {
        app(EmailOrderInvoice::class)->handle(auth()->user(), $invoice);
    }

}
