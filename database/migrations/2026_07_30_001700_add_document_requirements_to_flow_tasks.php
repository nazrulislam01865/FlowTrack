<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_tasks', function (Blueprint $table): void {
            $table->foreignId('document_category_id')
                ->nullable()
                ->after('task_pack_item_id')
                ->constrained('master_records')
                ->nullOnDelete();
            $table->string('document_requirement_source', 30)
                ->nullable()
                ->after('document_category_id');
            $table->index(
                ['flow_job_id', 'workflow_phase_id', 'document_requirement_source', 'document_category_id'],
                'flow_tasks_document_requirement_idx',
            );
        });

        DB::table('flow_tasks as tasks')
            ->join('task_pack_items as items', 'items.id', '=', 'tasks.task_pack_item_id')
            ->whereNotNull('items.document_category_id')
            ->select('tasks.id', 'items.document_category_id')
            ->orderBy('tasks.id')
            ->chunk(250, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('flow_tasks')->where('id', $row->id)->update([
                        'document_category_id' => $row->document_category_id,
                        'document_requirement_source' => 'task_pack_item',
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('flow_tasks', function (Blueprint $table): void {
            $table->dropIndex('flow_tasks_document_requirement_idx');
            $table->dropConstrainedForeignId('document_category_id');
            $table->dropColumn('document_requirement_source');
        });
    }
};
