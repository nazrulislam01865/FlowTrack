<?php

namespace App\Console\Commands;

use App\Models\MasterRecord;
use App\Services\SupplierBridge\SyncSupplier;
use Illuminate\Console\Command;

/** Initial backfill and periodic reconciliation; does not create supplier passwords. */
final class SyncSupplierPortalCatalog extends Command
{
    protected $signature = 'flowtrack:sync-supplier-catalog {--supplier= : FlowTracker supplier ID for a single record}';
    protected $description = 'Synchronize FlowTracker suppliers and their assigned products to the separate portal';

    public function handle(SyncSupplier $sync): int
    {
        if (! config('supplier_bridge.enabled')) {
            $this->warn('Supplier bridge is disabled.');
            return self::FAILURE;
        }
        $query = MasterRecord::query()->ofType('supplier')->select(['id']);
        if ($this->option('supplier')) $query->whereKey((int) $this->option('supplier'));
        $sent = $skipped = $failed = 0;
        $query->chunkById(100, function ($records) use ($sync, &$sent, &$skipped, &$failed): void {
            foreach ($records as $record) {
                try {
                    if ($sync->sync((int) $record->id)) {
                        $sent++;
                    } else {
                        $skipped++;
                        $this->line('Supplier '.$record->id.' skipped: no assigned products.');
                    }
                } catch (\Throwable $exception) {
                    $failed++;
                    report($exception);
                    $reason = $exception instanceof \RuntimeException
                        ? $exception->getMessage() : 'Check the Laravel log for details.';
                    $this->warn('Supplier '.$record->id.' could not be synchronized: '.$reason);
                }
            }
        });
        $this->info("Suppliers synchronized: {$sent}; skipped: {$skipped}; failed: {$failed}.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
