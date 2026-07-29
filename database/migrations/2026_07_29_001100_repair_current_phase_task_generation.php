<?php

use App\Modules\OrderFlow\Business\Services\TaskPackActivator;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_jobs') || ! Schema::hasTable('flow_tasks')) {
            return;
        }

        // Normalize legacy Completed statuses that were saved before completed_at
        // became the canonical Phase-gate signal.
        if (Schema::hasTable('master_records')) {
            $completedStatusIds = DB::table('master_records')
                ->where('type', 'task_status')
                ->where('code', 'CMP')
                ->pluck('id');

            if ($completedStatusIds->isNotEmpty()) {
                DB::table('flow_tasks')
                    ->whereIn('task_status_id', $completedStatusIds)
                    ->whereNull('completed_at')
                    ->whereNull('deleted_at')
                    ->update(['completed_at' => DB::raw('updated_at')]);
            }
        }

        $activator = app(TaskPackActivator::class);

        FlowJob::query()
            ->whereNotNull('current_phase_id')
            ->where('status', '!=', 'completed')
            ->with('currentPhase.taskPack.items')
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use ($activator): void {
                foreach ($jobs as $job) {
                    if ($job->currentPhase) {
                        $activator->activate($job, $job->currentPhase);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data repair only. Generated operational history is intentionally kept.
    }
};
