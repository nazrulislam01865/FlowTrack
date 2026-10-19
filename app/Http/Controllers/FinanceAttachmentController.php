<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AccessControlService;
use App\Services\JobService;
use App\Support\StoredFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceAttachmentController extends Controller
{
    public function invoiceOpen(Invoice $invoice): StreamedResponse
    {
        $this->authorizeFinanceRecord((int) $invoice->flow_job_id);
        return StoredFileResponse::inline(
            (string) $invoice->supporting_document_path,
            (string) ($invoice->supporting_document_name ?: basename((string) $invoice->supporting_document_path)),
        );
    }

    public function invoiceDownload(Invoice $invoice): StreamedResponse
    {
        $this->authorizeFinanceRecord((int) $invoice->flow_job_id);
        return StoredFileResponse::download(
            (string) $invoice->supporting_document_path,
            (string) ($invoice->supporting_document_name ?: basename((string) $invoice->supporting_document_path)),
        );
    }

    public function paymentOpen(Payment $payment): StreamedResponse
    {
        $this->authorizeFinanceRecord((int) $payment->flow_job_id);
        return StoredFileResponse::inline(
            (string) $payment->receipt_path,
            (string) ($payment->receipt_name ?: basename((string) $payment->receipt_path)),
        );
    }

    public function paymentDownload(Payment $payment): StreamedResponse
    {
        $this->authorizeFinanceRecord((int) $payment->flow_job_id);
        return StoredFileResponse::download(
            (string) $payment->receipt_path,
            (string) ($payment->receipt_name ?: basename((string) $payment->receipt_path)),
        );
    }

    private function authorizeFinanceRecord(int $jobId): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        abort_unless(app(AccessControlService::class)->can($user, 'finance', 'view'), 403);
        app(JobService::class)->findVisibleBase($user, $jobId);
    }
}
