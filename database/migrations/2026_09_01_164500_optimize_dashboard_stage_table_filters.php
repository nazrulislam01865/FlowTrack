<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_jobs')) {
            return;
        }

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->index(
                ['source_workflow_phase_id', 'deleted_at', 'completed_at', 'updated_at', 'id'],
                'ft_jobs_source_phase_open_updated_idx',
            );
            $table->index(
                ['workflow_phase_id', 'deleted_at', 'completed_at', 'updated_at', 'id'],
                'ft_jobs_phase_open_updated_idx',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_jobs')) {
            return;
        }

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropIndex('ft_jobs_source_phase_open_updated_idx');
            $table->dropIndex('ft_jobs_phase_open_updated_idx');
        });
    }
};
