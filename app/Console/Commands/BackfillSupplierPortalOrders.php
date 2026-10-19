<?php

namespace App\Console\Commands;

use App\Models\FlowJob;
use App\Services\SupplierBridge\CaptureCreatedOrder;
use Illuminate\Console\Command;

/** One-time (repeat-safe) discovery of historical orders omitted by the create-time bridge. */
final class BackfillSupplierPortalOrders extends Command
{
    protected $signature = 'flowtrack:backfill-supplier-orders
        {--order= : Optional single FlowTracker order ID}
        {--dry-run : Show eligible historical orders without creating deliveries}';

    protected $description = 'Queue older, non-draft orders for only their assigned product suppliers';

    public function handle(CaptureCreatedOrder $capture): int
    {
        if (! config('supplier_bridge.enabled')) {
            $this->error('Supplier bridge is disabled.');
            return self::FAILURE;
        }

        $query = FlowJob::query()->where('status', '!=', 'Draft')
            ->whereHas('items', fn ($items) => $items->where('is_removed', false)
                ->whereNotNull('catalog_product_id')->whereNotNull('supplier_id'));
        if ($this->option('order') !== null) {
            $id = filter_var($this->option('order'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $this->error('The --order option must be a valid positive order ID.');
                return self::FAILURE;
            }
            $query->whereKey($id);
        }

        if ($this->option('dry-run')) {
            $this->info('Candidate non-draft orders with explicit line supplier and catalog product: '.$query->count());
            $this->line('No records were created or sent. Run without --dry-run to queue delivery records.');
            return self::SUCCESS;
        }

        $total = ['created' => 0, 'existing' => 0, 'no_matching_supplier' => 0, 'unassigned_lines' => 0];
        $query->select(['id', 'job_number', 'status', 'created_at', 'supplier_delivery_date', 'estimated_delivery_date'])
            ->chunkById(50, function ($orders) use ($capture, &$total): void {
                $stats = $capture->captureMany($orders);
                foreach ($total as $key => $_) {
                    $total[$key] += $stats[$key];
                }
            });
        $this->info(sprintf(
            'Supplier order backfill: %d queued, %d already queued, %d orders without valid product-supplier lines, %d invalid lines skipped.',
            $total['created'], $total['existing'], $total['no_matching_supplier'], $total['unassigned_lines']
        ));
        $this->line('Run flowtrack:sync-supplier-orders to deliver queued records; the scheduler also retries pending deliveries.');
        return self::SUCCESS;
    }
}
