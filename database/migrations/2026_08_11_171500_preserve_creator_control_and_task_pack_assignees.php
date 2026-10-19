<?php

use App\Models\FlowJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_jobs') && ! Schema::hasColumn('flow_jobs', 'created_by')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->foreignId('created_by')->nullable()->after('coordinator_id')->constrained('users')->nullOnDelete();
                $table->index(['created_by', 'deleted_at'], 'flow_jobs_creator_deleted_idx');
            });
        }

        if (Schema::hasTable('flow_jobs') && Schema::hasColumn('flow_jobs', 'created_by') && Schema::hasTable('activities')) {
            DB::table('flow_jobs')->whereNull('created_by')->orderBy('id')->chunkById(200, function ($jobs): void {
                foreach ($jobs as $job) {
                    $creatorId = DB::table('activities')
                        ->where('subject_type', FlowJob::class)
                        ->where('subject_id', $job->id)
                        ->whereIn('event', ['job.created', 'job.draft_saved'])
                        ->whereNotNull('user_id')
                        ->orderBy('id')
                        ->value('user_id');

                    if (! $creatorId) {
                        $creatorId = DB::table('activities')
                            ->where('subject_type', FlowJob::class)
                            ->where('subject_id', $job->id)
                            ->whereNotNull('user_id')
                            ->orderBy('id')
                            ->value('user_id');
                    }

                    $creatorId ??= $job->owner_id ?: $job->coordinator_id;
                    if ($creatorId) DB::table('flow_jobs')->where('id', $job->id)->update(['created_by' => $creatorId]);
                }
            });
        }

        if (Schema::hasTable('inquiry_tasks') && ! Schema::hasColumn('inquiry_tasks', 'setup_assignee_id')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                $table->foreignId('setup_assignee_id')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
                $table->index(['source_task_pack_item_id', 'setup_assignee_id'], 'inq_tasks_setup_assignee_idx');
            });
        }

        if (Schema::hasTable('inquiry_tasks') && Schema::hasColumn('inquiry_tasks', 'setup_assignee_id') && Schema::hasTable('task_pack_items')) {
            DB::table('inquiry_tasks')
                ->whereNotNull('source_task_pack_item_id')
                ->orderBy('id')
                ->chunkById(200, function ($tasks): void {
                    foreach ($tasks as $task) {
                        $defaultAssigneeId = DB::table('task_pack_items')
                            ->where('id', $task->source_task_pack_item_id)
                            ->value('default_assignee_id');
                        if (! $defaultAssigneeId) continue;

                        $updates = ['setup_assignee_id' => $defaultAssigneeId];
                        if (! $task->assignee_id) $updates['assignee_id'] = $defaultAssigneeId;
                        DB::table('inquiry_tasks')->where('id', $task->id)->update($updates);
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inquiry_tasks') && Schema::hasColumn('inquiry_tasks', 'setup_assignee_id')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                $table->dropIndex('inq_tasks_setup_assignee_idx');
                $table->dropConstrainedForeignId('setup_assignee_id');
            });
        }

        if (Schema::hasTable('flow_jobs') && Schema::hasColumn('flow_jobs', 'created_by')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->dropIndex('flow_jobs_creator_deleted_idx');
                $table->dropConstrainedForeignId('created_by');
            });
        }
    }
};
