<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_phases', function (Blueprint $table) {
            $table->boolean('auto_advance_on_ready')->default(false)->after('requires_approval');
        });

        Schema::table('flow_job_phase_histories', function (Blueprint $table) {
            $table->string('readiness_status', 30)->default('pending')->after('status');
            $table->timestamp('ready_at')->nullable()->after('completed_at');
            $table->timestamp('readiness_checked_at')->nullable()->after('ready_at');
            $table->json('readiness_details')->nullable()->after('readiness_checked_at');
            $table->index(['flow_job_id', 'readiness_status'], 'fjph_job_readiness_idx');
        });

        Schema::table('flow_jobs', function (Blueprint $table) {
            $table->text('health_reason')->nullable()->after('health');
            $table->timestamp('health_updated_at')->nullable()->after('health_reason');
        });

        Schema::create('flow_transition_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('flow_task_id')->nullable()->constrained('flow_tasks')->nullOnDelete();
            $table->foreignId('workflow_phase_id')->nullable()->constrained('workflow_phases')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transition_type', 60);
            $table->string('source', 60)->default('system');
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['flow_job_id', 'occurred_at'], 'fte_job_time_idx');
            $table->index(['flow_task_id', 'occurred_at'], 'fte_task_time_idx');
            $table->index(['workspace_id', 'transition_type'], 'fte_workspace_type_idx');
        });

        DB::table('flow_job_phase_histories')
            ->where('status', 'current')
            ->update([
                'readiness_status' => 'in_progress',
                'readiness_checked_at' => now(),
            ]);

        DB::table('flow_job_phase_histories')
            ->whereIn('status', ['completed', 'skipped'])
            ->update([
                'readiness_status' => 'complete',
                'readiness_checked_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_transition_events');

        Schema::table('flow_jobs', function (Blueprint $table) {
            $table->dropColumn(['health_reason', 'health_updated_at']);
        });

        Schema::table('flow_job_phase_histories', function (Blueprint $table) {
            $table->dropIndex('fjph_job_readiness_idx');
            $table->dropColumn(['readiness_status', 'ready_at', 'readiness_checked_at', 'readiness_details']);
        });

        Schema::table('workflow_phases', function (Blueprint $table) {
            $table->dropColumn('auto_advance_on_ready');
        });
    }
};
