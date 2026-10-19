<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_pack_items')) return;

        Schema::table('task_pack_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('task_pack_items', 'standard_duration_value')) {
                $table->decimal('standard_duration_value', 8, 2)->nullable()->after('due_offset_days');
            }
            if (!Schema::hasColumn('task_pack_items', 'standard_duration_unit')) {
                $table->string('standard_duration_unit', 32)->default('business_hours')->after('standard_duration_value');
            }
            if (!Schema::hasColumn('task_pack_items', 'timer_start_rule')) {
                $table->string('timer_start_rule', 64)->default('status_in_progress')->after('standard_duration_unit');
            }
            if (!Schema::hasColumn('task_pack_items', 'timer_stop_rule')) {
                $table->string('timer_stop_rule', 64)->default('status_completed')->after('timer_start_rule');
            }
            if (!Schema::hasColumn('task_pack_items', 'pause_statuses')) {
                $table->json('pause_statuses')->nullable()->after('timer_stop_rule');
            }
            if (!Schema::hasColumn('task_pack_items', 'work_calendar')) {
                $table->string('work_calendar', 64)->default('workspace_hours')->after('pause_statuses');
            }
            if (!Schema::hasColumn('task_pack_items', 'set_due_from_standard_duration')) {
                $table->boolean('set_due_from_standard_duration')->default(true)->after('work_calendar');
            }
            if (!Schema::hasColumn('task_pack_items', 'allow_efficiency_override')) {
                $table->boolean('allow_efficiency_override')->default(false)->after('set_due_from_standard_duration');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('task_pack_items')) return;

        $columns = [
            'standard_duration_value',
            'standard_duration_unit',
            'timer_start_rule',
            'timer_stop_rule',
            'pause_statuses',
            'work_calendar',
            'set_due_from_standard_duration',
            'allow_efficiency_override',
        ];

        $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('task_pack_items', $column)));
        if ($existing) {
            Schema::table('task_pack_items', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
