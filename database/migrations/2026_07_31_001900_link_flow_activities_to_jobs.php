<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_activities', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_activities', 'flow_job_id')) {
                $table->foreignId('flow_job_id')
                    ->nullable()
                    ->after('workspace_id')
                    ->constrained('flow_jobs')
                    ->nullOnDelete();
                $table->index(['flow_job_id', 'created_at']);
            }
        });

        $jobType = 'App\\Modules\\OrderFlow\\Data\\Models\\FlowJob';
        $taskType = 'App\\Modules\\OrderFlow\\Data\\Models\\FlowTask';
        $documentType = 'App\\Modules\\OrderFlow\\Data\\Models\\FlowDocument';

        DB::table('flow_activities')
            ->where('subject_type', $jobType)
            ->whereNull('flow_job_id')
            ->orderBy('id')
            ->chunkById(500, function ($activities): void {
                foreach ($activities as $activity) {
                    if (DB::table('flow_jobs')->where('id', $activity->subject_id)->exists()) {
                        DB::table('flow_activities')->where('id', $activity->id)->update([
                            'flow_job_id' => $activity->subject_id,
                        ]);
                    }
                }
            });

        DB::table('flow_activities')
            ->where('subject_type', $taskType)
            ->whereNull('flow_job_id')
            ->orderBy('id')
            ->chunkById(500, function ($activities): void {
                foreach ($activities as $activity) {
                    $jobId = DB::table('flow_tasks')->where('id', $activity->subject_id)->value('flow_job_id');
                    if ($jobId) {
                        DB::table('flow_activities')->where('id', $activity->id)->update(['flow_job_id' => $jobId]);
                    }
                }
            });

        DB::table('flow_activities')
            ->where('subject_type', $documentType)
            ->whereNull('flow_job_id')
            ->orderBy('id')
            ->chunkById(500, function ($activities): void {
                foreach ($activities as $activity) {
                    $jobId = DB::table('flow_documents')->where('id', $activity->subject_id)->value('flow_job_id');
                    if ($jobId) {
                        DB::table('flow_activities')->where('id', $activity->id)->update(['flow_job_id' => $jobId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('flow_activities', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_activities', 'flow_job_id')) {
                $table->dropIndex(['flow_job_id', 'created_at']);
                $table->dropConstrainedForeignId('flow_job_id');
            }
        });
    }
};
