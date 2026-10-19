<?php

namespace App\Services\SupplierBridge;

use App\Jobs\DeliverSupplierOrder;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Create one durable, supplier-scoped projection per eligible order. */
final class CaptureCreatedOrder
{
    public function capture(FlowJob $order): void
    {
        $this->captureMany([$order], true);
    }

    /**
     * Backfill works in bounded batches; the live create path uses the same rules.
     * The supplier on each order line must match that line's actual catalog assignment.
     *
     * @param iterable<FlowJob> $orders
     * @return array{created:int, existing:int, no_matching_supplier:int, unassigned_lines:int}
     */
    public function captureMany(iterable $orders, bool $dispatch = false): array
    {
        $stats = ['created' => 0, 'existing' => 0, 'no_matching_supplier' => 0, 'unassigned_lines' => 0];
        if (! config('supplier_bridge.enabled')) {
            return $stats;
        }

        $eligible = collect($orders)->filter(fn (FlowJob $order) =>
            $order->exists || $order->getKey() !== null
        )->filter(fn (FlowJob $order) =>
            strcasecmp((string) $order->status, 'Draft') !== 0 && $order->deleted_at === null
        )->keyBy(fn (FlowJob $order) => (int) $order->getKey());
        if ($eligible->isEmpty()) {
            return $stats;
        }
        $orderIds = $eligible->keys()->all();

        // One set-based lookup per batch; no per-product/supplier queries.
        $lines = DB::table('flow_job_items as item')
            ->join('master_records as product', 'product.id', '=', 'item.catalog_product_id')
            ->join('master_records as supplier', 'supplier.id', '=', 'item.supplier_id')
            ->leftJoin('product_supplier_links as link', function ($join): void {
                $join->on('link.product_id', '=', 'item.catalog_product_id')
                    ->on('link.supplier_id', '=', 'item.supplier_id')
                    ->on('link.workspace_id', '=', 'product.workspace_id');
            })
            ->whereIn('item.flow_job_id', $orderIds)
            ->where('item.is_removed', false)
            ->where('item.quantity', '>', 0)
            ->where('product.type', 'product')
            ->where('supplier.type', 'supplier')
            ->where('supplier.status', 'active')
            ->whereNull('product.deleted_at')
            ->whereNull('supplier.deleted_at')
            ->whereColumn('supplier.workspace_id', 'product.workspace_id')
            ->orderBy('item.id')
            ->get([
                'item.id as item_id', 'item.flow_job_id', 'item.catalog_product_id', 'item.supplier_id',
                'product.name as product_name', 'item.quantity', 'product.metadata as product_metadata',
                'link.id as linked_id',
            ]);

        $byOrder = [];
        $seenLines = [];
        foreach ($lines as $line) {
            // A left join may produce duplicates if legacy pivot data has duplicates.
            if (isset($seenLines[$line->item_id])) {
                continue;
            }
            $seenLines[$line->item_id] = true;
            $supplierId = (int) $line->supplier_id;
            if (! $line->linked_id) {
                $product = new MasterRecord();
                $product->setRawAttributes(['type' => 'product', 'metadata' => $line->product_metadata]);
                if (! $product->hasProductSupplier($supplierId)) {
                    $stats['unassigned_lines']++;
                    continue;
                }
            }
            $byOrder[(int) $line->flow_job_id][$supplierId][] = [
                'product_name' => substr((string) ($line->product_name ?: 'Product'), 0, 255),
                'product_code' => 'PRD-'.str_pad((string) $line->catalog_product_id, 6, '0', STR_PAD_LEFT),
                'quantity' => (int) $line->quantity,
            ];
        }

        $existing = DB::table('supplier_order_deliveries')->whereIn('flow_job_id', $orderIds)
            ->get(['flow_job_id', 'supplier_id'])
            ->mapWithKeys(fn ($record) => [$record->flow_job_id.':'.$record->supplier_id => true]);

        foreach ($eligible as $orderId => $order) {
            $group = $byOrder[(int) $orderId] ?? [];
            if ($group === []) {
                $stats['no_matching_supplier']++;
            }
            foreach ($group as $supplierId => $items) {
                $key = $orderId.':'.$supplierId;
                if ($existing->has($key)) {
                    $stats['existing']++;
                    continue;
                }
                // Receiver accepts at most 100 assigned lines; do not truncate silently.
                if (count($items) > 100) {
                    Log::warning('Supplier order projection exceeds maximum line count.', ['order_id' => $orderId, 'supplier_id' => $supplierId]);
                    continue;
                }
                $eventId = (string) Str::uuid();
                $number = $order->displayOrderNumber();
                $deliveryDate = $order->supplier_delivery_date?->toDateString()
                    ?? $order->estimated_delivery_date?->toDateString();
                $payload = [
                    'event_id' => $eventId,
                    'supplier_id' => (int) $supplierId,
                    'order' => [
                        'reference' => $number,
                        'title' => 'Order '.$number,
                        'created_at' => $order->created_at?->toIso8601String(),
                        'delivery_date' => $deliveryDate,
                        'status' => substr((string) $order->status, 0, 80),
                        'items' => $items,
                    ],
                ];
                $inserted = DB::table('supplier_order_deliveries')->insertOrIgnore([
                    'flow_job_id' => (int) $orderId,
                    'supplier_id' => (int) $supplierId,
                    'event_id' => $eventId,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted !== 1) {
                    $stats['existing']++;
                    continue;
                }
                $stats['created']++;
                if ($dispatch) {
                    $id = DB::table('supplier_order_deliveries')
                        ->where('flow_job_id', $orderId)->where('supplier_id', $supplierId)->value('id');
                    DB::afterCommit(function () use ($id): void {
                        try {
                            if ($id && config('queue.default') !== 'sync') {
                                DeliverSupplierOrder::dispatch($id)->onQueue('supplier-critical');
                            }
                        } catch (\Throwable $exception) {
                            Log::warning('Supplier order delivery queued for scheduled retry.', ['delivery_id' => $id]);
                        }
                    });
                }
            }
        }
        return $stats;
    }
}
