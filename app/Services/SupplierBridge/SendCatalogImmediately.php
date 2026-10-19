<?php

namespace App\Services\SupplierBridge;

use Illuminate\Support\Facades\DB;

/** Push one supplier after commit; preserve any newer outbox change on races/failure. */
final class SendCatalogImmediately
{
    public function __construct(private readonly SyncSupplier $sync) {}

    public function send(int $supplierId): void
    {
        if (! config('supplier_bridge.enabled')) {
            throw new \RuntimeException('Supplier bridge is disabled.');
        }
        $token = DB::table('supplier_catalog_outbox')->where('supplier_id', $supplierId)
            ->value('change_token');
        $this->sync->sync($supplierId);
        try {
            $realtime = app(\App\Services\ReverbChannelService::class);
            $realtime->triggerSupplierCatalog($supplierId);
            $realtime->triggerSupplierDashboard($supplierId);
        } catch (\Throwable $exception) {
            // Best-effort WebSocket delivery cannot invalidate an accepted catalogue.
            report($exception);
        }
        if ($token !== null) {
            DB::table('supplier_catalog_outbox')->where('supplier_id', $supplierId)
                ->where('change_token', $token)->delete();
        }
    }
}
