<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_jobs', 'attention_requested')) {
                $table->boolean('attention_requested')->default(false)->after('needs_attention')->index();
            }
            if (! Schema::hasColumn('flow_jobs', 'attention_reason')) {
                $table->text('attention_reason')->nullable()->after('attention_requested');
            }
            if (! Schema::hasColumn('flow_jobs', 'attention_by')) {
                $table->foreignId('attention_by')->nullable()->after('attention_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('flow_jobs', 'attention_at')) {
                $table->timestamp('attention_at')->nullable()->after('attention_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_jobs', 'attention_by')) {
                $table->dropConstrainedForeignId('attention_by');
            }
            foreach (['attention_at', 'attention_reason', 'attention_requested'] as $column) {
                if (Schema::hasColumn('flow_jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
