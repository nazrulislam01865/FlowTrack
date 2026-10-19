<?php

namespace App\Console\Commands;

use App\Services\OrderWorkflowBindingService;
use App\Services\OrderWorkflowSetupService;
use Illuminate\Console\Command;

class SyncOrderWorkflow extends Command
{
    protected $signature = 'flowtrack:sync-order-workflow';

    protected $description = 'Synchronize active Orders with their configured Order workflows';

    public function handle(OrderWorkflowBindingService $binding): int
    {
        $workflows = OrderWorkflowSetupService::orderWorkflowQuery()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($workflows->isEmpty()) {
            $this->error('No active Order workflow is configured in Workflow Setup.');
            return self::FAILURE;
        }

        $total = 0;
        foreach ($workflows as $workflow) {
            if (! app(OrderWorkflowSetupService::class)->isReadyForOrderCreation((int) $workflow->id)) {
                $this->warn("Skipped {$workflow->name}: seven-stage Order setup is incomplete.");
                continue;
            }
            $count = $binding->syncActiveOrders((int) $workflow->id);
            $total += $count;
            $this->line("{$workflow->name}: synchronized {$count} active Order(s).");
        }

        $this->info("Order workflow synchronization complete: {$total} active Order(s) checked.");
        return self::SUCCESS;
    }
}
