<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('workflow_phases', 'requires_approval')) {
            DB::table('workflow_phases')->update(['requires_approval' => false]);
        }
    }

    public function down(): void
    {
        // Approval operations are intentionally disabled for now.
        // Existing phase approval settings cannot be reconstructed safely.
    }
};
