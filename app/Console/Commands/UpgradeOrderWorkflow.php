<?php

namespace App\Console\Commands;

use App\Models\FlowJob;
use App\Models\WorkflowPhase;
use App\Services\JobService;
use App\Services\OrderWorkflowSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class UpgradeOrderWorkflow extends Command
{
    protected $signature = 'flowtrack:upgrade-order-workflow
        {workflowId : Existing Order workflow template ID, for example 30 or 32}
        {--dry-run : Inspect the workflow and active Orders without changing data}';

    protected $description = 'Upgrade one existing Order workflow from the legacy five-stage structure to the seven-stage runtime';

    public function handle(OrderWorkflowSetupService $setup): int
    {
        $workflowId = (int) $this->argument('workflowId');

        if ($workflowId < 1) {
            $this->error('A valid workflow ID is required.');
            return self::FAILURE;
        }

        $missingColumns = $this->missingRequiredColumns();
        if ($missingColumns !== []) {
            $this->error('The database schema is not ready for the seven-stage Order workflow.');
            $this->line('Run: php artisan migrate');
            $this->newLine();
            $this->line('Missing columns:');
            foreach ($missingColumns as $column) {
                $this->line(' - '.$column);
            }
            return self::FAILURE;
        }

        $workflow = OrderWorkflowSetupService::orderWorkflowQuery()
            ->whereKey($workflowId)
            ->first();

        if (! $workflow) {
            $this->error("Order workflow {$workflowId} was not found in the current workspace.");
            return self::FAILURE;
        }

        $phases = WorkflowPhase::query()
            ->where('workflow_template_id', $workflowId)
            ->orderBy('sequence')
            ->get(['id', 'sequence', 'name', 'task_pack_id']);

        $activeOrderCount = FlowJob::query()
            ->whereNull('deleted_at')
            ->whereNull('completed_at')
            ->whereNotIn('status', JobService::INACTIVE_STATUSES)
            ->where(function ($query) use ($workflowId): void {
                $query->where('source_workflow_id', $workflowId)
                    ->orWhere(function ($legacy) use ($workflowId): void {
                        $legacy->whereNull('source_workflow_id')->where('workflow_id', $workflowId);
                    });
            })
            ->count();

        $this->info("Workflow {$workflow->id}: {$workflow->name} ({$workflow->code})");
        $this->line("Current phases: {$phases->count()}");
        $this->line("Active Orders linked to this workflow: {$activeOrderCount}");
        $this->newLine();

        $this->table(
            ['Sequence', 'Phase', 'Task Pack ID'],
            $phases->map(fn (WorkflowPhase $phase) => [
                (int) $phase->sequence,
                (string) $phase->name,
                $phase->task_pack_id ?: '-',
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry run only. No database records were changed.');
            return self::SUCCESS;
        }

        if ($phases->count() === 7 && $setup->isReadyForOrderCreation($workflowId)) {
            $this->newLine();
            $this->info('This workflow is already a valid seven-stage Order workflow. No changes were made.');
            return self::SUCCESS;
        }

        if ($phases->count() !== 5) {
            $this->newLine();
            $this->error('This command only upgrades the known legacy five-stage structure.');
            $this->line('No changes were made. Inspect this workflow manually before continuing.');
            return self::FAILURE;
        }

        $state = $setup->defaultState();
        $result = $setup->save($workflowId, $state['stages'], false);

        $workflow->refresh();
        $updatedPhases = WorkflowPhase::query()
            ->where('workflow_template_id', $workflowId)
            ->with('taskPack:id,name,code')
            ->orderBy('sequence')
            ->get();

        $this->newLine();
        $this->info('Upgrade completed successfully.');
        $this->line('Synchronized active Orders: '.(int) ($result['synced_order_count'] ?? 0));
        $this->line('Workflow code preserved: '.$workflow->code);
        $this->newLine();

        $this->table(
            ['Sequence', 'Phase', 'Task Pack', 'Task Pack Code'],
            $updatedPhases->map(fn (WorkflowPhase $phase) => [
                (int) $phase->sequence,
                (string) $phase->name,
                (string) ($phase->taskPack?->name ?? '-'),
                (string) ($phase->taskPack?->code ?? '-'),
            ])->all()
        );

        if (! $setup->isReadyForOrderCreation($workflowId)) {
            $this->error('The workflow saved, but readiness validation failed. Do not continue to another workflow yet.');
            return self::FAILURE;
        }

        $this->info('Seven-stage readiness check: PASS');
        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function missingRequiredColumns(): array
    {
        $required = [
            'task_pack_items' => [
                'automation_key',
                'color',
                'document_required_before_completion',
                'allow_multiple_documents',
                'document_instructions',
            ],
            'flow_jobs' => [
                'source_workflow_id',
                'source_workflow_phase_id',
            ],
            'workflow_phases' => [
                'workflow_template_id',
                'color',
            ],
        ];

        $missing = [];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table.' (table)';
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        return $missing;
    }
}
