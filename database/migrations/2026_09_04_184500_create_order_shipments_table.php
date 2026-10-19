<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsMultipleFlag = ! Schema::hasColumn('flow_jobs', 'allow_multiple_shipments');
        $needsAddressMode = ! Schema::hasColumn('flow_jobs', 'shipment_address_mode');
        if ($needsMultipleFlag || $needsAddressMode) {
            Schema::table('flow_jobs', function (Blueprint $table) use ($needsMultipleFlag, $needsAddressMode): void {
                if ($needsMultipleFlag) {
                    $table->boolean('allow_multiple_shipments')->default(false)->after('shipment_urgency_ids');
                }
                if ($needsAddressMode) {
                    $table->string('shipment_address_mode', 30)->default('same_address')->after('allow_multiple_shipments');
                }
            });
        }

        if (! Schema::hasTable('order_shipments')) {
            Schema::create('order_shipments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->boolean('is_primary')->default(false);

                $table->string('recipient')->nullable();
                $table->string('phone_country_code', 12)->nullable();
                $table->string('phone', 80)->nullable();
                $table->text('address')->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 120)->nullable();
                $table->string('postal_code', 30)->nullable();
                $table->string('country', 120)->nullable();
                $table->foreignId('shipping_source_address_id')->nullable()->constrained('client_shipping_addresses')->nullOnDelete();

                $table->foreignId('shipment_method_id')->nullable()->constrained('master_records')->nullOnDelete();
                $table->foreignId('shipment_urgency_id')->nullable()->constrained('master_records')->nullOnDelete();
                $table->foreignId('courier_id')->nullable()->constrained('master_records')->nullOnDelete();
                $table->string('package_reference', 255)->nullable();
                $table->string('tracking_number', 255)->nullable();
                $table->timestamp('label_printed_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                // Sequence is indexed, not unique, because removed shipments are
                // soft-deleted and active rows are compacted back to 1..N.
                $table->index(['flow_job_id', 'sequence'], 'order_shipments_job_sequence_index');
                $table->index(['flow_job_id', 'dispatched_at'], 'order_shipments_dispatch_index');
                $table->index(['flow_job_id', 'tracking_number'], 'order_shipments_tracking_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipments');

        $columns = array_values(array_filter(
            ['allow_multiple_shipments', 'shipment_address_mode'],
            fn (string $column): bool => Schema::hasColumn('flow_jobs', $column),
        ));
        if ($columns !== []) {
            Schema::table('flow_jobs', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
