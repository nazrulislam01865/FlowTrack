<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inquiry_rfq_invitations') || Schema::hasColumn('inquiry_rfq_invitations', 'request_message')) {
            return;
        }

        Schema::table('inquiry_rfq_invitations', function (Blueprint $table): void {
            $table->text('request_message')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inquiry_rfq_invitations') || ! Schema::hasColumn('inquiry_rfq_invitations', 'request_message')) {
            return;
        }

        Schema::table('inquiry_rfq_invitations', function (Blueprint $table): void {
            $table->dropColumn('request_message');
        });
    }
};
