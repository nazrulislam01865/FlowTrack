<?php

namespace App\Jobs;

use App\Services\Orders\OrderWorkflowSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Per-Order coalesced, bounded background builder. No synchronous web rebuild. */
final class RebuildOrderWorkflowReadSnapshot implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public int $timeout = 60;
    public int $uniqueFor = 120;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'order-workflow-read-snapshot:'.$this->orderId;
    }

    public function backoff(): array
    {
        return [2, 5, 15];
    }

    public function handle(OrderWorkflowSnapshotService $snapshots): void
    {
        if (! $snapshots->writerEnabled()) return;
        // Each execution reads the LATEST version. An older build is rejected
        // under the Order lock, then retried without publishing stale data.
        if (! $snapshots->publishIfCurrent($this->orderId)) {
            $this->release(2);
        }
    }
}
