<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'legal_business_name')) $table->string('legal_business_name')->nullable()->after('name');
            if (! Schema::hasColumn('clients', 'website')) $table->string('website')->nullable()->after('legal_business_name');
            if (! Schema::hasColumn('clients', 'preferred_currency')) $table->string('preferred_currency', 3)->default('USD')->after('preferred_language');
            if (! Schema::hasColumn('clients', 'contact_job_title')) $table->string('contact_job_title')->nullable()->after('contact_name');
            if (! Schema::hasColumn('clients', 'office_address_line1')) $table->string('office_address_line1', 255)->nullable()->after('office_address');
            if (! Schema::hasColumn('clients', 'office_suite')) $table->string('office_suite', 120)->nullable()->after('office_address_line1');
            if (! Schema::hasColumn('clients', 'office_city')) $table->string('office_city', 120)->nullable()->after('office_suite');
            if (! Schema::hasColumn('clients', 'office_state')) $table->string('office_state', 120)->nullable()->after('office_city');
            if (! Schema::hasColumn('clients', 'office_zip')) $table->string('office_zip', 30)->nullable()->after('office_state');
            if (! Schema::hasColumn('clients', 'billing_same_as_office')) $table->boolean('billing_same_as_office')->default(true)->after('office_zip');
            if (! Schema::hasColumn('clients', 'billing_address_line1')) $table->string('billing_address_line1', 255)->nullable()->after('billing_same_as_office');
            if (! Schema::hasColumn('clients', 'billing_suite')) $table->string('billing_suite', 120)->nullable()->after('billing_address_line1');
            if (! Schema::hasColumn('clients', 'billing_city')) $table->string('billing_city', 120)->nullable()->after('billing_suite');
            if (! Schema::hasColumn('clients', 'billing_state')) $table->string('billing_state', 120)->nullable()->after('billing_city');
            if (! Schema::hasColumn('clients', 'billing_zip')) $table->string('billing_zip', 30)->nullable()->after('billing_state');
            if (! Schema::hasColumn('clients', 'billing_country')) $table->string('billing_country', 120)->nullable()->after('billing_zip');
            if (! Schema::hasColumn('clients', 'ein_tax_id')) $table->string('ein_tax_id', 80)->nullable()->after('billing_country');
            if (! Schema::hasColumn('clients', 'sales_tax_status')) $table->string('sales_tax_status', 30)->default('taxable')->after('ein_tax_id');
            if (! Schema::hasColumn('clients', 'payment_terms')) $table->string('payment_terms', 60)->nullable()->after('sales_tax_status');
            if (! Schema::hasColumn('clients', 'po_required')) $table->boolean('po_required')->default(false)->after('payment_terms');
            if (! Schema::hasColumn('clients', 'is_draft')) $table->boolean('is_draft')->default(false)->after('is_active');
        });

        if (! Schema::hasTable('client_shipping_addresses')) {
            Schema::create('client_shipping_addresses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('label');
                $table->string('recipient')->nullable();
                $table->string('address_line1');
                $table->string('suite', 120)->nullable();
                $table->string('city', 120);
                $table->string('state', 120);
                $table->string('zip', 30);
                $table->string('country', 120)->default('United States');
                $table->boolean('is_default')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['client_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_shipping_addresses');
        $columns = [
            'legal_business_name','website','preferred_currency','contact_job_title',
            'office_address_line1','office_suite','office_city','office_state','office_zip',
            'billing_same_as_office','billing_address_line1','billing_suite','billing_city','billing_state','billing_zip','billing_country',
            'ein_tax_id','sales_tax_status','payment_terms','po_required','is_draft',
        ];
        $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('clients', $column)));
        if ($existing) Schema::table('clients', fn (Blueprint $table) => $table->dropColumn($existing));
    }
};
