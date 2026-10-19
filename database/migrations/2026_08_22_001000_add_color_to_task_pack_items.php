<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_pack_items')) return;

        if (! Schema::hasColumn('task_pack_items', 'color')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->string('color', 7)->default('#2563EB')->after('description');
            });
        }

        if (Schema::hasTable('task_pack_tasks') && ! Schema::hasColumn('task_pack_tasks', 'color')) {
            Schema::table('task_pack_tasks', function (Blueprint $table): void {
                $table->string('color', 7)->default('#2563EB')->after('title');
            });
        }

        $palette = [
            '#2563EB', '#7C3AED', '#0891B2', '#0F766E',
            '#16A34A', '#CA8A04', '#EA580C', '#DC2626',
            '#DB2777', '#4F46E5', '#0369A1', '#27855A',
        ];
        $hasLegacyPackColor = Schema::hasTable('task_packs') && Schema::hasColumn('task_packs', 'color');

        DB::table('task_pack_items')
            ->orderBy('task_pack_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'task_pack_id', 'sort_order'])
            ->groupBy('task_pack_id')
            ->each(function ($items, $packId) use ($palette, $hasLegacyPackColor): void {
                $legacyPackColor = $hasLegacyPackColor
                    ? DB::table('task_packs')->where('id', $packId)->value('color')
                    : null;
                $legacyPackColor = is_string($legacyPackColor) && preg_match('/^#[0-9A-Fa-f]{6}$/', $legacyPackColor)
                    ? strtoupper($legacyPackColor)
                    : null;

                foreach ($items->values() as $index => $item) {
                    $color = $legacyPackColor ?: $palette[$index % count($palette)];
                    DB::table('task_pack_items')->where('id', $item->id)->update(['color' => $color]);
                    if (Schema::hasTable('task_pack_tasks') && Schema::hasColumn('task_pack_tasks', 'color')) {
                        DB::table('task_pack_tasks')->where('id', $item->id)->update(['color' => $color]);
                    }
                }
            });

        // A short-lived previous build added color to Task Packs themselves.
        // Task color now belongs only to Task Pack items, so clean that column
        // up after safely migrating its value into the individual tasks.
        if ($hasLegacyPackColor && Schema::hasColumn('task_packs', 'color')) {
            Schema::table('task_packs', function (Blueprint $table): void {
                $table->dropColumn('color');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_pack_tasks') && Schema::hasColumn('task_pack_tasks', 'color')) {
            Schema::table('task_pack_tasks', function (Blueprint $table): void {
                $table->dropColumn('color');
            });
        }

        if (Schema::hasTable('task_pack_items') && Schema::hasColumn('task_pack_items', 'color')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->dropColumn('color');
            });
        }

        $legacyPackMigrationWasApplied = Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', '2026_08_21_235500_add_color_to_task_packs')->exists();
        if ($legacyPackMigrationWasApplied && Schema::hasTable('task_packs') && ! Schema::hasColumn('task_packs', 'color')) {
            Schema::table('task_packs', function (Blueprint $table): void {
                $table->string('color', 7)->default('#2563EB')->after('description');
            });
        }
    }
};
