<?php

namespace App\Services\Orders;

use App\DTOs\Email\EmailMessage;
use App\Exceptions\EmailDeliveryException;
use App\Models\FlowJob;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\CompanyProfileService;
use App\Services\Email\EmailService;
use App\Services\Email\ModuleEmailControlService;
use App\Services\InvoicePdfService;
use App\Services\OrderFinanceService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Billing-stage invoice generation, preview and delivery.
 *
 * This keeps the workflow task tied to the canonical Invoice/PDF records used
 * everywhere else in FlowTrack instead of maintaining a second activity-only
 * invoice representation.
 */
final class OrderInvoiceWorkflowEmailService
{
    public function __construct(
        private readonly OrderFinanceService $finance,
        private readonly InvoicePdfService $pdf,
        private readonly EmailService $email,
        private readonly ModuleEmailControlService $emailControl,
        private readonly BrandingService $branding,
        private readonly CompanyProfileService $companyProfile,
        private readonly OrderWorkflowEmailService $workflowEmailDirectory,
    ) {
    }

    public function preparedInvoice(FlowJob $job): ?Invoice
    {
        $prepared = $job->relationLoaded('workflowInvoiceActivities')
            ? $job->workflowInvoiceActivities->sortByDesc('id')->first()
            : $job->activities()
                ->where('event', 'job.workflow_invoice_prepared')
                ->latest('id')
                ->first();
        $loadedInvoices = $job->relationLoaded('invoices') ? $job->invoices : null;

        $invoiceId = (int) data_get($prepared?->meta, 'invoice_id', 0);
        if ($invoiceId > 0) {
            $invoice = $loadedInvoices?->firstWhere('id', $invoiceId)
                ?: Invoice::query()->where('flow_job_id', $job->id)->find($invoiceId);
            if ($invoice) return $invoice;
        }

        $invoiceNumber = trim((string) data_get($prepared?->meta, 'invoice_number', ''));
        if ($invoiceNumber !== '') {
            $invoice = $loadedInvoices?->firstWhere('invoice_number', $invoiceNumber)
                ?: Invoice::query()
                    ->where('flow_job_id', $job->id)
                    ->where('invoice_number', $invoiceNumber)
                    ->first();
            if ($invoice) return $invoice;
        }

        // When a workflow-prepared activity exists but cannot be linked to an
        // Invoice record, it is the legacy activity-only case. Return null so
        // ensurePreparedInvoice() materializes that exact workflow invoice
        // instead of ever attaching an unrelated Finance-module draft.
        if ($prepared) return null;

        if ($loadedInvoices) return $loadedInvoices->sortByDesc('id')->first();

        return Invoice::query()
            ->where('flow_job_id', $job->id)
            ->latest('id')
            ->first();
    }

    /** @param array<string,mixed> $payload */
    public function prepare(FlowJob $job, User $actor, array $payload): Invoice
    {
        app(OrderHoldService::class)->assertNotHeld($job);

        $amount = round((float) ($payload['invoice_amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.invoice_amount' => 'Invoice amount must be greater than zero.',
            ]);
        }

        $items = $this->finance->defaultInvoiceItems($job, $amount);
        $itemsTotal = round((float) collect($items)->sum(
            fn (array $item) => (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0),
        ), 2);
        if (abs($itemsTotal - $amount) > 0.009) {
            // If the user intentionally changes the workflow invoice amount,
            // never let product-line arithmetic silently create a different PDF
            // total. Keep one exact order line in that uncommon case.
            $items = [[
                'description' => 'Order '.$job->displayOrderNumber(),
                'quantity' => 1,
                'unit_price' => $amount,
            ]];
        }

        $invoice = $this->finance->createInvoice(
            $job,
            $actor,
            [
                'type' => 'Final invoice',
                'currency' => strtoupper(trim((string) ($payload['invoice_currency'] ?? 'USD'))) ?: 'USD',
                'issue_date' => (string) ($payload['invoice_date'] ?? ''),
                'due_date' => (string) ($payload['invoice_due_date'] ?? ''),
                'billing_contact_id' => null,
                'purchase_order_reference' => trim((string) ($job->order_number ?: $job->job_number)),
                'notes' => 'Payment terms: '.(trim((string) ($payload['payment_terms'] ?? '')) ?: 'As agreed'),
                'tax_rate' => 0,
            ],
            $items,
            null,
            true,
        );

        return $this->pdf->ensure($invoice);
    }

