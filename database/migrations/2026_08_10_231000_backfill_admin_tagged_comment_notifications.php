<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_notifications') || ! Schema::hasTable('users') || ! Schema::hasTable('roles')) {
            return;
        }

        $administratorIds = DB::table('users')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.is_active', true)
            ->where(function ($query): void {
                $query->where('users.is_super_admin', true)
                    ->orWhereIn('roles.slug', ['super-admin', 'admin', 'administrator']);
            })
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($administratorIds->isEmpty()) {
            return;
        }

        // Existing mention rows are per tagged recipient. Collapse those recipient
        // copies into one logical mention event, then give every administrator a
        // dashboard copy if they were not already directly tagged. Historical
        // copies are marked read so deploying this change does not create a large
        // artificial unread badge; they still appear under the dashboard's All tab.
        $seen = [];
        $now = now();

        DB::table('flow_notifications')
            ->where('type', 'mention')
            ->where(function ($query): void {
                $query->whereNotNull('flow_job_id')
                    ->orWhereNotNull('flow_task_id')
                    ->orWhereNotNull('inquiry_id')
                    ->orWhereNotNull('inquiry_task_id');
            })
            ->chunkById(500, function ($sources) use ($administratorIds, &$seen, $now): void {
                foreach ($sources as $source) {
                    $signature = hash('sha256', implode('|', [
                        (string) ($source->flow_job_id ?? ''),
                        (string) ($source->flow_task_id ?? ''),
                        (string) ($source->inquiry_id ?? ''),
                        (string) ($source->inquiry_task_id ?? ''),
                        (string) $source->title,
                        (string) $source->message,
                        (string) $source->created_at,
                    ]));

                    if (isset($seen[$signature])) {
                        continue;
                    }
                    $seen[$signature] = true;

                    foreach ($administratorIds as $administratorId) {
                        $alreadyHasEvent = DB::table('flow_notifications')
                            ->where('user_id', $administratorId)
                            ->whereIn('type', ['mention', 'mention_admin'])
                            ->where('flow_job_id', $source->flow_job_id)
                            ->where('flow_task_id', $source->flow_task_id)
                            ->where('inquiry_id', $source->inquiry_id)
                            ->where('inquiry_task_id', $source->inquiry_task_id)
                            ->where('message', $source->message)
                            ->where('created_at', $source->created_at)
                            ->exists();

                        if ($alreadyHasEvent) {
                            continue;
                        }

                        $title = str_replace(' mentioned you in ', ' mentioned a user in ', (string) $source->title);
                        if ($title === (string) $source->title) {
                            $title = 'Tagged comment: '.(string) $source->title;
                        }

                        DB::table('flow_notifications')->insert([
                            'user_id' => $administratorId,
                            'flow_job_id' => $source->flow_job_id,
                            'flow_task_id' => $source->flow_task_id,
                            'inquiry_id' => $source->inquiry_id,
                            'inquiry_task_id' => $source->inquiry_task_id,
                            'type' => 'mention_admin',
                            'title' => $title,
                            'message' => $source->message,
                            'read_at' => $now,
                            'created_at' => $source->created_at,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_notifications')) {
            DB::table('flow_notifications')->where('type', 'mention_admin')->delete();
        }
    }
};
