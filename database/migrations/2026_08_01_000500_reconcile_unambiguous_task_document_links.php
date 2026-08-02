<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_documents')
            || ! Schema::hasTable('flow_tasks')
            || ! Schema::hasTable('task_pack_items')
            || ! Schema::hasColumn('flow_documents', 'flow_task_id')
            || ! Schema::hasColumn('flow_documents', 'task_pack_item_id')) {
            return;
        }

        // Repair older Task-Pack documents that already have an exact Pack item
        // but predate the flow_task_id column.
        DB::table('flow_documents')
            ->whereNull('deleted_at')
            ->whereNull('flow_task_id')
            ->whereNotNull('task_pack_item_id')
            ->select(['id', 'flow_job_id', 'workflow_phase_id', 'task_pack_item_id'])
            ->orderBy('id')
            ->chunkById(250, function ($documents): void {
                foreach ($documents as $document) {
                    $taskIds = DB::table('flow_tasks')
                        ->where('flow_job_id', $document->flow_job_id)
                        ->where('workflow_phase_id', $document->workflow_phase_id)
                        ->where('task_pack_item_id', $document->task_pack_item_id)
                        ->whereNull('deleted_at')
                        ->whereNull('removed_from_job_at')
                        ->pluck('id')
                        ->unique()
                        ->values();

                    if ($taskIds->count() === 1) {
                        DB::table('flow_documents')->where('id', $document->id)->update([
                            'flow_task_id' => (int) $taskIds->first(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        // Older uploads from the Documents page sometimes only carried
        // Job + Phase + category. Adopt them only when that category maps to
        // exactly one Task-Pack item in the phase and is not also a separate
        // Workflow-Phase-level requirement.
        DB::table('flow_documents')
            ->whereNull('deleted_at')
            ->whereNull('flow_task_id')
            ->whereNull('task_pack_item_id')
            ->whereNotNull('workflow_phase_id')
            ->whereNotNull('document_category_id')
            ->select(['id', 'flow_job_id', 'workflow_phase_id', 'document_category_id'])
            ->orderBy('id')
            ->chunkById(250, function ($documents): void {
                foreach ($documents as $document) {
                    $phaseOwnsCategory = DB::table('workflow_phases')
                        ->where('id', $document->workflow_phase_id)
                        ->where('document_category_id', $document->document_category_id)
                        ->exists();
                    if ($phaseOwnsCategory) {
                        continue;
                    }

                    $candidates = DB::table('flow_tasks as tasks')
                        ->join('task_pack_items as items', 'items.id', '=', 'tasks.task_pack_item_id')
                        ->where('tasks.flow_job_id', $document->flow_job_id)
                        ->where('tasks.workflow_phase_id', $document->workflow_phase_id)
                        ->whereNull('tasks.deleted_at')
                        ->whereNull('tasks.removed_from_job_at')
                        ->where(function ($query) use ($document): void {
                            $query->where('tasks.document_category_id', $document->document_category_id)
                                ->orWhere('items.document_category_id', $document->document_category_id);
                        })
                        ->select(['tasks.id', 'tasks.task_pack_item_id'])
                        ->distinct()
                        ->get();

                    if ($candidates->count() !== 1) {
                        continue;
                    }

                    $candidate = $candidates->first();
                    DB::table('flow_documents')->where('id', $document->id)->update([
                        'flow_task_id' => (int) $candidate->id,
                        'task_pack_item_id' => (int) $candidate->task_pack_item_id,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Data reconciliation only; repaired links are intentionally retained.
    }
};
