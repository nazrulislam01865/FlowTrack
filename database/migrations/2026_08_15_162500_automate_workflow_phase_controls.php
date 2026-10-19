<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workflow_phases') || !Schema::hasColumn('workflow_phases', 'workflow_template_id')) {
            return;
        }

        $updates = [];
        if (Schema::hasColumn('workflow_phases', 'allow_job_start')) {
            $updates['allow_job_start'] = true;
        }
        if (Schema::hasColumn('workflow_phases', 'is_skippable')) {
            $updates['is_skippable'] = true;
        }
        if (Schema::hasColumn('workflow_phases', 'can_skip')) {
            $updates['can_skip'] = true;
        }
        if (Schema::hasColumn('workflow_phases', 'auto_advance_on_ready')) {
            $updates['auto_advance_on_ready'] = true;
        }

        if ($updates === []) {
            return;
        }

        // Only reusable setup phases have workflow_template_id. Job snapshot
        // phases use workflow_id with workflow_template_id = NULL, so existing
        // Jobs keep the exact controls captured when they were created.
        DB::table('workflow_phases')
            ->whereNotNull('workflow_template_id')
            ->update($updates);
    }

    public function down(): void
    {
        // Previous per-phase choices cannot be reconstructed safely. Leaving the
        // normalized values in place is safer than guessing historical settings.
    }
};
