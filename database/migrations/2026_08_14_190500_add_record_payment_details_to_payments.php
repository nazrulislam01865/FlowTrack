<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('received_account', 120)->nullable()->after('reference');
            $table->string('receipt_path')->nullable()->after('received_account');
            $table->string('receipt_name')->nullable()->after('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['received_account', 'receipt_path', 'receipt_name']);
        });
    }
};
