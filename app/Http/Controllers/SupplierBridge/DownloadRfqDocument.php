<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\InquiryDocument;
use App\Models\InquiryRfqInvitation;
use App\Models\InquiryTask;
use App\Services\SecureDocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/** Private server-to-server download; never publicly issue bearer document URLs. */
final class DownloadRfqDocument extends Controller
{
    public function __invoke(Request $request, int $invitation, int $document, SecureDocumentStorage $storage)
    {
        $secret = (string) config('supplier_bridge.secret');
        $timestamp = $request->header('X-FlowTrack-Timestamp');
        $supplier = $request->header('X-FlowTrack-Supplier');
        $signature = $request->header('X-FlowTrack-Signature');
        $version = $request->header('X-FlowTrack-Document-Version', '');
        $signedMessage = $timestamp."\n".$supplier."\n".$invitation."\n".$document;
        if (is_string($version) && $version !== '') $signedMessage .= "\n".$version;
        if (! config('supplier_bridge.enabled') || strlen($secret) < 32 || ! is_string($timestamp) || ! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 120 || ! is_string($supplier) || ! ctype_digit($supplier)
            || ! is_string($signature) || ! preg_match('/^[0-9a-f]{64}$/i', $signature)
            || ! is_string($version) || ($version !== '' && ! preg_match('/^[0-9]{10,20}$/', $version))
            || ! hash_equals(hash_hmac('sha256', $signedMessage, $secret), $signature)) {
            abort(404);
        }
        $rfq = InquiryRfqInvitation::query()->whereKey($invitation)
            ->where('supplier_id', (int) $supplier)
            ->whereNotNull('invited_at')->where('email_status', '!=', 'Draft')->firstOrFail();
        $fileQuery = InquiryDocument::query()->whereKey($document)->where('inquiry_id', $rfq->inquiry_id);
        if ($request->route('task_document')) {
            $firstTaskId = InquiryTask::query()->where('inquiry_id', $rfq->inquiry_id)
                ->orderBy('sequence')->orderBy('id')->value('id');
            abort_unless($firstTaskId, 404);
            $fileQuery->where('inquiry_task_id', $firstTaskId)
                ->where('note', 'like', 'Supplier RFQ '.$rfq->id.' |%');
        } else {
            $fileQuery->whereNull('inquiry_task_id');
        }
        $file = $fileQuery->firstOrFail();
        if ($version !== '' && $version !== (string) $file->updated_at?->format('Uu')) abort(404);
        abort_if($file->size > 20971520, 413);
        $located = $storage->locate((string) $file->path);
        abort_unless($located, 404);
        $disk = Storage::disk($located['disk']);
        $name = basename($file->name) ?: 'rfq-document';
        return response()->streamDownload(static function () use ($disk, $located): void {
            $handle = $disk->readStream($located['path']);
            if (! is_resource($handle)) return;
            try { fpassthru($handle); } finally { fclose($handle); }
        }, $name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
