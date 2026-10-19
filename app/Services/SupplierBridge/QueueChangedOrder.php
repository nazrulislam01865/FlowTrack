<?php

namespace App\Services\SupplierBridge;

use App\Jobs\DeliverChangedSupplierOrder;
use App\Models\FlowJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Persist the change first; the worker sends after commit, with scheduled retry on outage. */
final class QueueChangedOrder
{
    public function mark(int $orderId): void
    {
        if ($orderId < 1 || ! config('supplier_bridge.enabled')) return;

        // A task may be created or assigned before the initial order delivery is
        // captured. Capture only missing orders here; supplier-line changes also
        // reconcile newly assigned suppliers in the model observer.
        if (! DB::table('supplier_order_deliveries')->where('flow_job_id', $orderId)->exists()) {
            $order = FlowJob::query()->find($orderId);
            if ($order && strcasecmp((string) $order->status, 'Draft') !== 0) {
                app(CaptureCreatedOrder::class)->captureMany([$order]);
            }
        }

        // Do not release an in-flight HTTP claim. A non-null next_attempt_at
        // marks it dirty so the sender will schedule a fresh snapshot on return.
        DB::table('supplier_order_deliveries')->where('flow_job_id', $orderId)
            ->where('status', 'sending')->update([
                'next_attempt_at' => now(), 'updated_at' => now(),
            ]);
        DB::table('supplier_order_deliveries')->where('flow_job_id', $orderId)
            ->whereIn('status', ['delivered', 'pending'])->update([
                'status' => 'pending', 'attempts' => 0,
                'next_attempt_at' => null, 'locked_at' => null,
                'updated_at' => now(),
            ]);

        if (\App\Support\Performance\OrderBaselineRecorder::enabled()) {
            $baselineMark = static fn () => \App\Support\Performance\OrderBaselineRecorder::record('supplier_pending', [
                'order_id' => $orderId,
            ]);
            if (DB::transactionLevel() > 0) DB::afterCommit($baselineMark);
            else $baselineMark();
        }

        // Never call the remote service during a user save. The persisted outbox
        // still works when a local server or queue worker is temporarily offline.
        if (config('queue.default') === 'sync') return;
        $dispatch = static function () use ($orderId): void {
            try {
                DeliverChangedSupplierOrder::dispatch($orderId)->onQueue('supplier-critical');
            } catch (\Throwable $error) {
                Log::warning('Immediate supplier delivery deferred to scheduler.', [
                    'order_id' => $orderId, 'error' => $error->getMessage(),
                ]);
            }
        };
        if (DB::transactionLevel() > 0) DB::afterCommit($dispatch);
        else $dispatch();
    }
}
