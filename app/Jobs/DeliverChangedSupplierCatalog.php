<?php
namespace App\Jobs;

use App\Services\SupplierBridge\SendCatalogImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Event-driven push; the outbox plus minute reconciliation survives worker/HTTP outages. */
final class DeliverChangedSupplierCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public function __construct(public readonly int $supplierId) {}
    public function handle(SendCatalogImmediately $sender): void
    {
        $entry = DB::table('supplier_catalog_outbox')->where('supplier_id', $this->supplierId)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->first();
        if (! $entry) return;
        try {
            $sender->send($this->supplierId);
        } catch (\Throwable $exception) {
            report($exception);
            $attempt = min(65535, (int) $entry->attempts + 1);
            DB::table('supplier_catalog_outbox')->where('supplier_id', $this->supplierId)
                ->where('change_token', $entry->change_token)->update([
                    'attempts' => $attempt,
                    'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempt, 7)))),
                ]);
        }
    }
}
