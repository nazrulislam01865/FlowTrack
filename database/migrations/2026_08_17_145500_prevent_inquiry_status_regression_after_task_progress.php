<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inquiries') || !Schema::hasTable('inquiry_tasks')) {
            return;
        }

        // Repair existing active Inquiries that were incorrectly pushed back to
        // "To do" after a completed task advanced the workflow to a new task that
        // had not started yet. Only repair that exact regression pattern; preserve
        // Draft/final/custom attention statuses and fully completed taskflows.
        DB::table('inquiries')
            ->whereNull('inquiries.deleted_at')
            ->whereNull('inquiries.result')
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) = 'to do'")
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('inquiry_tasks')
                    ->whereColumn('inquiry_tasks.inquiry_id', 'inquiries.id')
                    ->whereNull('inquiry_tasks.deleted_at')
                    ->where(function ($progress): void {
                        $progress->whereNotNull('inquiry_tasks.started_at')
                            ->orWhereNotNull('inquiry_tasks.completed_at');
                    });
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('inquiry_tasks')
                    ->whereColumn('inquiry_tasks.inquiry_id', 'inquiries.id')
                    ->whereNull('inquiry_tasks.deleted_at')
                    ->whereNull('inquiry_tasks.completed_at');
            })
            ->update([
                'status' => 'In Progress',
                'completed_at' => null,
            ]);
    }

    public function down(): void
    {
        // This is a data repair for a lifecycle regression. Reverting repaired
        // records to an incorrect status would be destructive, so there is no
        // reverse data mutation.
    }
};
