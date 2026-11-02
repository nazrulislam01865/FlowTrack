<?php

namespace App\Observers;

use App\Models\MasterRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Persist a durable revision after every Eloquent Remote Area mutation.
 * Normal MasterDataService writes are transactional, so the revision and the
 * saved row commit together. No cached generation is trusted after revision
 * changes, even when the Redis invalidation message cannot be delivered.
 */
final class RemoteAreaLookupVersionObserver
{
    public function saved(MasterRecord $record): void
    {
        $this->changed($record);
    }

    public function deleted(MasterRecord $record): void
    {
        $this->changed($record);
    }

    public function restored(MasterRecord $record): void
    {
        // Eloquent restore() also fires saved; a second increment is harmless,
        // but avoid unnecessary work here.
    }

    private function changed(MasterRecord $record): void
    {
        $workspaces = [];
        if ($record->type === 'remote_area') $workspaces[] = (int) $record->workspace_id;
        if ($record->wasChanged(['type', 'workspace_id'])
            && (string) $record->getRawOriginal('type') === 'remote_area') {
            $workspaces[] = (int) $record->getRawOriginal('workspace_id');
        }
        $workspaces = array_unique(array_filter($workspaces, static fn ($id) => $id > 0));
        if ($workspaces === []) return;

        // Safe deployment order: installing code ahead of the new migration
        // does not affect ordinary Remote Area edits while the flag is off.
        if (! Schema::hasTable('remote_area_lookup_versions')) return;
        foreach ($workspaces as $workspaceId) {
            DB::table('remote_area_lookup_versions')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'revision' => 0,
            ]);
            DB::table('remote_area_lookup_versions')->where('workspace_id', $workspaceId)->increment('revision');
            $invalidate = static function () use ($workspaceId): void {
                try {
                    Cache::forget("flowtrack:master:active:{$workspaceId}:remote_area");
                } catch (Throwable) {
                    // Durable DB revision remains authoritative even if cache is down.
                }
                if (config('remote_area_lookup.enabled') && config('queue.default') !== 'sync') {
                    try {
                        \App\Jobs\RebuildRemoteAreaIndex::dispatch($workspaceId);
                    } catch (Throwable) {
                        // Scheduler provides recovery; never fail an edit after commit.
                    }
                }
            };
            if (DB::transactionLevel() > 0) DB::afterCommit($invalidate);
            else $invalidate();
        }
    }
}
