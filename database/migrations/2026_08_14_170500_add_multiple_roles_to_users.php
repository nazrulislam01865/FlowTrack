<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table): void {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['user_id', 'role_id']);
                $table->index(['role_id', 'user_id'], 'ft_user_roles_role_user_idx');
            });
        }

        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role_id')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('role_id')
            ->orderBy('id')
            ->select(['id', 'role_id', 'created_at', 'updated_at'])
            ->chunkById(500, function ($users): void {
                $rows = [];
                foreach ($users as $user) {
                    $rows[] = [
                        'user_id' => (int) $user->id,
                        'role_id' => (int) $user->role_id,
                        'created_at' => $user->created_at ?: now(),
                        'updated_at' => $user->updated_at ?: now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('user_roles')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
