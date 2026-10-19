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
    public function touch(string $reason = 'data'): void
    {
        if (DB::transactionLevel() > 0) {
            $this->pendingReason = $reason;
            if ($this->afterCommitScheduled) {
                return;
            }

            $this->afterCommitScheduled = true;
            DB::afterCommit(function (): void {
                $reason = $this->pendingReason;
                $this->afterCommitScheduled = false;
                $this->pendingReason = 'data';
                $this->commitTouch($reason);
            });
            return;
        }

        $this->commitTouch($reason);
    }

    private function commitTouch(string $reason): void
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
