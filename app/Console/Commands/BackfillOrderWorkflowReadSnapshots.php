<?php

namespace App\Console\Commands;

use App\Models\FlowJob;
use App\Models\OrderWorkflowReadSnapshot;
use App\Services\OrderWorkflowBindingService;
use App\Services\OrderWorkflowSetupService;
use App\Services\Orders\OrderWorkflowSnapshotService;
use Illuminate\Console\Command;
use Throwable;

/** Rate-limited, idempotent and resumable by primary key. */
final class BackfillOrderWorkflowReadSnapshots extends Command
{
    protected $signature = 'flowtrack:backfill-order-workflow-snapshots
        {--after-id=0 : Resume after this Order ID}
        {--limit=200 : Maximum Orders to process in this invocation}
        {--chunk=25 : Orders to fetch per chunk}
        {--sleep-ms=50 : Pause between processed Orders}
        {--dry-run : Report candidates without writing snapshots}';

    protected $description = 'Build versioned read-only Order workflow snapshots in small resumable batches';

    public function handle(OrderWorkflowSnapshotService $snapshots): int
    {
        if (! $snapshots->writerEnabled()) {
            $this->error('Enable FLOWTRACK_WORKFLOW_READ_SNAPSHOT_WRITES_ENABLED before backfill.');
            return self::FAILURE;
        }
        $afterId = max(0, (int) $this->option('after-id'));
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $chunk = max(1, min(100, (int) $this->option('chunk')));
        $sleepMs = max(0, min(2000, (int) $this->option('sleep-ms')));
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $built = 0;
        $skipped = 0;
        $failed = 0;
        $lastId = $afterId;
        $setup = app(OrderWorkflowSetupService::class);
        $bindings = app(OrderWorkflowBindingService::class);

        FlowJob::query()
            ->where('id', '>', $afterId)
            ->whereNull('deleted_at')
            ->whereNull('completed_at')
            ->whereNotIn('status', \App\Services\JobService::INACTIVE_STATUSES)
            ->orderBy('id')
            ->select(['id', 'workflow_id', 'workflow_phase_id', 'workflow_display_version'])
            ->chunkById($chunk, function ($orders) use (
                &$processed, &$built, &$skipped, &$failed, &$lastId,
                $limit, $dryRun, $sleepMs, $setup, $bindings, $snapshots
            ): bool {
                foreach ($orders as $order) {
                    if ($processed >= $limit) return false;
                    $processed++;
                    $lastId = (int) $order->id;
                    try {
                        if (! $setup->isActiveOrderWorkflow((int) $order->workflow_id)) {
                            $skipped++;
                            continue;
                        }
                        $phases = $bindings->publishedPhasesForRead((int) $order->workflow_id);
                        if ($phases->isEmpty() || ! $phases->contains('id', (int) $order->workflow_phase_id)) {
                            $skipped++;
                            continue;
                        }
                        $existing = OrderWorkflowReadSnapshot::query()->where('flow_job_id', $order->id)
                            ->first(['source_version', 'definition_revision', 'schema_version']);
                        if ($existing && (int) $existing->source_version === (int) $order->workflow_display_version
                            && (int) $existing->schema_version === OrderWorkflowSnapshotService::SCHEMA_VERSION
                            && hash_equals($snapshots->definitionRevision($phases), (string) $existing->definition_revision)) {
                            $skipped++;
                            continue;
                        }
                        if (! $dryRun && ! $snapshots->publishIfCurrent((int) $order->id)) {
                            // A concurrent edit made this build obsolete; the
                            // next invocation rebuilds the latest version.
                            $failed++;
                        } else {
                            $built++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->warn('Order '.$order->id.': '.$exception->getMessage());
                    }
                    if ($sleepMs > 0 && ! $dryRun) usleep($sleepMs * 1000);
                }
                return $processed < $limit;
            });
        $this->info(sprintf('%s: examined=%d candidates=%d skipped=%d failed=%d resume_after=%d',
            $dryRun ? 'Dry run' : 'Backfill', $processed, $built, $skipped, $failed, $lastId));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
