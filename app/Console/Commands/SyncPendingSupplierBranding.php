<?php

namespace App\Console\Commands;

use App\Jobs\DeliverSupplierBranding;
use App\Services\SupplierBridge\PortalClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** One-minute outage repair; fast queue sends changes immediately when healthy. */
final class SyncPendingSupplierBranding extends Command
{
    protected $signature = 'flowtrack:sync-pending-supplier-branding';
    protected $description = 'Retry deferred brand/logo updates without rebuilding supplier catalogues';

    public function handle(PortalClient $portal): int
    {
        if (! config('supplier_bridge.enabled')) return self::SUCCESS;
        $entries = DB::table('supplier_brand_outbox')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('updated_at')->limit(100)->get(['supplier_id', 'change_token']);
        foreach ($entries as $entry) {
            (new DeliverSupplierBranding((int) $entry->supplier_id, (string) $entry->change_token))
                ->handle($portal);
        }
        $this->info('Processed '.$entries->count().' supplier branding updates.');
        return self::SUCCESS;
    }
}
