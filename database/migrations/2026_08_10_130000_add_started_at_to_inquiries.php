<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inquiries', 'started_at')) {
            Schema::table('inquiries', function (Blueprint $table): void {
                $table->timestamp('started_at')->nullable()->after('status')->index();
            });
        }

        if (! Schema::hasTable('inquiry_tasks')) {
            return;
        }

        // Preserve existing history by taking the earliest task start timestamp.
        // From this migration onward the Inquiry owns its own editable start time,
        // while task timestamps continue to represent the individual task history.
        DB::table('inquiries')
            ->whereNull('started_at')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($inquiries): void {
                foreach ($inquiries as $inquiry) {
                    $tasks = DB::table('inquiry_tasks')
                        ->where('inquiry_id', $inquiry->id)
                        ->whereNull('deleted_at')
                        ->orderBy('sequence')
                        ->orderBy('id')
                        ->get(['status', 'started_at', 'completed_at', 'updated_at', 'created_at']);

                    $candidates = $tasks->map(function ($task) {
                        if ($task->started_at) return $task->started_at;
                        if ($task->completed_at) return $task->completed_at;
                        if (strcasecmp((string) $task->status, 'In Progress') === 0) {
                            return $task->updated_at ?: $task->created_at;
                        }
                        return null;
                    })->filter()->sort()->values();

                    if ($candidates->isNotEmpty()) {
                        DB::table('inquiries')->where('id', $inquiry->id)->update(['started_at' => $candidates->first()]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('inquiries', 'started_at')) {
            Schema::table('inquiries', function (Blueprint $table): void {
                $table->dropColumn('started_at');
            });
        }
    }
};
