<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('account_manager_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('clients', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('clients', 'archived_by')) {
                $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('clients', 'purged_at')) {
                $table->timestamp('purged_at')->nullable()->after('archived_by');
            }
            if (! Schema::hasColumn('clients', 'purged_by')) {
                $table->foreignId('purged_by')->nullable()->after('purged_at')->constrained('users')->nullOnDelete();
            }
            $table->index(['is_active', 'purged_at', 'archived_at'], 'clients_archive_lookup_idx');
            $table->index(['created_by', 'is_active'], 'clients_creator_status_idx');
        });

        // Existing inactive clients pre-date explicit archive metadata. Their
        // last update is the best available archive timestamp and keeps the
        // Archived date filter useful immediately after deployment.
        DB::table('clients')
            ->where('is_active', false)
            ->whereNull('archived_at')
            ->update(['archived_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex('clients_archive_lookup_idx');
            $table->dropIndex('clients_creator_status_idx');
            foreach (['created_by', 'archived_by', 'purged_by'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropForeign([$column]);
                }
            }

            $columns = array_values(array_filter(
                ['created_by', 'archived_at', 'archived_by', 'purged_at', 'purged_by'],
                fn (string $column): bool => Schema::hasColumn('clients', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
