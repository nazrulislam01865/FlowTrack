<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (!Schema::hasColumn('invoices', 'client_snapshot')) {
                $table->json('client_snapshot')->nullable()->after('company_snapshot');
            }
            if (!Schema::hasColumn('invoices', 'pdf_layout_version')) {
                $table->unsignedSmallInteger('pdf_layout_version')->default(1)->after('pdf_generated_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'pdf_layout_version')) {
                $table->dropColumn('pdf_layout_version');
            }
            if (Schema::hasColumn('invoices', 'client_snapshot')) {
                $table->dropColumn('client_snapshot');
            }
        });
    }
};
