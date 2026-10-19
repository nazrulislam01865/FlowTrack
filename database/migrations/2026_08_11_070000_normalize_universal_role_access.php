<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('role_module_access')) return;

        DB::table('role_module_access')
            ->whereIn('module_code', ['clients', 'workflow', 'masterdata'])
            ->orderBy('id')
            ->get(['id', 'actions'])
            ->each(function ($row): void {
                $actions = is_array($row->actions)
                    ? $row->actions
                    : json_decode((string) ($row->actions ?? '[]'), true);

                DB::table('role_module_access')
                    ->where('id', $row->id)
                    ->update([
                        'record_scope' => is_array($actions) && count($actions) > 0 ? 'all_records' : 'none',
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Universal modules intentionally remain normalized. Previous per-row
        // scopes were not meaningful for these workspace-wide records.
    }
};
