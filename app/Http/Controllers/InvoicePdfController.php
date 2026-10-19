<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AccessControlService;
use App\Services\InvoicePdfService;
use App\Services\JobService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePdfController extends Controller
{
    public function open(Invoice $invoice): StreamedResponse
    {
        $this->authorizeInvoice($invoice);
        return $this->respond(app(InvoicePdfService::class)->ensure($invoice), 'inline');
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        $this->authorizeInvoice($invoice);
        return $this->respond(app(InvoicePdfService::class)->ensure($invoice), 'attachment');
    }

    private function authorizeInvoice(Invoice $invoice): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, 'finance', 'view'), 403);
        app(JobService::class)->findVisibleBase($user, (int) $invoice->flow_job_id);
    }

    private function respond(Invoice $invoice, string $disposition): StreamedResponse
    {
        $path = (string) $invoice->pdf_path;
        $name = (string) ($invoice->pdf_name ?: app(InvoicePdfService::class)->filename($invoice));
        $disk = Storage::disk('local');
        abort_unless($path !== '' && $disk->exists($path), 404, 'Invoice PDF could not be found.');

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        try {
            $headers['Content-Length'] = (string) $disk->size($path);
        } catch (\Throwable) {
        }

        return response()->stream(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);
            abort_if($stream === false, 404);
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
        }, 200, $headers);
    }
}
