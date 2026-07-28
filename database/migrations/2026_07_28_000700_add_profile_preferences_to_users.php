<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->after('email_verified_at');
            $table->string('default_landing_page', 30)->default('dashboard')->after('status');
            $table->string('default_task_view', 20)->default('list')->after('default_landing_page');
            $table->string('avatar_path')->nullable()->after('default_task_view');
            $table->json('notification_preferences')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'phone',
                'default_landing_page',
                'default_task_view',
                'avatar_path',
                'notification_preferences',
            ]);
        });
    }
};
