<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flowtrack_sequences')) {
            return;
        }

        Schema::create('flowtrack_sequences', function (Blueprint $table): void {
            $table->string('key', 160)->primary();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowtrack_sequences');
    }
};
