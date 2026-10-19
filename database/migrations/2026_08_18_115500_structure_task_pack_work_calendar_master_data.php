<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records')) return;

        DB::table('master_records')
            ->where('type', 'task_pack_work_calendar')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'metadata'])
            ->each(function ($row): void {
                $metadata = json_decode((string) ($row->metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];

                if ($row->code === 'TPW-001') {
                    $metadata['day_from'] ??= 'monday';
                    $metadata['day_to'] ??= 'friday';
                    $metadata['time_from'] ??= '09:00';
                    $metadata['time_to'] ??= '18:00';
                }

                $updates = ['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                if (trim((string) $row->name) === 'Workspace hours · Mon–Fri, 9:00–18:00') {
                    $updates['name'] = 'Workspace hours';
                }

                DB::table('master_records')->where('id', $row->id)->update($updates);
            });
    }

    public function down(): void
    {
        // Structured Work Calendar metadata is intentionally retained on rollback
        // because deleting user-maintained schedule settings would be destructive.
    }
};
