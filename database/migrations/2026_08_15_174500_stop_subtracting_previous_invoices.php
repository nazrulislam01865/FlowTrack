<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        if (Schema::hasColumn('invoices', 'subtotal') && Schema::hasColumn('invoices', 'tax_amount') && Schema::hasColumn('invoices', 'total')) {
            // An invoice total belongs to that invoice only. Previous invoices
            // must not be deducted; payments remain separate records.
            DB::table('invoices')->update([
                'total' => DB::raw('ROUND(COALESCE(subtotal, 0) + COALESCE(tax_amount, 0), 2)'),
            ]);
        }

        if (Schema::hasColumn('invoices', 'previously_invoiced')) {
            // Keep the legacy column for schema compatibility, but clear the value
            // so no invoice can accidentally treat it as a deduction later.
            DB::table('invoices')->update(['previously_invoiced' => 0]);
        }

        if (Schema::hasColumn('invoices', 'pdf_layout_version')) {
            // Layout v3 removes the previous-invoice deduction row. Setting the
            // stored version lower makes InvoicePdfService regenerate on access.
            DB::table('invoices')->update(['pdf_layout_version' => 2]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: recreating the incorrect subtraction would
        // make historical invoice totals financially inconsistent again.
    }
};
