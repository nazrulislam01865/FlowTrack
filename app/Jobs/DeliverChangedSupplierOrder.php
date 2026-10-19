<?php

namespace App\Jobs;

use App\Services\SupplierBridge\DeliverOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Fast delivery for one changed order; existing minute scheduler retries failures. */
final class DeliverChangedSupplierOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public ?float $enqueuedAt = null;

    public function __construct(public readonly int $orderId)
    {
        $this->enqueuedAt = microtime(true);
    }

    public function handle(DeliverOrder $sender): void
    {
        if ($this->enqueuedAt !== null && \App\Support\Performance\OrderBaselineRecorder::enabled()) {
            \App\Support\Performance\OrderBaselineRecorder::record('supplier_queue_started', [
                'order_id' => $this->orderId,
                'queue_delay_ms' => round(max(0, microtime(true) - $this->enqueuedAt) * 1000, 2),
            ]);
        }
        $ids = DB::table('supplier_order_deliveries')
            ->where('flow_job_id', $this->orderId)->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })->pluck('id');
        foreach ($ids as $id) {
            $sender->send((int) $id);
        }
    }
}
