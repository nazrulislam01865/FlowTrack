<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\Document;
use App\Models\MasterRecord;
use App\Models\SupplierTaskAssignment;
use App\Models\Task;
use App\Services\SecureDocumentStorage;
use App\Support\OrderTaskRequirement;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Supplier-scoped, signed streaming of task QC evidence only. */
final class DownloadOrderQcDocument extends Controller
{
    public function __invoke(Request $request, int $order, int $document, SecureDocumentStorage $storage)
    {
        $secret = (string) config('supplier_bridge.secret');
        $time = $request->header('X-FlowTrack-Timestamp');
        $supplier = $request->header('X-FlowTrack-Supplier');
        $signature = $request->header('X-FlowTrack-Signature');
        abort_unless(config('supplier_bridge.enabled') && strlen($secret) >= 32
            && is_string($time) && ctype_digit($time) && abs(time() - (int) $time) <= 120
            && is_string($supplier) && ctype_digit($supplier) && (int) $supplier > 0
            && is_string($signature) && preg_match('/^[a-f0-9]{64}$/i', $signature)
            && hash_equals(hash_hmac('sha256', $time."\n".$supplier."\n".$order."\n".$document, $secret), $signature), 404);

        $file = Document::query()->where('flow_job_id', $order)->findOrFail($document);
        abort_unless($file->task_id, 404);
        $task = Task::query()->where('flow_job_id', $order)->with('setupTemplate')->findOrFail((int) $file->task_id);
        abort_unless(in_array(OrderTaskRequirement::automationKey($task), ['QC_CHECK', 'QC_ISSUE'], true), 404);
        abort_unless(SupplierTaskAssignment::query()->where('flow_job_id', $order)
            ->where('task_id', $task->id)->where('supplier_id', (int) $supplier)->exists(), 404);
        // Use the same currently eligible supplier-product rules as approved
        // artwork downloads; a stale assignment must not expose QC files.
        $eligibleLines = DB::table('flow_job_items as i')
            ->join('master_records as p', 'p.id', '=', 'i.catalog_product_id')
            ->join('master_records as s', 's.id', '=', 'i.supplier_id')
            ->leftJoin('product_supplier_links as l', function ($join): void {
                $join->on('l.product_id', '=', 'p.id')->on('l.supplier_id', '=', 's.id')
                    ->on('l.workspace_id', '=', 'p.workspace_id');
            })
            ->where('i.flow_job_id', $order)->where('i.supplier_id', (int) $supplier)
            ->where('i.is_removed', false)->where('i.quantity', '>', 0)
            ->where('p.type', 'product')->whereNull('p.deleted_at')
            ->where('s.type', 'supplier')->where('s.status', 'active')->whereNull('s.deleted_at')
            ->whereColumn('p.workspace_id', 's.workspace_id')
            ->limit(101)->get(['p.metadata', 'l.id as linked_id']);
        abort_unless($eligibleLines->contains(static function ($line) use ($supplier): bool {
            if ($line->linked_id) return true;
            $product = new MasterRecord();
            $product->setRawAttributes(['type' => 'product', 'metadata' => $line->metadata]);
            return $product->hasProductSupplier((int) $supplier);
        }), 404);

        $located = $storage->locate((string) $file->path);
        abort_unless($located, 404);
        $disk = Storage::disk($located['disk']);
        return response()->streamDownload(static function () use ($disk, $located): void {
            $handle = $disk->readStream($located['path']);
            if (! is_resource($handle)) return;
            try { fpassthru($handle); } finally { fclose($handle); }
        }, basename(str_replace('\\', '/', (string) $file->name)) ?: 'qc-document', [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) max(0, (int) $file->size),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
