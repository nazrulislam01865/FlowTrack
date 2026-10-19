<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inquiry_tasks', 'started_at')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                $table->timestamp('started_at')->nullable()->after('status');
            });
        }

        // Existing inquiries pre-date the explicit start timestamp. Rebuild the
        // best available history from the sequential task chain so completed and
        // currently active tasks immediately have a useful Started at value.
        if (! Schema::hasTable('inquiries') || ! Schema::hasTable('inquiry_tasks')) {
            return;
        }

        DB::table('inquiries')
            ->select(['id', 'status', 'created_at'])
            ->orderBy('id')
            ->chunkById(100, function ($inquiries): void {
                foreach ($inquiries as $inquiry) {
                    $tasks = DB::table('inquiry_tasks')
                        ->where('inquiry_id', $inquiry->id)
                        ->whereNull('deleted_at')
                        ->orderBy('sequence')
                        ->orderBy('id')
                        ->get(['id', 'created_at', 'completed_at']);

                    $previousCompletedAt = null;
                    $activeRecorded = false;

                    foreach ($tasks as $task) {
                        $startedAt = null;

                        if ($task->completed_at) {
                            $startedAt = $previousCompletedAt ?: $task->created_at ?: $inquiry->created_at;
                            $previousCompletedAt = $task->completed_at;
                        } elseif (! $activeRecorded && (string) $inquiry->status !== 'Draft') {
                            $startedAt = $previousCompletedAt ?: $task->created_at ?: $inquiry->created_at;
                            $activeRecorded = true;
                        }

                        if ($startedAt) {
                            DB::table('inquiry_tasks')->where('id', $task->id)->update(['started_at' => $startedAt]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('inquiry_tasks', 'started_at')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                $table->dropColumn('started_at');
            });
        }
    }
};
