<?php

namespace App\Actions\Orders;

use App\DTOs\Email\EmailMessage;
use App\Models\Invoice;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\Email\EmailService;
use App\Services\Email\ModuleEmailControlService;

/** Send the existing Order invoice email and mark the invoice as emailed. */
final class EmailOrderInvoice
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly AccessControlService $access,
        private readonly EmailService $email,
        private readonly ModuleEmailControlService $emailControl,
    ) {
    }

    public function handle(User $actor, Invoice $invoice): void
    {
        $job = $this->orders->base($actor, (int) $invoice->flow_job_id);
        abort_unless($this->access->can($actor, 'finance', 'create'), 403);

        if (! $this->emailControl->orderEnabled() || ! $invoice->billing_contact_email) return;

        $job->loadMissing('client');
        $subject = 'Invoice '.$invoice->invoice_number.' · '.($job->displayOrderNumber() ?? 'Order');
        $html = '<p>Hello '.e($invoice->billing_contact_name ?: 'there').',</p>'
            .'<p>Invoice <strong>'.e($invoice->invoice_number).'</strong> has been created for '.e($job->title ?: 'your order').'.</p>'
            .'<p><strong>Amount:</strong> '.e($invoice->currency).' '.number_format((float) $invoice->total, 2).'<br>'
            .'<strong>Due date:</strong> '.e($invoice->due_date?->format('M j, Y') ?: '—').'</p>'
            .'<p>'.nl2br(e((string) ($invoice->notes ?: 'Please include the invoice number with your payment.'))).'</p>';

        // Synchronous on purpose: emailed_at must only be written after the
        // provider has accepted this invoice email.
        $this->email->sendNow(EmailMessage::html(
            $invoice->billing_contact_email,
            $subject,
            $html,
            [
                'type' => 'order_invoice',
                'order_id' => (int) $job->id,
                'invoice_id' => (int) $invoice->id,
            ],
        ));
        $invoice->update(['emailed_at' => now()]);
    }
}
