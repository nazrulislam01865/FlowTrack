<?php

namespace App\Services\SupplierBridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** One-way HTTP bridge. Does not expose FlowTracker API or credentials to suppliers. */
final class DeliverOrder
{
    public function send(int $id): bool
    {
        $baselineStart = \App\Support\Performance\OrderBaselineRecorder::enabled() ? microtime(true) : null;
        $orderId = null;
        $supplierId = null;
        $baselineSuccess = false;
        $baselineApplied = null;
        try {
            $baselineSuccess = $this->sendCanonical($id, $orderId, $supplierId, $baselineApplied);
            return $baselineSuccess;
        } finally {
            if ($baselineStart !== null) {
                \App\Support\Performance\OrderBaselineRecorder::record('supplier_delivery_attempt', [
                    'delivery_id' => $id,
                    'order_id' => $orderId,
                    'supplier_id' => $supplierId,
                    'success' => $baselineSuccess,
                    'applied' => $baselineApplied,
                    'duration_ms' => round((microtime(true) - $baselineStart) * 1000, 2),
                ]);
            }
        }
    }

    private function sendCanonical(int $id, ?int &$orderId, ?int &$supplierId, ?bool &$baselineApplied): bool
    {
        $claimed = DB::table('supplier_order_deliveries')->where('id', $id)
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale): void {
                        $stale->where('status', 'sending')->where('locked_at', '<', now()->subMinutes(5));
                    });
            })
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->update(['status' => 'sending', 'locked_at' => now(), 'next_attempt_at' => null, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $delivery = DB::table('supplier_order_deliveries')->find($id);
        $orderId = $delivery ? (int) $delivery->flow_job_id : null;
        $supplierId = $delivery ? (int) $delivery->supplier_id : null;
        try {
            $url = config('supplier_bridge.url');
            $secret = config('supplier_bridge.secret');
            if (! config('supplier_bridge.enabled') || strlen($secret) < 32 || ! is_string($url) || ! $this->allowedUrl($url)) {
                throw new RuntimeException('Supplier bridge is not securely configured.');
            }

            // Resolve Task Pack supplier defaults just before delivery. The
            // initial order notification may precede generation of every task;
            // keep projection construction itself read-only.
            $order = \App\Models\FlowJob::query()->find((int) $delivery->flow_job_id);
            if ($order && strcasecmp((string) $order->status, 'Draft') !== 0) {
                app(SupplierDefaultTaskAssigner::class)->syncForOrder($order);
                // A completed predecessor on an imported/older order can leave
                // its next Task Pack row at Not Started. Bring that row to
                // canonical Ready before sending the action snapshot. This
                // uses FlowTracker's existing sequence rules (never bypasses
                // a required predecessor or manually approves a task).
                app(\App\Services\OrderTaskSequenceService::class)->synchronizeCurrentPhase($order);
            }

            // Rebuild at delivery time so retries also carry current stage, files and assigned lines.
            $snapshotAt = now();
            $snapshot = app(SupplierOrderProjection::class)->build(
                (int) $delivery->flow_job_id, (int) $delivery->supplier_id, (string) $delivery->event_id,
            );
            $body = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', $timestamp."\n".$delivery->event_id."\n".$body, $secret);
            $response = Http::connectTimeout(2)->timeout(5)->withHeaders([
                'X-FlowTrack-Timestamp' => $timestamp,
                'X-FlowTrack-Event' => $delivery->event_id,
                'X-FlowTrack-Signature' => $signature,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->withBody($body, 'application/json')->post($url.'/api/v1/integrations/flowtrack/orders');

            if ($response->status() === 409) {
                // Only provision when the supplier mapping is actually missing.
                if (! app(SyncSupplier::class)->sync((int) $delivery->supplier_id)) {
                    throw new RuntimeException('Supplier provisioning could not be completed.');
                }
                $timestamp = (string) time();
                $signature = hash_hmac('sha256', $timestamp."\n".$delivery->event_id."\n".$body, $secret);
                $response = Http::connectTimeout(2)->timeout(5)->withHeaders([
                    'X-FlowTrack-Timestamp' => $timestamp,
                    'X-FlowTrack-Event' => $delivery->event_id,
                    'X-FlowTrack-Signature' => $signature,
                    'Content-Type' => 'application/json', 'Accept' => 'application/json',
                ])->withBody($body, 'application/json')->post($url.'/api/v1/integrations/flowtrack/orders');
            }
            if (! $response->successful()) {
                throw new RuntimeException('Supplier service returned HTTP '.$response->status());
            }
            // A 200 response must mean the newer projection was actually
            // persisted. Never mark an ignored stale revision as delivered.
            if ($response->json('applied') === false) {
                throw new RuntimeException('Supplier service rejected an older workflow revision.');
            }

            // A workflow/task change during the HTTP request marks this
            // delivery pending again. Do not erase that newer work when the
            // in-flight snapshot succeeds.
            // A successful acknowledgement must also record the projection
            // format that was actually accepted. Without this marker, a code
            // upgrade can leave an old but "delivered" snapshot permanently
            // missing new artwork/action fields until another database write.
            // Keep the existing outbox metadata; no schema change is needed.
            $metadata = json_decode((string) $delivery->payload, true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['projection_version'] = SupplierOrderProjection::FORMAT_VERSION;
            $applied = DB::table('supplier_order_deliveries')->where('id', $id)
                ->where('status', 'sending')->whereNull('next_attempt_at')->update([
                'status' => 'delivered', 'delivered_at' => $snapshotAt, 'locked_at' => null,
                'payload' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'last_error' => null, 'updated_at' => now(),
            ]);
            $baselineApplied = $applied === 1;
            if ($applied === 1) {
                try {
                    app(\App\Services\ReverbChannelService::class)->triggerSupplierOrder(
                        (int) $delivery->supplier_id, (int) $delivery->flow_job_id,
                    );
                    app(\App\Services\ReverbChannelService::class)->triggerSupplierDashboard(
                        (int) $delivery->supplier_id,
                    );
                } catch (\Throwable $exception) {
                    // Realtime is best-effort: do not retry an already accepted
                    // signed snapshot when the WebSocket server is unavailable.
                    Log::warning('Supplier realtime notification unavailable.', ['delivery_id' => $id]);
                }
            }
            if ($applied !== 1) {
                // The task changed during delivery. Release this HTTP claim and
                // deliver the newer snapshot immediately, not 15 minutes later.
                $dirty = DB::table('supplier_order_deliveries')->where('id', $id)
                    ->where('status', 'sending')->whereNotNull('next_attempt_at')
                    ->update(['status' => 'pending', 'next_attempt_at' => null,
                        'locked_at' => null, 'attempts' => 0, 'updated_at' => now()]);
                if ($dirty && config('queue.default') !== 'sync') {
                    try {
                        \App\Jobs\DeliverChangedSupplierOrder::dispatch((int) $delivery->flow_job_id)
                            ->onQueue('supplier-critical');
                    } catch (\Throwable $error) {
                        Log::warning('Newer supplier order delivery deferred to scheduler.', ['delivery_id' => $id]);
                    }
                }
            }
            return true;
        } catch (\Throwable $exception) {
            $attempts = (int) $delivery->attempts + 1;
            DB::table('supplier_order_deliveries')->where('id', $id)->where('status', 'sending')->update([
                'status' => 'pending', 'attempts' => $attempts, 'locked_at' => null,
                'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempts, 7)))),
                'last_error' => substr($exception instanceof RuntimeException ? $exception->getMessage() : 'Supplier transport unavailable', 0, 160),
                'updated_at' => now(),
            ]);
            Log::warning('Supplier order API delivery deferred.', ['delivery_id' => $id, 'attempt' => $attempts]);
            return false;
        }
    }

    private function allowedUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || str_contains($url, '@') || str_contains($url, '?') || str_contains($url, '#')) {
            return false;
        }
        // Never allow an arbitrary HTTP endpoint outside local development.
        return $scheme === 'https' || (app()->environment(['local', 'testing']) && $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true));
    }
}
