<?php

namespace App\Services\Orders;

use App\Models\FlowJob;
use App\Models\FlowJobItem;

/**
 * Keeps the legacy order-level product summary aligned with active order lines.
 *
 * The item rows are authoritative. The summary columns remain for compatibility
 * with older screens, imports and reports, so every workflow that removes or
 * restores an item should synchronize them through this service.
 */
final class OrderItemSummaryService
{
    public function sync(FlowJob $order): void
    {
        $items = $order->items()->active()->orderBy('sort_order')->get();
        $completeItems = $items
            ->filter(fn (FlowJobItem $item): bool => filled($item->product_name))
            ->values();
        $first = $completeItems->first();

        $order->update([
            'product' => $first?->product_name,
            'category' => $first?->category_name,
            'quantity' => (int) $completeItems->sum('quantity'),
        ]);
    }
}