    /**
     * Materialize an Invoice/PDF for orders whose Task 6.1 was completed by the
     * older activity-only implementation. This is intentionally idempotent.
     */
    public function ensurePreparedInvoice(FlowJob $job, User $actor): Invoice
    {
        if ($invoice = $this->preparedInvoice($job)) {
            return $this->pdf->ensure($invoice);
        }

        $prepared = $job->activities()
            ->where('event', 'job.workflow_invoice_prepared')
            ->latest('id')
            ->first();

        abort_unless($prepared, 422, 'Prepare the invoice before sending it.');

        $meta = (array) ($prepared->meta ?? []);
        $storedRemoteAreaCharge = max(0, (float) ($meta['remote_area_charge'] ?? 0));
        $baseAmount = array_key_exists('invoice_base_amount', $meta)
            ? (float) $meta['invoice_base_amount']
            : max(0, (float) ($meta['invoice_amount'] ?? 0) - $storedRemoteAreaCharge);
        $invoice = $this->prepare($job, $actor, [
            'invoice_amount' => $baseAmount,
            'invoice_currency' => $meta['invoice_currency'] ?? 'USD',
            'invoice_date' => $meta['invoice_date'] ?? now()->toDateString(),
            'invoice_due_date' => $meta['invoice_due_date'] ?? now()->addDays(30)->toDateString(),
            'payment_terms' => $meta['payment_terms'] ?? 'Net 30',
        ]);

        $prepared->update([
            'description' => 'Invoice '.$invoice->invoice_number.' prepared.',
            'meta' => array_merge($meta, [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'invoice_base_amount' => $baseAmount,
                'remote_area_charge' => max(0, (float) $invoice->total - $baseAmount),
                'invoice_amount' => (float) $invoice->total,
                'invoice_currency' => (string) $invoice->currency,
                'invoice_date' => $invoice->issue_date?->format('Y-m-d'),
                'invoice_due_date' => $invoice->due_date?->format('Y-m-d'),
            ]),
        ]);

        return $invoice;
    }

    /** @param array<string,mixed> $selection @return array<string,mixed> */
    public function preview(Task $task, ?User $actor = null, array $selection = [], bool $includeHtml = true): array
    {
        $job = $task->job ?: FlowJob::query()->find($task->flow_job_id);
        if (! $job) return [];

        $job->loadMissing(['client', 'owner', 'coordinator', 'items']);
        $invoice = $this->preparedInvoice($job);
        if (! $invoice) return [];

        $invoice = $this->pdf->ensure($invoice->loadMissing(['items', 'payments', 'billingContact']));
        $actor ??= auth()->user() instanceof User ? auth()->user() : ($job->owner ?: $job->coordinator);
        $toEmail = trim((string) ($selection['to_email'] ?? $invoice->billing_contact_email ?? $job->client?->email ?? ''));
        $defaultToName = trim((string) ($job->client?->billing_recipient ?: $invoice->billing_contact_name ?: $job->client?->contact_name ?: $job->client?->name ?: 'Client accounts contact'));
        $ccEmails = $this->parseEmails((string) ($selection['cc_emails'] ?? ''), false);
        $recipientOptions = collect($this->workflowEmailDirectory->activeSystemUserRecipientOptions());
        $matchedToUser = $recipientOptions->first(
            fn (array $option) => mb_strtolower(trim((string) ($option['email'] ?? ''))) === mb_strtolower($toEmail)
        );
        $subject = $this->subject($job, $invoice);
        $brand = $this->companyBrand();
        $viewData = $includeHtml
            ? $this->viewData($job, $invoice, $actor, $brand)
            : [];
        $html = $includeHtml
            ? view('emails.orders.workflow-handoff', $viewData)->render()
            : '';
        $emailServiceEnabled = $this->emailControl->orderEnabled();

        $recipients = [];
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = [
                'name' => (string) ($matchedToUser['name'] ?? $defaultToName),
                'email' => $toEmail,
                'external' => ! (bool) $matchedToUser,
            ];
        }

