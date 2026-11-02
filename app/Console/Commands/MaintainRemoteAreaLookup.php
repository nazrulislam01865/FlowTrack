<?php

namespace App\Console\Commands;

use App\Services\RemoteAreas\RemoteAreaLookupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Recovery sweep for a missed queue event or Redis reset, never an HTTP task. */
final class MaintainRemoteAreaLookup extends Command
{
    protected $signature = 'flowtrack:remote-area-index-maintain';
    protected $description = 'Warm missing/stale Remote Area workspace indexes outside HTTP requests';

    public function handle(RemoteAreaLookupService $lookup): int
    {
        if (! config('remote_area_lookup.enabled') || ! Schema::hasTable('remote_area_lookup_versions')) {
            return self::SUCCESS;
        }
        foreach (DB::table('remote_area_lookup_versions')->select('workspace_id')->cursor() as $row) {
            $id = (int) $row->workspace_id;
            if ($lookup->ready($id)) continue;
            try {
                $result = $lookup->build($id);
                if (! $result['published']) $this->warn('Rules changed during build for workspace '.$id);
                else $this->info('Index refreshed for workspace '.$id);
            } catch (Throwable $error) {
                $this->warn('Index not refreshed for workspace '.$id.': '.$error->getMessage());
                // Continue other workspaces; the legacy matcher remains active.
            }
        }
        return self::SUCCESS;
    }
}
