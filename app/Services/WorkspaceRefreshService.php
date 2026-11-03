<?php

namespace App\Services;

use App\Jobs\DeliverRealtimeWorkspaceEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkspaceRefreshService
{
    private const VERSION_KEY_PREFIX = 'flowtrack:workspace-data-version:';
    private const DISPATCH_GATE_PREFIX = 'flowtrack:workspace-refresh-gate:';

    private bool $afterCommitScheduled = false;
    private string $pendingReason = 'data';
    private ?int $pendingOrderId = null;

    public function workspaceId(): int
    {
        return max(1, (int) config('flowtrack.workspace_id', 1));
    }

    public function version(): string
    {
        return (string) Cache::get($this->versionKey(), '1');
    }

    /**
     * Invalidate shared dashboard/shell cache keys and tell connected browsers
     * that record-backed UI should be re-rendered. Changes inside one database
     * transaction are collapsed into one post-commit invalidation.
     */
    public function touch(string $reason = 'data', ?int $orderId = null): void
    {
        $orderId = $orderId !== null && $orderId > 0 ? $orderId : null;

        if (DB::transactionLevel() > 0) {
            $this->pendingReason = $reason;
            if ($this->afterCommitScheduled) {
                // When several orders (or unknown subjects) change inside one
                // transaction, never publish a misleading single-order scope.
                if ($this->pendingOrderId !== $orderId) {
                    $this->pendingOrderId = null;
                }
                return;
            }

            $this->pendingOrderId = $orderId;
            $this->afterCommitScheduled = true;
            DB::afterCommit(function (): void {
                $reason = $this->pendingReason;
                $orderId = $this->pendingOrderId;
                $this->afterCommitScheduled = false;
                $this->pendingReason = 'data';
                $this->pendingOrderId = null;
                $this->commitTouch($reason, $orderId);
            });
            return;
        }

        $this->commitTouch($reason, $orderId);
    }

    private function commitTouch(string $reason, ?int $orderId): void
    {
        $version = $this->newVersion();
        Cache::forever($this->versionKey(), $version);

        $reverb = app(ReverbChannelService::class);
        if (! $reverb->enabled()) {
            return;
        }

        // Bulk imports/workflow transitions can still cause several independent
        // commits. Coalesce their realtime traffic into a single delayed signal.
        // Receivers always re-query the latest authorized database state.
        if (! Cache::add($this->dispatchGateKey(), true, now()->addSecond())) {
            return;
        }

        try {
            DeliverRealtimeWorkspaceEvent::dispatch(
                $this->workspaceId(),
                'flowtrack.refresh',
                [
                    'version' => $version,
                    'reason' => $reason,
                    'workspace_id' => $this->workspaceId(),
                    'order_id' => $orderId,
                    'event_id' => $version,
                ],
            )
                ->onConnection((string) config('services.realtime.queue_connection', 'database'))
                ->delay(now()->addSecond());
        } catch (\Throwable $exception) {
            Log::warning('Workspace refresh event could not be queued.', [
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function versionKey(): string
    {
        return self::VERSION_KEY_PREFIX.$this->workspaceId();
    }

    private function dispatchGateKey(): string
    {
        return self::DISPATCH_GATE_PREFIX.$this->workspaceId();
    }

    private function newVersion(): string
    {
        try {
            return now()->format('YmdHisv').'-'.bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return now()->format('YmdHisv').'-'.uniqid('', true);
        }
    }
}
