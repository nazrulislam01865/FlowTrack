<?php

namespace App\Jobs;

use App\Services\SupplierBridge\PortalClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Fast signed logo/name update; independently retryable without a full product catalogue. */
final class DeliverSupplierBranding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $supplierId, public readonly string $outboxToken) {}

    public function handle(PortalClient $portal): void
    {
        if (! config('supplier_bridge.enabled')) return;
        $entry = DB::table('supplier_brand_outbox')
            ->where('supplier_id', $this->supplierId)
            ->where('change_token', $this->outboxToken)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->first();
        if (! $entry) return; // Superseded by a newer logo change, or already delivered.
        try {
            $brand = app(\App\Services\BrandingService::class)->current();
            $response = $portal->post('branding', [
                'supplier_id' => $this->supplierId,
                'revision' => (int) now()->format('Uu'),
                'brand_name' => (string) ($brand['name'] ?: 'FlowTrack'),
                'brand_logo_path' => $brand['logo_url'] ?: null,
                'brand_favicon_path' => $brand['favicon_url'] ?: null,
                'footer' => $brand['supplier_portal_footer'],
            ]);
            if (! $response->successful()) throw new \RuntimeException('Branding bridge HTTP '.$response->status());
            DB::table('supplier_brand_outbox')->where('supplier_id', $this->supplierId)
                ->where('change_token', $this->outboxToken)->delete();
            try {
                $realtime = app(\App\Services\ReverbChannelService::class);
                $realtime->triggerSupplierCatalog($this->supplierId);
                $realtime->triggerSupplierDashboard($this->supplierId);
            } catch (\Throwable $exception) {
                Log::warning('Brand updated but realtime unavailable.', ['supplier_id' => $this->supplierId]);
            }
        } catch (\Throwable $exception) {
            $attempts = min(65535, (int) $entry->attempts + 1);
            DB::table('supplier_brand_outbox')->where('supplier_id', $this->supplierId)
                ->where('change_token', $this->outboxToken)->update([
                    'attempts' => $attempts,
                    'next_attempt_at' => now()->addSeconds(min(3600, 5 * (2 ** min($attempts, 8)))),
                    'updated_at' => now(),
                ]);
            Log::warning('Fast supplier branding sync deferred to retry.', [
                'supplier_id' => $this->supplierId, 'attempt' => $attempts,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
