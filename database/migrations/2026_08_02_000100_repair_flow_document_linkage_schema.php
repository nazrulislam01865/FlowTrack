<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_documents')) {
            return;
        }

        $this->addMissingColumns();

        $this->addIndexIfMissing(
            ['flow_job_id', 'workflow_phase_id', 'document_category_id'],
            'flow_docs_job_phase_category_idx',
        );
        $this->addIndexIfMissing(
            ['flow_job_id', 'flow_task_id'],
            'flow_docs_job_task_idx',
        );
        $this->addIndexIfMissing(
            ['flow_task_id', 'document_category_id', 'deleted_at'],
            'ft_documents_task_category_active_idx',
        );

        $this->reconcileUnambiguousTaskLinks();
    }

    /**
     * This is an additive production repair. Rolling it back would recreate the
     * broken schema and can discard document linkage, so down() is intentionally
     * non-destructive.
     */
    public function down(): void {}

    private function addMissingColumns(): void
    {
        if (! Schema::hasColumn('flow_documents', 'workflow_phase_id')) {
            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->foreignId('workflow_phase_id')
                    ->nullable()
                    ->constrained('workflow_phases')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('flow_documents', 'task_pack_item_id')) {
            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->foreignId('task_pack_item_id')
                    ->nullable()
                    ->constrained('task_pack_items')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('flow_documents', 'flow_task_id')) {
            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->foreignId('flow_task_id')
                    ->nullable()
                    ->constrained('flow_tasks')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('flow_documents', 'linked_by')) {
            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->foreignId('linked_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('flow_documents', 'linked_at')) {
            Schema::table('flow_documents', function (Blueprint $table): void {
                $table->timestamp('linked_at')->nullable();
            });
        }
    }

    /** @param list<string> $columns */
    private function addIndexIfMissing(array $columns, string $indexName): void
    {
        if (! Schema::hasColumns('flow_documents', $columns)
            || Schema::hasIndex('flow_documents', $indexName)) {
            return;
        }

        Schema::table('flow_documents', function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function reconcileUnambiguousTaskLinks(): void
    {
        if (! Schema::hasTable('flow_tasks')
            || ! Schema::hasColumns('flow_documents', [
                'id',
                'flow_job_id',
                'workflow_phase_id',
                'task_pack_item_id',
                'document_category_id',
                'flow_task_id',
                'deleted_at',
            ])) {
            return;
        }

        DB::table('flow_documents')
            ->whereNull('flow_task_id')
            ->whereNull('deleted_at')
            ->whereNotNull('task_pack_item_id')
            ->select(['id', 'flow_job_id', 'workflow_phase_id', 'task_pack_item_id'])
            ->orderBy('id')
            ->chunkById(200, function ($documents): void {
                foreach ($documents as $document) {
                    $taskIds = DB::table('flow_tasks')
                        ->where('flow_job_id', $document->flow_job_id)
                        ->where('workflow_phase_id', $document->workflow_phase_id)
                        ->where('task_pack_item_id', $document->task_pack_item_id)
                        ->whereNull('deleted_at')
                        ->limit(2)
                        ->pluck('id');

                    if ($taskIds->count() === 1) {
                        DB::table('flow_documents')->where('id', $document->id)->update([
                            'flow_task_id' => (int) $taskIds->first(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }
};
