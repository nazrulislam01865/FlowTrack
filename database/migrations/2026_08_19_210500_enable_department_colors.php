<?php

use App\Support\MasterColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records') || !Schema::hasColumn('master_records', 'color')) return;

        DB::table('master_records')
            ->where('type', 'department')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name', 'color'])
            ->each(function ($record): void {
                if (MasterColor::normalize($record->color)) return;

                DB::table('master_records')
                    ->where('id', $record->id)
                    ->update([
                        'color' => MasterColor::defaultFor('department', (string) $record->name),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Department colors share the existing master_records.color column.
        // Keep saved administrator choices when rolling back this feature.
    }
};