<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_documents')) {
            return;
        }

        if (! Schema::hasColumn('flow_documents', 'flow_task_id')) {
            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->foreignId('flow_task_id')
                    ->nullable()
                    ->constrained('flow_tasks')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasIndex('flow_documents', 'flow_docs_job_task_idx')) {
            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->index(['flow_job_id', 'flow_task_id'], 'flow_docs_job_task_idx');
            });
        }

        if (! Schema::hasColumns('flow_documents', [
            'flow_task_id',
            'flow_job_id',
            'workflow_phase_id',
            'task_pack_item_id',
            'document_category_id',
            'deleted_at',
        ]) || ! Schema::hasTable('flow_tasks')) {
            return;
        }

        // Backfill only unambiguous existing links. Task-Pack documents match
        // the exact generated Task; generic task attachments are linked only
        // when one Task in the same Job/Phase/category is the sole candidate.
        DB::table('flow_documents as documents')
            ->join('flow_tasks as tasks', function ($join): void {
                $join->on('tasks.flow_job_id', '=', 'documents.flow_job_id')
                    ->on('tasks.workflow_phase_id', '=', 'documents.workflow_phase_id')
                    ->on('tasks.task_pack_item_id', '=', 'documents.task_pack_item_id');
            })
            ->whereNull('documents.flow_task_id')
            ->whereNull('documents.deleted_at')
            ->whereNull('tasks.deleted_at')
            ->whereNotNull('documents.task_pack_item_id')
            ->select('documents.id', 'tasks.id as task_id')
            ->orderBy('documents.id')
            ->get()
            ->groupBy('id')
            ->filter(fn ($matches) => $matches->count() === 1)
            ->each(function ($matches): void {
                $match = $matches->first();
                DB::table('flow_documents')->where('id', $match->id)->update([
                    'flow_task_id' => $match->task_id,
                    'updated_at' => now(),
                ]);
            });

        DB::table('flow_documents as documents')
            ->join('flow_tasks as tasks', function ($join): void {
                $join->on('tasks.flow_job_id', '=', 'documents.flow_job_id')
                    ->on('tasks.workflow_phase_id', '=', 'documents.workflow_phase_id')
                    ->on('tasks.document_category_id', '=', 'documents.document_category_id');
            })
            ->whereNull('documents.flow_task_id')
            ->whereNull('documents.task_pack_item_id')
            ->whereNull('documents.deleted_at')
            ->whereNull('tasks.deleted_at')
            ->where('tasks.document_requirement_source', 'task_attachment')
            ->select('documents.id', 'tasks.id as task_id')
            ->orderBy('documents.id')
            ->get()
            ->groupBy('id')
            ->filter(fn ($matches) => $matches->count() === 1)
            ->each(function ($matches): void {
                $match = $matches->first();
                DB::table('flow_documents')->where('id', $match->id)->update([
                    'flow_task_id' => $match->task_id,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('flow_documents', 'flow_task_id')) {
            if (Schema::hasIndex('flow_documents', 'flow_docs_job_task_idx')) {
                Schema::table('flow_documents', function (Blueprint $table): void {
                    $table->dropIndex('flow_docs_job_task_idx');
                });
            }

            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('flow_task_id');
            });
        }
    }
};
