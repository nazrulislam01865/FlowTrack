<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('remote_area_lookup_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_area_lookup_versions');
    }
};
