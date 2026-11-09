<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('workflow_display_version')->default(0);
        });

        Schema::create('order_workflow_read_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->unique()->constrained('flow_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('source_version');
            $table->string('definition_revision', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->longText('projection_json');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_workflow_read_snapshots');
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropColumn('workflow_display_version');
        });
    }
};
