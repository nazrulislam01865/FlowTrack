<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('task_pack_items', function (Blueprint $table): void {
            $table->string('default_assignee_type', 16)->default('user');
        });
        Schema::table('supplier_task_assignments', function (Blueprint $table): void {
            $table->string('assignment_source', 16)->default('manual');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_task_assignments', fn (Blueprint $table) => $table->dropColumn('assignment_source'));
        Schema::table('task_pack_items', fn (Blueprint $table) => $table->dropColumn('default_assignee_type'));
    }
};
