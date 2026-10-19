<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_phases')) {
            return;
        }

        if (! Schema::hasColumn('workflow_phases', 'color')) {
            Schema::table('workflow_phases', function (Blueprint $table): void {
                $table->string('color', 7)->nullable()->after('short_name');
            });
        }

        // Persist colors for all existing phases so every screen reads the
        // configured database value instead of deriving presentation colors.
        $palette = [
            '#7C3AED', '#2563EB', '#D97706', '#059669',
            '#DB2777', '#0891B2', '#DC2626', '#4F46E5',
        ];

        $sourceColors = [];
        DB::table('workflow_phases')
            ->whereNull('color')
            ->whereNotNull('workflow_template_id')
            ->orderBy('workflow_template_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'sequence'])
            ->each(function ($phase) use ($palette, &$sourceColors): void {
                $color = $palette[(max(1, (int) $phase->sequence) - 1) % count($palette)];
                DB::table('workflow_phases')->where('id', $phase->id)->update(['color' => $color]);
                $sourceColors[(int) $phase->id] = $color;
            });

        // Existing Order snapshots keep the same visual identity as the source
        // phase they were copied from. Any legacy phase without a source id gets
        // a stored sequence-based color once during this migration.
        DB::table('workflow_phases')
            ->whereNull('color')
            ->orderBy('id')
            ->get(['id', 'source_workflow_phase_id', 'sequence'])
            ->each(function ($phase) use ($palette, &$sourceColors): void {
                $sourceId = (int) ($phase->source_workflow_phase_id ?? 0);
                $color = $sourceColors[$sourceId] ?? null;

                if (! $color && $sourceId > 0) {
                    $color = DB::table('workflow_phases')->where('id', $sourceId)->value('color');
                }

                $color = $color ?: $palette[(max(1, (int) $phase->sequence) - 1) % count($palette)];
                DB::table('workflow_phases')->where('id', $phase->id)->update(['color' => $color]);
                $sourceColors[(int) $phase->id] = $color;
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_phases') && Schema::hasColumn('workflow_phases', 'color')) {
            Schema::table('workflow_phases', function (Blueprint $table): void {
                $table->dropColumn('color');
            });
        }
    }
};
