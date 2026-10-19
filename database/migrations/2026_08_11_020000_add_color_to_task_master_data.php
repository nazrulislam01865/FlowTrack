<?php

use App\Support\MasterColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records')) return;

        if (!Schema::hasColumn('master_records', 'color')) {
            Schema::table('master_records', function (Blueprint $table): void {
                $table->string('color', 7)->nullable();
            });
        }

        DB::table('master_records')
            ->whereIn('type', ['task_status', 'task_flag'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'type', 'name', 'color'])
            ->each(function ($record): void {
                if (MasterColor::normalize($record->color)) return;

                DB::table('master_records')
                    ->where('id', $record->id)
                    ->update([
                        'color' => MasterColor::defaultFor((string) $record->type, (string) $record->name),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('master_records') || !Schema::hasColumn('master_records', 'color')) return;

        Schema::table('master_records', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
