<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasPhone = Schema::hasColumn('clients', 'phone');
        $hasLanguage = Schema::hasColumn('clients', 'preferred_language');
        $hasNotes = Schema::hasColumn('clients', 'notes');

        Schema::table('clients', function (Blueprint $table) use ($hasPhone, $hasLanguage, $hasNotes) {
            if (! $hasPhone) $table->string('phone', 60)->nullable()->after('email');
            if (! $hasLanguage) $table->string('preferred_language', 50)->default('English')->after('account_manager_id');
            if (! $hasNotes) $table->text('notes')->nullable()->after('outstanding_balance');
        });
    }

    public function down(): void
    {
        $columns = collect(['phone','preferred_language','notes'])->filter(fn ($column) => Schema::hasColumn('clients', $column))->values()->all();
        if ($columns) Schema::table('clients', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
