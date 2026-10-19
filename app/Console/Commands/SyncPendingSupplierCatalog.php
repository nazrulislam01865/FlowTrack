<?php
namespace App\Console\Commands;

use App\Services\SupplierBridge\SyncSupplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SyncPendingSupplierCatalog extends Command
{
    protected $signature = 'flowtrack:sync-pending-supplier-catalog';
    protected $description = 'Send changed supplier product and approved-cost snapshots over the secure bridge';

    public function handle(SyncSupplier $sync): int
    {
        if (! config('supplier_bridge.enabled')) return self::SUCCESS;
        $entries = DB::table('supplier_catalog_outbox')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('updated_at')->limit(100)->get();
        foreach ($entries as $entry) {
            try {
                app(\App\Services\SupplierBridge\SendCatalogImmediately::class)->send((int) $entry->supplier_id);
                // Do not discard a concurrent product edit that updated this outbox token.
                DB::table('supplier_catalog_outbox')->where('supplier_id', $entry->supplier_id)
                    ->where('change_token', $entry->change_token)->delete();
            } catch (\Throwable $exception) {
                report($exception);
                $attempt = min(65535, (int) $entry->attempts + 1);
                DB::table('supplier_catalog_outbox')->where('supplier_id', $entry->supplier_id)
                    ->where('change_token', $entry->change_token)->update([
                        'attempts' => $attempt,
                        'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempt, 7)))),
                    ]);
            }
        }
        $this->info('Processed '.$entries->count().' supplier catalog changes.');
        return self::SUCCESS;
    }
}
