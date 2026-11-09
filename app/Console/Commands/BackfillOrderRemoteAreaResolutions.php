<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderRemoteAreaResolutionService;
use App\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Lowest-priority, restartable keyset backfill. Never instantiates workflows or emits events. */
final class BackfillOrderRemoteAreaResolutions extends Command
{
    protected $signature = 'flowtrack:remote-area-backfill
        {--workspace= : Active workspace ID (defaults to configured workspace)}
        {--after-id=0 : Restart strictly after this Order ID}
        {--batch=100 : Maximum keyset page size, up to 200}
        {--limit=0 : Maximum Orders scanned; 0 scans all}
        {--sleep=50 : Milliseconds between pages}
        {--retry-unresolved : Include safely recorded unresolved Orders}
        {--dry-run : Resolve but do not write any Order}';

    protected $description = 'Backfill primary Order Remote Area snapshots without workflow, notifications or invoices';

    public function handle(OrderRemoteAreaResolutionService $resolver): int
    {
        $context = app(WorkspaceContext::class);
        if (filled($this->option('workspace'))) $context->set((int) $this->option('workspace'));
        $workspace = $context->id();
        abort_unless($workspace === $resolver->workspaceId(), 422, 'Workspace context mismatch.');
        // FlowJob has no per-row workspace_id in this single-workspace schema.
        // Never let an explicit workspace override attribute all Orders to an
        // unrelated workspace just because that workspace exists.
        $configuredWorkspace = (int) config('flowtrack.workspace_id', 1);
        if ($configuredWorkspace > 0 && $configuredWorkspace !== $workspace) {
            $this->error('Backfill workspace must match the configured Order workspace.');
            return self::FAILURE;
        }
        $batch = max(1, min(200, (int) $this->option('batch')));
        $limit = max(0, (int) $this->option('limit'));
        $sleep = max(0, min(5000, (int) $this->option('sleep')));
        $cursor = max(0, (int) $this->option('after-id'));
        $scanned = $saved = $unresolved = $skipped = 0;

        do {
            $take = $limit ? min($batch, $limit - $scanned) : $batch;
            if ($take < 1) break;
            $rows = DB::table('flow_jobs')
                ->select(['id', 'shipping_postal_code', 'remote_area_resolution_status',
                    'remote_area_input_fingerprint', 'remote_area_workspace_id'])
                ->where('id', '>', $cursor)
                ->where(function ($query): void {
                    $query->whereNull('remote_area_resolution_status');
                    if ($this->option('retry-unresolved')) $query->orWhere('remote_area_resolution_status', 'unresolved');
                })
                ->orderBy('id')->limit($take)->get();

            foreach ($rows as $row) {
                $cursor = (int) $row->id;
                $scanned++;
                try {
                    $resolution = $resolver->resolveForInput($row->shipping_postal_code);
                    if (! $resolution->complete()) {
                        $unresolved++;
                        continue;
                    }
                    if ($this->option('dry-run')) {
                        $saved++;
                        continue;
                    }
                    $query = DB::table('flow_jobs')->where('id', (int) $row->id);
                    if ($row->shipping_postal_code === null) $query->whereNull('shipping_postal_code');
                    else $query->where('shipping_postal_code', $row->shipping_postal_code);
                    if ($row->remote_area_resolution_status === null) $query->whereNull('remote_area_resolution_status');
                    else $query->where('remote_area_resolution_status', $row->remote_area_resolution_status);
                    if ($row->remote_area_input_fingerprint === null) $query->whereNull('remote_area_input_fingerprint');
                    else $query->where('remote_area_input_fingerprint', $row->remote_area_input_fingerprint);
                    if ($row->remote_area_workspace_id === null) $query->whereNull('remote_area_workspace_id');
                    else $query->where('remote_area_workspace_id', (int) $row->remote_area_workspace_id);
                    // No updated_at change: backfill must not look like a customer edit.
                    $updated = $query->update($resolution->columns());
                    if ($updated === 1) $saved++;
                    else $skipped++;
                } catch (Throwable $error) {
                    $unresolved++;
                    $this->warn('Order '.$cursor.': '.get_class($error));
                }
            }
            if ($rows->count() === $take && $sleep > 0) usleep($sleep * 1000);
        } while ($rows->count() === $take && ($limit === 0 || $scanned < $limit));

        $this->info("Workspace {$workspace}: scanned={$scanned}, resolved={$saved}, unresolved={$unresolved}, concurrently_changed={$skipped}, last_id={$cursor}".($this->option('dry-run') ? ' (dry-run)' : ''));
        return $unresolved ? self::FAILURE : self::SUCCESS;
    }
}
