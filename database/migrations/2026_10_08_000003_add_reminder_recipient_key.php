<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = 'supplier_artwork_reminder_deliveries';
        // MySQL DDL can persist before a later step fails; allow a safe retry.
        if (! Schema::hasColumn($table, 'recipient_key')) {
            Schema::table($table, function (Blueprint $schema): void {
                $schema->string('recipient_key', 120)->nullable()->after('supplier_id');
            });
        }
        // Existing supplier deliveries keep their original send-once identity.
        DB::table($table)->whereNull('recipient_key')->whereNotNull('supplier_id')
            ->update(['recipient_key' => DB::raw("CONCAT('supplier:', supplier_id)")]);
        if (! Schema::hasIndex($table, 'order_reminder_task_recipient_unique')) {
            Schema::table($table, function (Blueprint $schema): void {
                $schema->unique(['task_id', 'recipient_key'], 'order_reminder_task_recipient_unique');
            });
        }
    }

    public function down(): void
    {
        $table = 'supplier_artwork_reminder_deliveries';
        if (Schema::hasIndex($table, 'order_reminder_task_recipient_unique')) {
            Schema::table($table, function (Blueprint $schema): void {
                $schema->dropUnique('order_reminder_task_recipient_unique');
            });
        }
        if (Schema::hasColumn($table, 'recipient_key')) {
            Schema::table($table, function (Blueprint $schema): void {
                $schema->dropColumn('recipient_key');
            });
        }
    }
};
