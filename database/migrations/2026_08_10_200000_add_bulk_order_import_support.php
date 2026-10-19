<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            // order_number is the external customer/source reference. The bulk
            // importer intentionally supports more than one FlowTrack Order for
            // the same external reference when the user chooses "separate".
            $table->dropUnique(['order_number']);
            $table->index('order_number', 'flow_jobs_order_number_idx');
        });

        Schema::table('flow_jobs', function (Blueprint $table): void {
            // The supplied import contract explicitly allows Client ID to stay
            // blank. Ownership still keeps an unassigned order permission-scoped.
            $table->unsignedBigInteger('client_id')->nullable()->change();

            $table->date('received_date')->nullable()->after('order_number');
            $table->foreignId('supplier_id')->nullable()->after('received_date')->constrained('master_records')->nullOnDelete();
            $table->string('warehouse', 255)->nullable()->after('supplier_id');
            $table->text('supplier_instruction')->nullable()->after('warehouse');
            $table->string('source_row_id', 191)->nullable()->after('supplier_instruction');
            $table->string('import_profile', 30)->nullable()->after('source_row_id');
            $table->string('bulk_import_id', 40)->nullable()->after('import_profile');
            $table->unique('source_row_id', 'flow_jobs_source_row_id_uq');
            $table->index('bulk_import_id', 'flow_jobs_bulk_import_id_idx');
        });

        Schema::create('bulk_order_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('import_number', 80)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('profile', 30);
            $table->foreignId('default_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('default_supplier_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->string('duplicate_policy', 20)->default('skip');
            $table->string('original_filename');
            $table->string('file_fingerprint', 64)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 30)->default('processing');
            $table->timestamps();
            $table->index(['workspace_id', 'created_at'], 'bulk_order_imports_workspace_created_idx');
        });

        Schema::create('bulk_order_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bulk_order_import_id')->constrained('bulk_order_imports')->cascadeOnDelete();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('source_row_id', 191)->nullable();
            $table->string('reference_order_no')->nullable();
            $table->foreignId('flow_job_id')->nullable()->constrained('flow_jobs')->nullOnDelete();
            $table->string('status', 30);
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['bulk_order_import_id', 'status'], 'bulk_order_import_rows_import_status_idx');
            $table->index('source_row_id', 'bulk_order_import_rows_source_idx');
            $table->index('reference_order_no', 'bulk_order_import_rows_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_order_import_rows');
        Schema::dropIfExists('bulk_order_imports');

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropUnique('flow_jobs_source_row_id_uq');
            $table->dropIndex('flow_jobs_bulk_import_id_idx');
            $table->dropColumn([
                'received_date', 'supplier_id', 'warehouse', 'supplier_instruction',
                'source_row_id', 'import_profile', 'bulk_import_id',
            ]);
            $table->dropIndex('flow_jobs_order_number_idx');
        });

        // client_id intentionally remains nullable on rollback because a valid
        // imported order may not have a client and forcing NOT NULL would make
        // rollback destructive. The external order reference also remains a
        // normal index because duplicate references may now be legitimate data.
    }
};
