<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_jobs', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('workspace_id')->constrained('users')->nullOnDelete();
                $table->index(['workspace_id', 'created_by']);
            }
        });

        Schema::table('flow_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_tasks', 'removed_from_job_at')) {
                $table->timestamp('removed_from_job_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('flow_tasks', 'removed_from_job_by')) {
                $table->foreignId('removed_from_job_by')->nullable()->after('removed_from_job_at')->constrained('users')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('flow_job_phase_exclusions')) {
            Schema::create('flow_job_phase_exclusions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
                $table->foreignId('workflow_phase_id')->constrained('workflow_phases')->cascadeOnDelete();
                $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->timestamps();
                $table->unique(['flow_job_id', 'workflow_phase_id'], 'flow_job_phase_exclusions_unique');
            });
        }

        DB::table('flow_jobs')
            ->whereNull('created_by')
            ->orderBy('id')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $activityUserId = DB::table('flow_activities')
                        ->where('subject_type', 'App\\Modules\\OrderFlow\\Data\\Models\\FlowJob')
                        ->where('subject_id', $job->id)
                        ->where('event', 'job.created')
                        ->whereNotNull('user_id')
                        ->orderBy('id')
                        ->value('user_id');

                    $creatorId = $activityUserId ?: $job->owner_id ?: $job->coordinator_id;
                    if (! $creatorId) {
                        continue;
                    }

                    DB::table('flow_jobs')->where('id', $job->id)->update(['created_by' => $creatorId]);
                    DB::table('flow_job_members')->updateOrInsert(
                        ['flow_job_id' => $job->id, 'user_id' => $creatorId],
                        [
                            'access_level' => 'manager',
                            'can_manage_tasks' => true,
                            'can_upload_documents' => true,
                            'can_view_financials' => (int) $creatorId === (int) $job->owner_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_job_phase_exclusions');

        Schema::table('flow_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_tasks', 'removed_from_job_by')) {
                $table->dropConstrainedForeignId('removed_from_job_by');
            }
            if (Schema::hasColumn('flow_tasks', 'removed_from_job_at')) {
                $table->dropColumn('removed_from_job_at');
            }
        });

        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_jobs', 'created_by')) {
                $table->dropIndex(['workspace_id', 'created_by']);
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }
};
