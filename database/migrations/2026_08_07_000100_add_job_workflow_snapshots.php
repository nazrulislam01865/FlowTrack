<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table): void {
            $table->boolean('is_snapshot')->default(false);
            $table->unsignedBigInteger('source_workflow_id')->nullable();
            $table->unsignedBigInteger('snapshot_job_id')->nullable();
            $table->index(['is_snapshot', 'source_workflow_id'], 'workflows_snapshot_source_idx');
            $table->index('snapshot_job_id', 'workflows_snapshot_job_idx');
        });

        Schema::table('workflow_phases', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_workflow_phase_id')->nullable();
            $table->index('source_workflow_phase_id', 'workflow_phases_source_idx');
        });

        Schema::table('task_packs', function (Blueprint $table): void {
            $table->boolean('is_snapshot')->default(false);
            $table->unsignedBigInteger('source_task_pack_id')->nullable();
            $table->unsignedBigInteger('snapshot_job_id')->nullable();
            $table->index(['is_snapshot', 'source_task_pack_id'], 'task_packs_snapshot_source_idx');
            $table->index('snapshot_job_id', 'task_packs_snapshot_job_idx');
        });

        Schema::table('task_pack_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_task_pack_item_id')->nullable();
            $table->index('source_task_pack_item_id', 'task_pack_items_source_idx');
        });

        Schema::table('task_pack_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_task_pack_task_id')->nullable();
            $table->index('source_task_pack_task_id', 'task_pack_tasks_source_idx');
        });

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_workflow_id')->nullable();
            $table->unsignedBigInteger('source_workflow_phase_id')->nullable();
            $table->index(['source_workflow_id', 'source_workflow_phase_id', 'deleted_at'], 'flow_jobs_source_workflow_idx');
        });

        // Existing Jobs are still legacy-linked at this point. Record their
        // setup origin now so Board/filter behavior remains stable; they are
        // converted to full private snapshots automatically before a linked
        // Workflow/Task Pack/phase is deleted.
        DB::table('flow_jobs')->whereNull('source_workflow_id')->update([
            'source_workflow_id' => DB::raw('workflow_id'),
            'source_workflow_phase_id' => DB::raw('workflow_phase_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropIndex('flow_jobs_source_workflow_idx');
            $table->dropColumn(['source_workflow_id', 'source_workflow_phase_id']);
        });

        Schema::table('task_pack_tasks', function (Blueprint $table): void {
            $table->dropIndex('task_pack_tasks_source_idx');
            $table->dropColumn('source_task_pack_task_id');
        });

        Schema::table('task_pack_items', function (Blueprint $table): void {
            $table->dropIndex('task_pack_items_source_idx');
            $table->dropColumn('source_task_pack_item_id');
        });

        Schema::table('task_packs', function (Blueprint $table): void {
            $table->dropIndex('task_packs_snapshot_source_idx');
            $table->dropIndex('task_packs_snapshot_job_idx');
            $table->dropColumn(['is_snapshot', 'source_task_pack_id', 'snapshot_job_id']);
        });

        Schema::table('workflow_phases', function (Blueprint $table): void {
            $table->dropIndex('workflow_phases_source_idx');
            $table->dropColumn('source_workflow_phase_id');
        });

        Schema::table('workflows', function (Blueprint $table): void {
            $table->dropIndex('workflows_snapshot_source_idx');
            $table->dropIndex('workflows_snapshot_job_idx');
            $table->dropColumn(['is_snapshot', 'source_workflow_id', 'snapshot_job_id']);
        });
    }
};
