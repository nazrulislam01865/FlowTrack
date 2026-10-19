<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('flow_jobs', 'tracking_token')) {
                $table->string('tracking_token', 64)->nullable()->unique()->after('order_number');
            }
            if (!Schema::hasColumn('flow_jobs', 'tracking_token_created_at')) {
                $table->timestamp('tracking_token_created_at')->nullable()->after('tracking_token');
            }
            $table->index(['job_number', 'tracking_token'], 'flow_jobs_job_number_tracking_token_idx');
        });

        // Backfill tokens for existing jobs
        $jobs = DB::table('flow_jobs')->whereNull('tracking_token')->get(['id']);
        foreach ($jobs as $job) {
            DB::table('flow_jobs')->where('id', $job->id)->update([
                'tracking_token' => Str::random(32),
                'tracking_token_created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('flow_jobs', 'tracking_token')) {
                $table->dropIndex('flow_jobs_job_number_tracking_token_idx');
                $table->dropColumn(['tracking_token', 'tracking_token_created_at']);
            }
        });
    }
};
