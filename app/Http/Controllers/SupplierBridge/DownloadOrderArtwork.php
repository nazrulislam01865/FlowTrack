<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Support\OrderStageResolver;
use App\Services\SupplierBridge\ApprovedOrderArtwork;
use App\Services\SecureDocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Download only final, approved artwork for a currently assigned supplier. */
final class DownloadOrderArtwork extends Controller
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
            && is_string($signature) && preg_match('/^[0-9a-f]{64}$/i', $signature)
            && hash_equals(hash_hmac('sha256', $time."\n".$supplier."\n".$order."\n".$document, $secret), $signature), 404);

        // Verify an active supplier-product assignment even after order/reassignment updates.
        $job = FlowJob::query()->with('phase:id,name,short_name,sequence')->findOrFail($order);
        $stage = OrderStageResolver::resolve(
            $job->phase?->name, $job->phase?->short_name, $job->phase?->sequence, $job->status,
        );
        $artwork = app(ApprovedOrderArtwork::class);
        $tasks = DB::table('tasks as t')
            ->join('task_pack_items as pack', 'pack.id', '=', 't.task_pack_task_id')
            ->where('t.flow_job_id', $order)->whereNull('t.deleted_at')
            ->whereIn('pack.automation_key', [
                'ART_PREPARE_UPLOAD', 'ART_INTERNAL_REVIEW', 'ART_SEND_ORDER_TEAM',
                'ART_CLIENT_ERP_DECISION', 'ART_SAMPLE_APPROVAL',
            ])->orderByDesc('t.id')->get([
                't.id', 'pack.automation_key', 't.status', 't.completed_at',
            ]);
        abort_unless($artwork->confirmed((int) $stage['sequence'], $tasks,
            $job->completed_at !== null), 404);
        $eligible = DB::table('flow_job_items as i')
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
        $assigned = $eligible->contains(function ($line) use ($supplier): bool {
            if ($line->linked_id) return true;
            $product = new MasterRecord();
            $product->setRawAttributes(['type' => 'product', 'metadata' => $line->metadata]);
            return $product->hasProductSupplier((int) $supplier);
        });
        abort_unless($assigned, 404);

        $file = $artwork->documents($order, $tasks)
            ->first(static fn ($candidate) => (int) $candidate->id === $document);
        abort_unless($file, 404);
        $located = $storage->locate((string) $file->path);
        abort_unless($located, 404);
        $disk = Storage::disk($located['disk']);
        return response()->streamDownload(static function () use ($disk, $located): void {
            $handle = $disk->readStream($located['path']);
            if (! is_resource($handle)) return;
            try { fpassthru($handle); } finally { fclose($handle); }
        }, basename((string) $file->name) ?: 'approved-artwork', [
            'Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $file->size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
