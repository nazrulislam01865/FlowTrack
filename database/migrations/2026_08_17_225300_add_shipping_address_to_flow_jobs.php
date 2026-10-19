<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasAddress = Schema::hasColumn('flow_jobs', 'shipping_address');
        $hasPhoneCode = Schema::hasColumn('flow_jobs', 'shipping_phone_country_code');
        $hasPhone = Schema::hasColumn('flow_jobs', 'shipping_phone');
        $hasPostal = Schema::hasColumn('flow_jobs', 'shipping_postal_code');
        $hasSource = Schema::hasColumn('flow_jobs', 'shipping_source_address_id');

        Schema::table('flow_jobs', function (Blueprint $table) use ($hasAddress, $hasPhoneCode, $hasPhone, $hasPostal, $hasSource): void {
            if (! $hasAddress) {
                $table->text('shipping_address')->nullable()->after('description');
            }
            if (! $hasPhoneCode) {
                $table->string('shipping_phone_country_code', 12)->nullable()->after('shipping_address');
            }
            if (! $hasPhone) {
                $table->string('shipping_phone', 60)->nullable()->after('shipping_phone_country_code');
            }
            if (! $hasPostal) {
                $table->string('shipping_postal_code', 30)->nullable()->after('shipping_phone');
            }
            if (! $hasSource) {
                $table->foreignId('shipping_source_address_id')
                    ->nullable()
                    ->after('shipping_postal_code')
                    ->constrained('client_shipping_addresses')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('flow_jobs', 'shipping_source_address_id')) {
            Schema::table('flow_jobs', fn (Blueprint $table) => $table->dropConstrainedForeignId('shipping_source_address_id'));
        }

        $columns = collect([
            'shipping_address',
            'shipping_phone_country_code',
            'shipping_phone',
            'shipping_postal_code',
        ])->filter(fn (string $column) => Schema::hasColumn('flow_jobs', $column))->values()->all();

        if ($columns) {
            Schema::table('flow_jobs', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
