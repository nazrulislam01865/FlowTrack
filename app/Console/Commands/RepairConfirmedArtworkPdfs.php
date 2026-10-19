<?php

namespace App\Console\Commands;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Services\ArtworkTrackingPdfService;
use App\Services\OrderWorkflowActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One-time, opt-in repair for completed artwork with missing/old PDF previews. */
final class RepairConfirmedArtworkPdfs extends Command
{
    protected $signature = 'flowtrack:repair-artwork-pdfs
        {--order= : An Order id, job number, or order number}
        {--all : Process all Orders with completed Artwork review tasks}
        {--force : Rebuild even if the generated PDF already exists (e.g. legacy DOC/PDF placeholder)}
        {--dry-run : Show eligible completed review tasks without writing files or database records}';

    protected $description = 'Generate or rebuild confirmed artwork tracking PDFs only for completed Artwork review tasks';

    public function handle(ArtworkTrackingPdfService $pdfs, OrderWorkflowActionService $actions): int
    {
        if (! (bool) config('flowtrack.order_tracking_enabled', false)) {
            $this->error('FLOWTRACK_ORDER_TRACKING_ENABLED must be true to generate tracking PDFs.');
            return self::FAILURE;
        }

        $order = trim((string) $this->option('order'));
        if (($order === '') === ! (bool) $this->option('all')) {
            $this->error('Choose exactly one of --order=ID and --all.');
            return self::FAILURE;
        }

        $query = Task::query()
            ->where(function ($query): void {
                $query->whereNotNull('completed_at')->orWhere('status', 'Completed');
            })
            ->where(function ($query): void {
                $query->whereHas('setupTemplate', fn ($items) => $items->where('automation_key', 'ART_INTERNAL_REVIEW'))
                    ->orWhereIn('title', ['Internal Artwork Review']);
            })
            ->with('setupTemplate')
            ->orderBy('id');

        if ($order !== '') {
            $job = FlowJob::query()->where(function ($query) use ($order): void {
                $query->where('job_number', $order)->orWhere('order_number', $order);
                if (ctype_digit($order)) {
                    $query->orWhere('id', (int) $order);
                }
            })->first();
            if (! $job) {
                $this->error('Order not found: '.$order);
                return self::FAILURE;
            }
            $query->where('flow_job_id', $job->id);
        }

        $successes = $failures = $eligible = 0;
        $users = [];
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query->chunkById(100, function ($tasks) use ($actions, $pdfs, $dryRun, $force, &$successes, &$failures, &$eligible, &$users): void {
            foreach ($tasks as $task) {
                if ($actions->automationKey($task) !== 'ART_INTERNAL_REVIEW') {
                    continue;
                }
                $eligible++;
                if ($dryRun) {
                    $this->line('Eligible: Order '.$task->flow_job_id.', review task '.$task->id);
                    continue;
                }
                try {
                    $job = FlowJob::query()->findOrFail($task->flow_job_id);
                    $actorId = (int) ($task->assignee_at_completion ?: $task->assignee_id ?: $job->created_by);
                    if (! array_key_exists($actorId, $users)) {
                        $users[$actorId] = $actorId > 0 ? User::query()->find($actorId) : null;
                    }
                    $actor = $users[$actorId] ?: User::query()->where('is_super_admin', true)->first();
                    if (! $actor) {
                        throw new \RuntimeException('Could not locate the confirming user or an administrator for document attribution.');
                    }

                    // Same locks/order as the normal workflow completion. Only
                    // one invocation writes a given Order/version at a time.
                    $document = DB::transaction(function () use ($job, $task, $actor, $force, $pdfs) {
                        $locked = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
                        return $pdfs->generate($locked, $task, $actor, $force);
                    }, 3);
                    $successes++;
                    $this->info('OK Order '.$job->id.' -> '.$document->name);
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error('FAILED Order '.$task->flow_job_id.': '.$exception->getMessage());
                    report($exception);
                }
            }
        });

        $this->info("Eligible: {$eligible}, checked/repaired: {$successes}, failed: {$failures}.");
        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
