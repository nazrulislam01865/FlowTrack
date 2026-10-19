<?php

namespace App\Services\SupplierBridge;

use App\Jobs\DeliverSupplierBranding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Independent, coalesced brand outbox; never overwrites pending product/cost changes. */
final class MarkBrandDirty
{
    public function supplierIds(array $ids): void
    {
        if (! config('supplier_bridge.enabled')) return;
        $now = now();
        $rows = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id < 1) continue;
            $rows[] = [
                'supplier_id' => $id,
                'change_token' => (string) Str::uuid(),
                'attempts' => 0, 'next_attempt_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ($rows === []) return;
        DB::table('supplier_brand_outbox')->upsert(
            $rows, ['supplier_id'], ['change_token', 'attempts', 'next_attempt_at', 'updated_at'],
        );
        if (config('queue.default') === 'sync') return;
        $dispatch = static function () use ($rows): void {
            foreach ($rows as $row) {
                try {
                    DeliverSupplierBranding::dispatch((int) $row['supplier_id'], (string) $row['change_token'])
                        ->onQueue('supplier-branding');
                } catch (\Throwable $exception) {
                    Log::warning('Brand sync queue unavailable; outbox retained.', ['supplier_id' => $row['supplier_id']]);
                }
            }
        };
        if (DB::transactionLevel() > 0) DB::afterCommit($dispatch);
        else $dispatch();
    }
}
