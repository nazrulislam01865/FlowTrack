<?php

namespace App\Console\Commands;

use App\Models\FlowJob;
use App\Services\Orders\OrderWorkflowSummaryService;
use Illuminate\Console\Command;

class RebuildOrderWorkflowSummaries extends Command
{
    protected $signature = 'flowtrack:rebuild-order-workflow-summaries
        {--order= : Rebuild one Order id only}
        {--chunk=100 : Number of Orders per chunk}';

    protected $description = 'Build the materialized Order Details workflow summaries used for fast first paint';

    public function handle(OrderWorkflowSummaryService $summaries): int
    {
        if (! $summaries->tableReady()) {
            $this->error('order_workflow_summaries does not exist. Run php artisan migrate first.');
            return self::FAILURE;
        }

        $orderId = (int) ($this->option('order') ?: 0);
        if ($orderId > 0) {
            $order = FlowJob::query()->find($orderId);
            if (! $order) {
                $this->error("Order {$orderId} was not found.");
                return self::FAILURE;
            }

            $summaries->refresh($order);
            $this->info("Order {$orderId} workflow summary rebuilt.");
            return self::SUCCESS;
        }

        $chunk = max(10, min(500, (int) $this->option('chunk')));
        $count = 0;

        FlowJob::query()
            ->whereNull('deleted_at')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById($chunk, function ($orders) use ($summaries, &$count): void {
                foreach ($orders as $order) {
                    $summaries->refresh((int) $order->id);
                    $count++;
                }
                $this->output->write("\rBuilt {$count} summaries...");
            });

        $this->newLine();
        $this->info("Order workflow summary rebuild complete: {$count} Order(s).");

        return self::SUCCESS;
    }
}
