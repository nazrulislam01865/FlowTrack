<?php

use App\Models\FlowNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_notifications') || Schema::hasColumn('flow_notifications', 'actor_id')) {
            return;
        }

        Schema::table('flow_notifications', function (Blueprint $table): void {
            $table->foreignId('actor_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        FlowNotification::forgetActorSchemaCache();

        if (! Schema::hasTable('users')) {
            return;
        }

        // Older mention rows stored the actor only inside the title (for example
        // "Amy mentioned you in ..."). Recover that identity when the actor name
        // is unique so already-existing dashboard rows can immediately show the
        // correct profile photo after this migration is deployed.
        $usersByName = DB::table('users')
            ->select(['id', 'name'])
            ->get()
            ->groupBy(fn ($user) => mb_strtolower(trim((string) $user->name)))
            ->filter(fn ($users) => $users->count() === 1)
            ->map(fn ($users) => (int) $users->first()->id);

        if ($usersByName->isEmpty()) {
            return;
        }

        DB::table('flow_notifications')
            ->whereNull('actor_id')
            ->whereIn('type', ['mention', 'mention_admin'])
            ->orderBy('id')
            ->chunkById(500, function ($notifications) use ($usersByName): void {
                foreach ($notifications as $notification) {
                    $title = trim((string) $notification->title);
                    $actorName = null;

                    foreach ([' mentioned you in ', ' mentioned a user in '] as $separator) {
                        $position = mb_strpos($title, $separator);
                        if ($position === false) {
                            continue;
                        }

                        $actorName = trim(mb_substr($title, 0, $position));
                        break;
                    }

                    if (!$actorName) {
                        continue;
                    }

                    $actorId = $usersByName->get(mb_strtolower($actorName));
                    if (!$actorId) {
                        continue;
                    }

                    DB::table('flow_notifications')
                        ->where('id', $notification->id)
                        ->update(['actor_id' => $actorId]);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_notifications') || ! Schema::hasColumn('flow_notifications', 'actor_id')) {
            return;
        }

        Schema::table('flow_notifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_id');
        });

        FlowNotification::forgetActorSchemaCache();
    }
};
