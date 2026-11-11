<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\SupplierPurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Private, signed, supplier-scoped current PO PDF; never a public storage URL. */
final class DownloadSupplierPurchaseOrder extends Controller
{
    public function __invoke(Request $request, int $order, int $po, int $document)
    {
        $secret = (string) config('supplier_bridge.secret');
        $time = $request->header('X-FlowTrack-Timestamp');
        $supplier = $request->header('X-FlowTrack-Supplier');
        $signature = $request->header('X-FlowTrack-Signature');
        abort_unless(config('supplier_bridge.enabled') && strlen($secret) >= 32
            && is_string($time) && ctype_digit($time) && abs(time() - (int) $time) <= 120
            && is_string($supplier) && ctype_digit($supplier) && (int) $supplier > 0
            && is_string($signature) && preg_match('/^[a-f0-9]{64}$/i', $signature)
            && hash_equals(hash_hmac('sha256', implode("\n", [$time, $supplier, $order, $po, $document]), $secret), $signature), 404);
        $record = SupplierPurchaseOrder::query()
            ->where('flow_job_id', $order)->where('supplier_id', (int) $supplier)
            ->whereNull('archived_at')->where('document_id', $document)
            ->with('document:id,flow_job_id,category,path,mime_type,name,size')
            ->findOrFail($po);
        $owned = collect((array) $record->items_snapshot)->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        abort_unless($owned && DB::table('flow_job_items')->where('flow_job_id', $order)
            ->where('supplier_id', (int) $supplier)->where('is_removed', false)
            ->whereIn('id', $owned)->count() === count(array_unique($owned)), 404);
        $file = $record->document;
        abort_unless($file && (int) $file->flow_job_id === $order
            && $file->category === 'Supplier Purchase Order' && $file->mime_type === 'application/pdf'
            && $file->path === $record->pdf_path && (int) $file->size > 0
            && (int) $file->size <= 10485760 && Storage::disk('local')->exists($record->pdf_path), 404);
        return response()->streamDownload(static function () use ($record): void {
            $handle = Storage::disk('local')->readStream($record->pdf_path);
            if (! is_resource($handle)) return;
            try { fpassthru($handle); } finally { fclose($handle); }
        }, basename((string) $file->name), [
            'Content-Type' => 'application/pdf', 'Content-Length' => (string) $file->size,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
