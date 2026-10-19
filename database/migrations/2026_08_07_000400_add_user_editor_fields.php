<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasWechat = Schema::hasColumn('users', 'wechat_id');
        $hasPhone = Schema::hasColumn('users', 'phone');
        $hasAccountStatus = Schema::hasColumn('users', 'account_status');

        if (! $hasWechat || ! $hasPhone || ! $hasAccountStatus) {
            Schema::table('users', function (Blueprint $table) use ($hasWechat, $hasPhone, $hasAccountStatus) {
                if (! $hasWechat) {
                    $table->string('wechat_id', 80)->nullable();
                }
                if (! $hasPhone) {
                    $table->string('phone', 60)->nullable();
                }
                if (! $hasAccountStatus) {
                    $table->string('account_status', 20)->default('active')->index();
                }
            });
        }

        if (Schema::hasColumn('users', 'account_status') && Schema::hasColumn('users', 'is_active')) {
            DB::table('users')->where('is_active', false)->update(['account_status' => 'inactive']);
            DB::table('users')->where('is_active', true)->where(function ($query) {
                $query->whereNull('account_status')->orWhere('account_status', '');
            })->update(['account_status' => 'active']);
        }

        if (Schema::hasTable('workspace_memberships') && ! Schema::hasColumn('workspace_memberships', 'business_unit')) {
            Schema::table('workspace_memberships', function (Blueprint $table) {
                $table->string('business_unit', 20)->default('both');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workspace_memberships') && Schema::hasColumn('workspace_memberships', 'business_unit')) {
            Schema::table('workspace_memberships', function (Blueprint $table) {
                $table->dropColumn('business_unit');
            });
        }

        $columns = collect(['wechat_id', 'phone', 'account_status'])
            ->filter(fn (string $column) => Schema::hasColumn('users', $column))
            ->values()
            ->all();

        if ($columns) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