        $ccRecipients = collect($ccEmails)->map(function (string $email) use ($recipientOptions): array {
            $matched = $recipientOptions->first(
                fn (array $option) => mb_strtolower(trim((string) ($option['email'] ?? ''))) === mb_strtolower($email)
            );

            return [
                'name' => (string) ($matched['name'] ?? 'CC recipient'),
                'email' => $email,
                'external' => ! (bool) $matched,
            ];
        })->values();

        return [
            'recipients' => $recipients,
            'cc_recipients' => $ccRecipients->all(),
            'recipient_options' => $recipientOptions->values()->all(),
            'recipient_count' => count($recipients) + $ccRecipients->count(),
            'recipient_source' => 'Client billing email — active system users are suggested as you type; external email addresses are also allowed',
            'empty_recipient_message' => 'Enter the client billing email in To before sending this invoice.',
            'subject' => $subject,
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'invoice' => [
                'id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'currency' => (string) $invoice->currency,
                'total' => (float) $invoice->total,
                'issue_date_label' => $invoice->issue_date?->format('M j, Y') ?: '—',
                'due_date_label' => $invoice->due_date?->format('M j, Y') ?: '—',
                'billing_contact_name' => (string) ($invoice->billing_contact_name ?: ''),
                'pdf_name' => (string) ($invoice->pdf_name ?: $invoice->invoice_number.'.pdf'),
                'open_url' => route('invoices.pdf.open', $invoice),
                'download_url' => route('invoices.pdf.download', $invoice),
            ],
            'document_name' => (string) ($invoice->pdf_name ?: $this->pdf->filename($invoice)),
            'documents' => [[
                'id' => (int) $invoice->id,
                'name' => (string) ($invoice->pdf_name ?: $this->pdf->filename($invoice)),
            ]],
            'from_name' => $this->senderName($brand),
            'from_address' => $this->senderAddress(),
            'reply_to' => $actor && filter_var((string) $actor->email, FILTER_VALIDATE_EMAIL) ? (string) $actor->email : '',
            'email_service_enabled' => $emailServiceEnabled,
            'delivery' => $emailServiceEnabled ? $this->deliveryLabel() : 'Order email service disabled',
            'html' => $html,
        ];
    }

    /** @param array<string,mixed> $selection */
    public function send(Task $task, User $actor, array $selection = []): string
    {
        app(OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);

        $job = FlowJob::query()
            ->with(['client', 'owner', 'coordinator', 'items'])
            ->findOrFail($task->flow_job_id);
        $invoice = $this->ensurePreparedInvoice($job, $actor)->loadMissing(['items', 'payments', 'billingContact']);

        $toEmail = trim((string) ($selection['to_email'] ?? $invoice->billing_contact_email ?? $job->client?->email ?? ''));
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.to_email' => 'Enter a valid client billing email address in To.',
            ]);
        }
        $ccEmails = $this->parseEmails((string) ($selection['cc_emails'] ?? ''), true);
        $ccEmails = collect($ccEmails)
            ->reject(fn (string $email) => mb_strtolower($email) === mb_strtolower($toEmail))
            ->values()
            ->all();

        $invoice = $this->pdf->ensure($invoice);
        $path = trim((string) $invoice->pdf_path);
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages([
                'orderWorkflowActionEmail' => 'The generated invoice PDF could not be found. Generate the invoice again before sending.',
            ]);
        }

        // Billing follows the same lifecycle rule as the other Order email
        // handoffs: an administrator disabling email must never block the
        // operational workflow. Persist the intended recipients so the
        // completed task can be resent later when email is available again.
        if (! $this->emailControl->orderEnabled()) {
            $trackingId = 'disabled-'.Str::uuid();

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.workflow_invoice_email_skipped',
                'description' => 'Invoice email was not sent because the Order email service is disabled.',
                'meta' => [
                    'task_id' => (int) $task->id,
                    'invoice_id' => (int) $invoice->id,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'to_email' => $toEmail,
                    'to_emails' => [$toEmail],
                    'cc_emails' => $ccEmails,
                    'tracking_id' => $trackingId,
                    'email_service_disabled' => true,
                ],
            ]);

            $invoice->update(['billing_contact_email' => $toEmail]);

            return $trackingId;
        }

        $brand = $this->companyBrand();
        $replyTo = filter_var((string) $actor->email, FILTER_VALIDATE_EMAIL) ? [(string) $actor->email] : [];
        $message = new EmailMessage(
            to: $toEmail,
            cc: $ccEmails,
            subject: $this->subject($job, $invoice),
            view: 'emails.orders.workflow-handoff',
            viewData: $this->viewData($job, $invoice, $actor, $brand),
            replyTo: $replyTo,
            attachments: [EmailMessage::storageAttachment(
                'local',
                $path,
                (string) ($invoice->pdf_name ?: $this->pdf->filename($invoice)),
                'application/pdf',
            )],
            context: [
                'type' => 'order_invoice',
                'reference' => $job->displayOrderNumber(),
                'flow_job_id' => (int) $job->id,
                'task_id' => (int) $task->id,
                'invoice_id' => (int) $invoice->id,
            ],
        );

        // Attempt delivery synchronously so the workflow can persist an exact
        // Sent/Failed state before the task is completed.
        $trackingId = $this->email->sendNow($message);

        $invoice->update([
            'billing_contact_email' => $toEmail,
            'status' => 'sent',
            'sent_at' => $invoice->sent_at ?: now(),
            'emailed_at' => now(),
        ]);

        return $trackingId;
    }

    /**
     * Persistent delivery state for the completed Billing > Send Invoice task.
     * Email delivery and task completion are deliberately separate so an email
     * outage cannot hold the Order workflow hostage.
     *
     * @return array{status:?string,label:?string,to_emails:array<int,string>,cc_emails:array<int,string>,tracking_id:string,resendable:bool}
     */
    public function deliveryStatus(Task $task): array
    {
        $job = $task->job ?: FlowJob::query()->find($task->flow_job_id);
        if (! $job) {
            return ['status' => null, 'label' => null, 'to_emails' => [], 'cc_emails' => [], 'tracking_id' => '', 'resendable' => false];
        }

        $activities = $job->relationLoaded('workflowEmailActivities')
            ? collect($job->getRelation('workflowEmailActivities'))
            : $job->activities()
                ->whereIn('event', [
                    'job.workflow_invoice_sent',
                    'job.workflow_invoice_email_failed',
                    'job.workflow_invoice_email_skipped',
                ])
                ->latest('id')
                ->get();

        $latest = $activities
            ->filter(fn ($activity) => (int) data_get($activity->meta, 'task_id', 0) === (int) $task->id)
            ->filter(fn ($activity) => in_array((string) $activity->event, [
                'job.workflow_invoice_sent',
                'job.workflow_invoice_email_failed',
                'job.workflow_invoice_email_skipped',
            ], true))
            ->sortByDesc('id')
            ->first();

        if (! $latest) {
            return ['status' => null, 'label' => null, 'to_emails' => [], 'cc_emails' => [], 'tracking_id' => '', 'resendable' => false];
        }

        $status = match ((string) $latest->event) {
            'job.workflow_invoice_sent' => 'sent',
            'job.workflow_invoice_email_failed' => 'failed',
            'job.workflow_invoice_email_skipped' => 'not_sent',
            default => null,
        };

        $savedTo = (array) data_get($latest->meta, 'to_emails', []);
        if ($savedTo === []) {
            $singleTo = trim((string) data_get($latest->meta, 'to_email', ''));
            if ($singleTo !== '') $savedTo = [$singleTo];
        }

        $toEmails = collect($savedTo)
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
        $ccEmails = collect((array) data_get($latest->meta, 'cc_emails', []))
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();

        return [
            'status' => $status,
            'label' => match ($status) {
                'sent' => 'Sent',
                'failed' => 'Failed',
                'not_sent' => 'Not Sent',
                default => null,
            },
            'to_emails' => $toEmails,
            'cc_emails' => $ccEmails,
            'tracking_id' => (string) data_get($latest->meta, 'tracking_id', ''),
            'resendable' => $toEmails !== [],
        ];
    }

    /** @param array<string,mixed> $selection */
    public function recordSuccessfulDelivery(Task $task, User $actor, array $selection, string $trackingId): void
    {
        $job = FlowJob::query()->findOrFail($task->flow_job_id);
        $invoice = $this->preparedInvoice($job);
        $toEmail = trim((string) ($selection['to_email'] ?? $invoice?->billing_contact_email ?? ''));
        $ccEmails = $this->parseEmails((string) ($selection['cc_emails'] ?? ''), false);

        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.workflow_invoice_sent',
            'description' => 'Invoice '.($invoice?->invoice_number ?: '').' emailed to the client.',
            'meta' => [
                'task_id' => (int) $task->id,
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'to_email' => $toEmail,
                'to_emails' => $toEmail !== '' ? [$toEmail] : [],
                'cc_emails' => $ccEmails,
                'tracking_id' => $trackingId,
            ],
        ]);
    }

    /** @param array<string,mixed> $selection */
    public function recordFailedDelivery(Task $task, User $actor, array $selection, EmailDeliveryException $exception): void
    {
        $job = FlowJob::query()->findOrFail($task->flow_job_id);
        $invoice = $this->preparedInvoice($job);
        $toEmail = trim((string) ($selection['to_email'] ?? $invoice?->billing_contact_email ?? ''));
        $ccEmails = $this->parseEmails((string) ($selection['cc_emails'] ?? ''), false);
        $trackingId = '';
        if (preg_match('/Reference:\s*([A-Za-z0-9-]+)/', $exception->getMessage(), $matches) === 1) {
            $trackingId = trim((string) ($matches[1] ?? ''));
        }

        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.workflow_invoice_email_failed',
            'description' => 'Invoice email delivery failed. The Billing task was completed and the email can be resent.',
            'meta' => [
                'task_id' => (int) $task->id,
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'to_email' => $toEmail,
                'to_emails' => $toEmail !== '' ? [$toEmail] : [],
                'cc_emails' => $ccEmails,
                'tracking_id' => $trackingId,
                'delivery_attempts' => 1,
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function resendCompleted(Task $task, User $actor): string
    {
        abort_unless($task->completed_at || strcasecmp(trim((string) $task->status), 'Completed') === 0, 422, 'Only a completed Send Invoice task can be resent.');
        abort_unless($this->emailControl->orderEnabled(), 422, 'Order email sending is currently disabled. Enable it before resending the invoice.');

        $delivery = $this->deliveryStatus($task);
        $toEmail = trim((string) collect($delivery['to_emails'] ?? [])->first());
        abort_if($toEmail === '', 422, 'This completed invoice task has no saved recipient to resend to.');

        $selection = [
            'to_email' => $toEmail,
            'cc_emails' => collect($delivery['cc_emails'] ?? [])->implode(', '),
        ];

        try {
            $trackingId = $this->send($task, $actor, $selection);
        } catch (EmailDeliveryException $exception) {
            $this->recordFailedDelivery($task, $actor, $selection, $exception);
            throw $exception;
        }

        $this->recordSuccessfulDelivery($task, $actor, $selection, $trackingId);

        return $trackingId;
    }

    /** @return list<string> */
    private function parseEmails(string $value, bool $validate): array
    {
        $emails = collect($value === '' ? [] : preg_split('/[\s,;]+/', trim($value)))
            ->map(fn ($email) => trim((string) $email))
            ->filter()
            ->unique(fn ($email) => mb_strtolower((string) $email))
            ->values();

        if ($validate && $emails->count() > 10) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cc_emails' => 'Add no more than 10 CC email addresses.',
            ]);
        }

        $invalid = $emails->first(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) === false);
        if ($validate && $invalid) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cc_emails' => $invalid.' is not a valid CC email address.',
            ]);
        }

        return $emails
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->all();
    }

    /** @return array<string,mixed> */
    private function viewData(FlowJob $job, Invoice $invoice, ?User $actor, array $brand): array
    {
        return [
            'brand' => $brand,
            'job' => $job,
            'invoice' => $invoice,
            'sentBy' => $actor,
            'team' => trim((string) ($job->client?->billing_recipient ?: $invoice->billing_contact_name ?: $job->client?->contact_name ?: $job->client?->name ?: 'there')),
            'handoffType' => 'invoice',
            'document' => null,
            'documents' => collect([(object) [
                'name' => (string) ($invoice->pdf_name ?: $this->pdf->filename($invoice)),
            ]]),
            'orderNumber' => $job->displayOrderNumber(),
            'productSummary' => '',
        ];
    }

    private function subject(FlowJob $job, Invoice $invoice): string
    {
        return 'Invoice '.$invoice->invoice_number.' — '.$job->displayOrderNumber();
    }

    private function senderAddress(): string
    {
        if (mb_strtolower((string) config('flowtrack_email.transport', 'laravel')) === 'e2a') {
            return trim((string) config('flowtrack_email.e2a.agent_email'));
        }

        return trim((string) config('mail.from.address'));
    }

    /** @param array<string,mixed> $brand */
    private function senderName(array $brand): string
    {
        if (mb_strtolower((string) config('flowtrack_email.transport', 'laravel')) === 'e2a') {
            return '';
        }

        $configured = trim((string) config('mail.from.name'));
        if ($configured !== '' && ! in_array(mb_strtolower($configured), ['laravel', 'flowtrack'], true)) {
            return $configured;
        }

        return trim((string) ($brand['name'] ?? '')) ?: 'Company';
    }

    private function deliveryLabel(): string
    {
        $transport = mb_strtolower(trim((string) config('flowtrack_email.transport', 'laravel')));
        if ($transport === 'e2a') return 'E2A email service';

        $mailer = trim((string) config('flowtrack_email.mailer', config('mail.default')));
        return $mailer !== '' ? strtoupper($mailer).' mailer' : 'Configured email service';
    }

    /** @return array<string,mixed> */
    private function companyBrand(): array
    {
        $branding = $this->branding->current();
        $profile = $this->companyProfile->current();
        $tradingName = trim((string) ($profile['trading_name'] ?? ''));
        $legalName = trim((string) ($profile['legal_name'] ?? ''));
        $displayName = $tradingName !== ''
            ? $tradingName
            : ($legalName !== '' ? $legalName : trim((string) ($branding['name'] ?? '')));

        return array_merge($branding, [
            'name' => $displayName !== '' ? $displayName : 'Company',
            'legal_name' => $legalName,
            'trading_name' => $tradingName,
            'registration_number' => trim((string) ($profile['registration_number'] ?? '')),
            'tax_number' => trim((string) ($profile['tax_number'] ?? '')),
            'billing_email' => trim((string) ($profile['billing_email'] ?? '')),
            'phone' => trim((string) ($profile['phone'] ?? '')),
            'website' => trim((string) ($profile['website'] ?? '')),
            'address_lines' => $this->companyProfile->addressLines($profile),
        ]);
    }
}
