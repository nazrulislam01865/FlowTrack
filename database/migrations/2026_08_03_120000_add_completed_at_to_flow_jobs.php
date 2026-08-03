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
            if (! Schema::hasColumn('flow_jobs', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('status');
                $table->index(['workspace_id', 'status', 'completed_at'], 'ft_jobs_workspace_status_completed_idx');
            }
        });

        // Preserve a useful completion timestamp for Jobs that were already
        // completed before this column existed. Prefer the final completed
        // workflow phase timestamp and fall back to the Job's last update.
        DB::table('flow_jobs')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->orderBy('id')
            ->chunkById(200, function ($jobs): void {
                foreach ($jobs as $job) {
                    $phaseCompletedAt = DB::table('flow_job_phase_histories')
                        ->where('flow_job_id', $job->id)
                        ->whereNotNull('completed_at')
                        ->max('completed_at');

                    DB::table('flow_jobs')
                        ->where('id', $job->id)
                        ->update(['completed_at' => $phaseCompletedAt ?: $job->updated_at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_jobs', 'completed_at')) {
                $table->dropIndex('ft_jobs_workspace_status_completed_idx');
                $table->dropColumn('completed_at');
            }
        });
    }
};
