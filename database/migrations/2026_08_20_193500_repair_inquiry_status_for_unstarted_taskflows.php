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

        $readyByWorkspace = [];

        if (Schema::hasTable('master_records')) {
            DB::table('master_records')
                ->where('type', 'inquiry_task_status')
                ->whereNull('deleted_at')
                ->orderBy('workspace_id')
                ->orderBy('sort_order')
                ->get(['workspace_id', 'name', 'metadata'])
                ->each(function ($record) use (&$readyByWorkspace): void {
                    $metadata = json_decode((string) ($record->metadata ?? ''), true);
                    $auto = trim((string) ($metadata['auto_inquiry_status'] ?? ''));

                    if (strcasecmp($auto, 'To do') === 0) {
                        $readyByWorkspace[(int) $record->workspace_id][] = mb_strtolower(trim((string) $record->name));
                    }
                });
        }

        DB::table('inquiries')
            ->whereNull('deleted_at')
            ->whereNull('result')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) <> 'draft'")
            ->orderBy('id')
            ->chunkById(200, function ($inquiries) use ($readyByWorkspace): void {
                foreach ($inquiries as $inquiry) {
                    $readyStatuses = collect(array_merge(
                        ['not started', 'ready', 'to do', 'todo'],
                        $readyByWorkspace[(int) $inquiry->workspace_id] ?? []
                    ))->map(fn ($status) => mb_strtolower(trim((string) $status)))
                        ->filter()
                        ->unique()
                        ->values();

                    $tasks = DB::table('inquiry_tasks')
                        ->where('inquiry_id', $inquiry->id)
                        ->whereNull('deleted_at')
                        ->get(['status', 'completed_at']);

                    if ($tasks->isEmpty()) {
                        continue;
                    }

                    $allNotStarted = $tasks->every(function ($task) use ($readyStatuses): bool {
                        if ($task->completed_at !== null) {
                            return false;
                        }

                        return $readyStatuses->contains(mb_strtolower(trim((string) $task->status)));
                    });

                    if (!$allNotStarted) {
                        continue;
                    }

                    DB::table('inquiries')
                        ->where('id', $inquiry->id)
                        ->update([
                            'status' => 'To do',
                            'completed_at' => null,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data repair only. Historical task started_at timestamps are intentionally
        // preserved, so there is no safe reverse mutation for the parent status.
    }
};
