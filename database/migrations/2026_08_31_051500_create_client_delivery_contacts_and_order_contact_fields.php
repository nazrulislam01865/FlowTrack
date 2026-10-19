<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('client_delivery_contacts')) {
            Schema::create('client_delivery_contacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('contact_type', 30);
                $table->string('name');
                $table->string('phone_country_code', 12)->nullable();
                $table->string('phone', 60);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->index(['client_id', 'contact_type', 'last_used_at'], 'client_delivery_contacts_lookup_index');
                $table->index(['client_id', 'contact_type', 'name'], 'client_delivery_contacts_name_index');
            });
        }

        $hasType = Schema::hasColumn('flow_jobs', 'shipping_contact_type');
        $hasName = Schema::hasColumn('flow_jobs', 'shipping_contact_name');

        if (!$hasType || !$hasName) {
            Schema::table('flow_jobs', function (Blueprint $table) use ($hasType, $hasName): void {
                if (!$hasType) {
                    $table->string('shipping_contact_type', 30)->nullable()->after('shipping_address');
                }
                if (!$hasName) {
                    $table->string('shipping_contact_name')->nullable()->after('shipping_contact_type');
                }
            });
        }
    }

    public function down(): void
    {
        $columns = collect(['shipping_contact_type', 'shipping_contact_name'])
            ->filter(fn (string $column): bool => Schema::hasColumn('flow_jobs', $column))
            ->values()
            ->all();

        if ($columns !== []) {
            Schema::table('flow_jobs', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        Schema::dropIfExists('client_delivery_contacts');
    }
};
