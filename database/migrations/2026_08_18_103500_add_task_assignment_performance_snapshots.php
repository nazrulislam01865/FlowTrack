<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (!Schema::hasColumn('tasks', 'assignee_assigned_at')) {
                    $table->timestamp('assignee_assigned_at')->nullable()->after('assignee_id');
                }
                if (!Schema::hasColumn('tasks', 'assignee_at_completion')) {
                    $table->foreignId('assignee_at_completion')->nullable()->after('assignee_assigned_at')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('tasks', 'assignee_assigned_at_completion')) {
                    $table->timestamp('assignee_assigned_at_completion')->nullable()->after('assignee_at_completion');
                }
            });

            DB::table('tasks')
                ->whereNotNull('assignee_id')
                ->whereNull('assignee_assigned_at')
                ->update(['assignee_assigned_at' => DB::raw('created_at')]);

            DB::table('tasks')
                ->whereNotNull('assignee_id')
                ->where(function ($query): void {
                    $query->whereNotNull('completed_at')
                        ->orWhereIn(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), ['completed', 'complete', 'done']);
                })
                ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), ['cancelled', 'canceled'])
                ->update([
                    'assignee_at_completion' => DB::raw('assignee_id'),
                    'assignee_assigned_at_completion' => DB::raw('COALESCE(assignee_assigned_at, created_at)'),
                ]);

            Schema::table('tasks', function (Blueprint $table): void {
                $table->index(['assignee_id', 'assignee_assigned_at'], 'tasks_assignee_period_idx');
                $table->index(['assignee_at_completion', 'assignee_assigned_at_completion'], 'tasks_completion_credit_period_idx');
            });
        }

        if (Schema::hasTable('inquiry_tasks')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                if (!Schema::hasColumn('inquiry_tasks', 'assignee_assigned_at')) {
                    $table->timestamp('assignee_assigned_at')->nullable()->after('assignee_id');
                }
                if (!Schema::hasColumn('inquiry_tasks', 'assignee_at_completion')) {
                    $table->foreignId('assignee_at_completion')->nullable()->after('assignee_assigned_at')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('inquiry_tasks', 'assignee_assigned_at_completion')) {
                    $table->timestamp('assignee_assigned_at_completion')->nullable()->after('assignee_at_completion');
                }
            });

            DB::table('inquiry_tasks')
                ->whereNotNull('assignee_id')
                ->whereNull('assignee_assigned_at')
                ->update(['assignee_assigned_at' => DB::raw('created_at')]);

            DB::table('inquiry_tasks')
                ->whereNotNull('assignee_id')
                ->where(function ($query): void {
                    $query->whereNotNull('completed_at')
                        ->orWhereIn(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), ['completed', 'complete', 'done']);
                })
                ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), ['cancelled', 'canceled'])
                ->update([
                    'assignee_at_completion' => DB::raw('assignee_id'),
                    'assignee_assigned_at_completion' => DB::raw('COALESCE(assignee_assigned_at, created_at)'),
                ]);

            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                $table->index(['assignee_id', 'assignee_assigned_at'], 'inq_tasks_assignee_period_idx');
                $table->index(['assignee_at_completion', 'assignee_assigned_at_completion'], 'inq_tasks_completion_credit_period_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inquiry_tasks')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                if (Schema::hasColumn('inquiry_tasks', 'assignee_assigned_at_completion')) {
                    $table->dropIndex('inq_tasks_completion_credit_period_idx');
                }
                if (Schema::hasColumn('inquiry_tasks', 'assignee_assigned_at')) {
                    $table->dropIndex('inq_tasks_assignee_period_idx');
                }
                if (Schema::hasColumn('inquiry_tasks', 'assignee_at_completion')) {
                    $table->dropForeign(['assignee_at_completion']);
                }
                $columns = array_values(array_filter([
                    Schema::hasColumn('inquiry_tasks', 'assignee_assigned_at') ? 'assignee_assigned_at' : null,
                    Schema::hasColumn('inquiry_tasks', 'assignee_at_completion') ? 'assignee_at_completion' : null,
                    Schema::hasColumn('inquiry_tasks', 'assignee_assigned_at_completion') ? 'assignee_assigned_at_completion' : null,
                ]));
                if ($columns !== []) $table->dropColumn($columns);
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (Schema::hasColumn('tasks', 'assignee_assigned_at_completion')) {
                    $table->dropIndex('tasks_completion_credit_period_idx');
                }
                if (Schema::hasColumn('tasks', 'assignee_assigned_at')) {
                    $table->dropIndex('tasks_assignee_period_idx');
                }
                if (Schema::hasColumn('tasks', 'assignee_at_completion')) {
                    $table->dropForeign(['assignee_at_completion']);
                }
                $columns = array_values(array_filter([
                    Schema::hasColumn('tasks', 'assignee_assigned_at') ? 'assignee_assigned_at' : null,
                    Schema::hasColumn('tasks', 'assignee_at_completion') ? 'assignee_at_completion' : null,
                    Schema::hasColumn('tasks', 'assignee_assigned_at_completion') ? 'assignee_assigned_at_completion' : null,
                ]));
                if ($columns !== []) $table->dropColumn($columns);
            });
        }
    }
};
