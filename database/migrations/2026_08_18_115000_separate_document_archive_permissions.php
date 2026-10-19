<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_module_access')) return;

        // Preserve everyone's current Document Archive access on deployment by
        // cloning the existing Documents capability row. Administrators can then
        // manage Document Archive independently from Documents in the role matrix.
        foreach (DB::table('roles')->orderBy('id')->get(['id']) as $role) {
            $exists = DB::table('role_module_access')
                ->where('role_id', $role->id)
                ->where('module_code', 'document_archive')
                ->exists();

            if ($exists) continue;

            $documents = DB::table('role_module_access')
                ->where('role_id', $role->id)
                ->where('module_code', 'documents')
                ->first(['record_scope', 'actions']);

            DB::table('role_module_access')->insert([
                'role_id' => $role->id,
                'module_code' => 'document_archive',
                'record_scope' => (string) ($documents?->record_scope ?: 'none'),
                'actions' => $documents?->actions ?: json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_module_access')) return;
        DB::table('role_module_access')->where('module_code', 'document_archive')->delete();
    }
};
