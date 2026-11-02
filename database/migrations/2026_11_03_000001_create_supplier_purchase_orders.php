<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('po_number', 100)->unique();
            $table->uuid('batch_uuid');
            $table->string('mode', 12);
            $table->json('items_snapshot');
            $table->string('pdf_path');
            $table->string('pdf_name');
            $table->string('email_status', 16)->default('not_sent');
            $table->json('recipient_emails')->nullable();
            $table->string('email_subject')->nullable();
            $table->text('email_error')->nullable();
            $table->string('email_tracking_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['flow_job_id', 'task_id']);
        });

        // Both published Order templates and independently configured Order
        // task packs receive the new task. Existing completed tasks are kept.
        if (!Schema::hasTable('workflow_phases') || !Schema::hasTable('task_pack_items')) return;

        $packIds = DB::table('workflow_phases as phase')
            ->join('workflow_templates as flow', 'flow.id', '=', 'phase.workflow_template_id')
            ->where('flow.applies_to', 'orders')
            ->where('phase.sequence', 2)
            ->whereNotNull('phase.task_pack_id')
            ->distinct()
            ->pluck('phase.task_pack_id');

        foreach ($packIds as $packId) {
            $items = DB::table('task_pack_items')->where('task_pack_id', $packId)
                ->orderBy('sort_order')->orderBy('id')->get();
            if ($items->contains(fn ($item) => $item->automation_key === 'ART_CREATE_SUPPLIER_PO')) continue;
            $review = $items->first(fn ($item) => $item->automation_key === 'ART_INTERNAL_REVIEW');
            if (!$review) continue;
            $after = (int) $review->sort_order;
            $workspaceId = DB::table('task_packs')->where('id', $packId)->value('workspace_id');
            $orderTeamId = DB::table('master_records')->where('workspace_id', $workspaceId)
                ->where('type', 'department')->where('name', 'Order Team')->value('id');
            DB::table('task_pack_items')->where('task_pack_id', $packId)
                ->where('sort_order', '>', $after)->increment('sort_order');
            DB::table('task_pack_items')->insert([
                'task_pack_id' => $packId,
                'automation_key' => 'ART_CREATE_SUPPLIER_PO',
                'title' => 'Create PO / Purchase Order',
                'description' => 'Create one or multiple supplier purchase orders and send each generated PDF to its supplier.',
                'default_department_id' => $orderTeamId ?: $review->default_department_id,
                'default_assignee_id' => null,
                'priority_id' => $review->priority_id,
                'due_offset_days' => 0,
                'is_required' => true,
                'sort_order' => $after + 1,
                'document_required_before_completion' => false,
                'allow_multiple_documents' => true,
                'color' => '#7b61c9',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // The workflow runtime still references task_pack_tasks through
            // tasks.task_pack_task_id. Build the legacy mirror immediately,
            // including re-numbering the shifted entries, rather than leaving
            // the live Order workflow temporarily inconsistent.
            $this->mirrorPackToLegacy((int) $packId);
        }
    }

    private function mirrorPackToLegacy(int $packId): void
    {
        if (!Schema::hasTable('task_pack_tasks')) return;

        $items = DB::table('task_pack_items')->where('task_pack_id', $packId)
            ->orderBy('sort_order')->orderBy('id')->get();

        // Free the unique (task_pack_id, sequence) range before shifting.
        DB::table('task_pack_tasks')->where('task_pack_id', $packId)
            ->update(['sequence' => DB::raw('sequence + 30000')]);

        foreach ($items->values() as $index => $item) {
            $departmentId = null;
            if ($item->default_department_id && Schema::hasTable('departments')) {
                $code = DB::table('master_records')->where('id', $item->default_department_id)->value('code');
                if ($code) $departmentId = DB::table('departments')->where('code', $code)->value('id');
            }
            $data = [
                'task_pack_id' => $packId, 'title' => $item->title,
                'sequence' => $index + 1, 'is_required' => (bool) $item->is_required,
                'default_department_id' => $departmentId, 'updated_at' => now(),
            ];
            if (Schema::hasColumn('task_pack_tasks', 'color')) $data['color'] = $item->color ?: '#2563EB';
            if (DB::table('task_pack_tasks')->where('id', $item->id)->exists()) {
                DB::table('task_pack_tasks')->where('id', $item->id)->update($data);
            } else {
                DB::table('task_pack_tasks')->insert($data + [
                    'id' => $item->id, 'created_at' => $item->created_at ?? now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Never delete a task pack item already linked to a historical order's
        // generated Task. A rollback drops only the new PO record table.
        Schema::dropIfExists('supplier_purchase_orders');
    }
};
