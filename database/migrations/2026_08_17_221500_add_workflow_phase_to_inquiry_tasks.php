<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inquiry_tasks')) {
            return;
        }

        if (! Schema::hasColumn('inquiry_tasks', 'source_workflow_phase_id')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                // Keep this as a durable source id rather than a foreign key. Workflow
                // setup may later be edited/deleted, but an existing Inquiry must keep
                // the phase it originated from just like an Order keeps its source phase.
                $table->unsignedBigInteger('source_workflow_phase_id')
                    ->nullable()
                    ->after('source_task_pack_item_id');
                $table->index(
                    ['inquiry_id', 'source_workflow_phase_id', 'completed_at'],
                    'inq_tasks_source_phase_open_idx'
                );
            });
        }

        if (! Schema::hasTable('inquiries')
            || ! Schema::hasTable('workflow_phases')
            || ! Schema::hasTable('task_pack_items')) {
            return;
        }

        // Best-effort backfill for existing workflow-created Inquiry tasks. Prefer
        // the phase whose Task Pack owns the original Task Pack item. Sequence is
        // used to disambiguate a Task Pack reused in more than one phase.
        DB::table('inquiry_tasks')
            ->join('inquiries', 'inquiries.id', '=', 'inquiry_tasks.inquiry_id')
            ->whereNull('inquiry_tasks.source_workflow_phase_id')
            ->whereNotNull('inquiries.source_workflow_template_id')
            ->select([
                'inquiry_tasks.id',
                'inquiry_tasks.inquiry_id',
                'inquiry_tasks.source_task_pack_item_id',
                'inquiry_tasks.sequence',
                'inquiries.source_workflow_template_id',
            ])
            ->orderBy('inquiry_tasks.id')
            ->chunkById(250, function ($tasks): void {
                $workflowIds = collect($tasks)
                    ->pluck('source_workflow_template_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();
                $sourceItemIds = collect($tasks)
                    ->pluck('source_task_pack_item_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                $packByItem = $sourceItemIds->isEmpty()
                    ? collect()
                    : DB::table('task_pack_items')
                        ->whereIn('id', $sourceItemIds->all())
                        ->pluck('task_pack_id', 'id');

                $phases = $workflowIds->isEmpty()
                    ? collect()
                    : DB::table('workflow_phases')
                        ->whereIn('workflow_template_id', $workflowIds->all())
                        ->where('is_active', true)
                        ->orderBy('workflow_template_id')
                        ->orderBy('sequence')
                        ->orderBy('id')
                        ->get(['id', 'workflow_template_id', 'task_pack_id', 'sequence']);

                $phaseIdsByWorkflowPack = [];
                foreach ($phases as $phase) {
                    $workflowId = (int) $phase->workflow_template_id;
                    $packId = (int) ($phase->task_pack_id ?? 0);
                    if ($workflowId <= 0 || $packId <= 0) {
                        continue;
                    }
                    $phaseIdsByWorkflowPack[$workflowId.':'.$packId][] = (int) $phase->id;
                }

                foreach ($tasks as $task) {
                    $workflowId = (int) ($task->source_workflow_template_id ?? 0);
                    $packId = (int) ($packByItem->get((int) ($task->source_task_pack_item_id ?? 0)) ?? 0);
                    $matches = $phaseIdsByWorkflowPack[$workflowId.':'.$packId] ?? [];
                    // Backfill only when the source Task Pack identifies one
                    // phase unambiguously. If a Task Pack is reused in multiple
                    // phases, leave the source phase null and let DashboardService's
                    // sequence fallback resolve it safely.
                    if (count($matches) !== 1) {
                        continue;
                    }

                    DB::table('inquiry_tasks')
                        ->where('id', $task->id)
                        ->update(['source_workflow_phase_id' => $matches[0]]);
                }
            }, 'inquiry_tasks.id', 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('inquiry_tasks')
            || ! Schema::hasColumn('inquiry_tasks', 'source_workflow_phase_id')) {
            return;
        }

        Schema::table('inquiry_tasks', function (Blueprint $table): void {
            $table->dropIndex('inq_tasks_source_phase_open_idx');
            $table->dropColumn('source_workflow_phase_id');
        });
    }
};
