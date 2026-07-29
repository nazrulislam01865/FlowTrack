<?php

use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('flowtrack:sync-workflow-state {--no-auto : Recalculate health and readiness without automatic phase movement}', function () {
    $automation = app(WorkflowAutomationService::class);
    $allowAuto = ! $this->option('no-auto');
    $processed = 0;
    $skipped = 0;

    FlowJob::query()
        ->whereNotNull('current_phase_id')
        ->where('status', '!=', 'completed')
        ->with(['owner', 'coordinator'])
        ->orderBy('id')
        ->chunkById(100, function ($jobs) use ($automation, $allowAuto, &$processed, &$skipped) {
            foreach ($jobs as $job) {
                $actor = $job->coordinator ?: $job->owner;
                if (! $actor) {
                    $actorId = WorkspaceMembership::query()
                        ->where('workspace_id', $job->workspace_id)
                        ->where('status', 'active')
                        ->orderBy('id')
                        ->value('user_id');
                    $actor = $actorId ? \App\Models\User::query()->find($actorId) : null;
                }

                if (! $actor) {
                    $skipped++;
                    continue;
                }

                $automation->synchronize($job, $actor, 'scheduled_sync', $allowAuto);
                $processed++;
            }
        });

    $this->info("Workflow state synchronized for {$processed} Jobs. Skipped: {$skipped}.");
})->purpose('Generate missing current-Phase tasks and recalculate Job health and phase readiness');

Schedule::command('flowtrack:sync-workflow-state --no-auto')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
