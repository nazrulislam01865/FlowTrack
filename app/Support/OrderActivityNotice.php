<?php

namespace App\Support;

/**
 * Safe, non-sensitive summaries for already-recorded Order activities.
 * No database reads: the Activity event is the authoritative write signal.
 */
final class OrderActivityNotice
{
    private const LABELS = [
        'job.hold_started' => 'Order placed on hold',
        'job.hold_released' => 'Order hold released',
        'job.delivery_date_updated' => 'Hand date updated',
        'job.estimated_delivery_date_set' => 'Estimated delivery date updated',
        'job.supplier_delivery_date_set' => 'Supplier delivery date updated',
        'job.shipping_updated' => 'Shipping information updated',
        'job.shipment_updated' => 'Shipment information updated',
        'job.shipment_added' => 'Shipment added',
        'job.shipment_removed' => 'Shipment removed',
        'job.shipment_method_updated' => 'Shipment method updated',
        'job.shipment_plan_confirmed' => 'Shipment plan confirmed',
        'job.shipment_information_confirmed' => 'Shipment information confirmed',
        'job.courier_label_generated' => 'Shipment tracking details recorded',
        'job.courier_label_printed' => 'Shipping label printed',
        'job.package_shipped' => 'Shipment dispatched and shipping date recorded',
        'job.product_updated' => 'Order product details updated',
        'job.product_added' => 'Product added to order',
        'job.product_removed' => 'Product removed from order',
        'job.product_restored' => 'Product restored to order',
        'job.finance_updated' => 'Order financial information updated',
        'job.collection_updated' => 'Order collection updated',
        'job.payment_recorded' => 'Order payment recorded',
        'job.task_added' => 'Order task added',
        'job.sample_required' => 'Sample requested',
        'job.sample_not_required' => 'Sample requirement cleared',
        'job.artwork_revision_requested' => 'Artwork revision requested',
        'job.artwork_revision_applied' => 'Artwork revision applied',
        'job.artwork_client_erp_uploaded' => 'Artwork document recorded',
        'job.artwork_cancelled' => 'Artwork cancelled',
        'job.production_issue_reported' => 'Production issue reported',
        'job.production_issue_resolved' => 'Production issue resolved',
        'job.qc_issue_reported' => 'Quality-control issue reported',
        'job.qc_issue_resolved' => 'Quality-control issue resolved',
        'job.qc_passed' => 'Quality-control approval recorded',
        'job.owner_updated' => 'Order owner changed',
        'job.coordinator_updated' => 'Order coordinator changed',
        'job.priority_updated' => 'Order priority updated',
        'job.production_urgency_updated' => 'Production urgency updated',
        'job.shipment_urgency_updated' => 'Shipment urgency updated',
        'job.overview_updated' => 'Order overview updated',
        'job.title_updated' => 'Order title updated',
        'job.description_updated' => 'Order description updated',
        'job.inquiry_linked' => 'Inquiry linked to order',
        'job.inquiry_unlinked' => 'Inquiry unlinked from order',
        'job.document_renamed' => 'Order document renamed',
        'job.document_deleted' => 'Order document removed',
        'job.workflow_invoice_prepared' => 'Invoice prepared',
        'job.workflow_invoice_sent' => 'Invoice sent',
        'job.workflow_invoice_email_failed' => 'Invoice delivery failed',
        'job.workflow_payment_recorded' => 'Workflow payment recorded',
        'job.supplier_purchase_orders_generated' => 'Supplier purchase orders generated',
        'job.redo_started' => 'Order revision started',
        'job.redo_created' => 'Order revision created',
        'job.redo_discount_recorded' => 'Order revision discount recorded',
        'job.order_workflow_rebound' => 'Order workflow updated',
        'job.completed' => 'Order completed',
        'job.cancelled' => 'Order cancelled',
        'job.deactivated' => 'Order deactivated',
        'job.attention_cleared' => 'Order attention cleared',
        'job.comment' => 'New order comment',
    ];

    // These action handlers already send notifications. Never send a second
    // copy from the Activity observer or replace comment/mention deep links.
    private const DIRECTLY_NOTIFIED = [
        'job.delivery_date_updated', 'job.shipping_updated',
        'job.shipment_method_updated', 'job.shipment_urgency_updated',
        'job.production_urgency_updated', 'job.product_updated',
        'job.product_added', 'job.product_removed', 'job.product_restored',
        'job.owner_updated', 'job.coordinator_updated', 'job.priority_updated',
        'job.overview_updated', 'job.title_updated', 'job.description_updated',
        'job.artwork_cancelled',
        'job.deactivated', 'job.cancelled', 'job.completed', 'job.comment',
    ];

    private const SYSTEM_EVENTS = [
        'job.created', 'job.draft_saved', 'job.task_activity',
        'job.status_auto_changed', 'job.deleted',
    ];

    public static function label(string $event, array $meta = []): string
    {
        if ($event === 'job.shipment_updated' && isset($meta['changed_fields'])) {
            return self::shipmentChangeLabel((array) $meta['changed_fields']);
        }

        if ($event === 'job.product_updated') {
            return match ($meta['field'] ?? null) {
                'unit_price' => 'Product unit price updated',
                'quantity' => 'Product quantity updated',
                'supplier_id' => 'Product supplier updated',
                'product_name' => 'Product name updated',
                'category_name' => 'Product category updated',
                'notes' => 'Product notes updated',
                default => self::LABELS[$event],
            };
        }

        return self::LABELS[$event]
            ?? (str_starts_with($event, 'job.')
                ? \Illuminate\Support\Str::headline(substr($event, 4))
                : '');
    }

    /**
     * Describe only the product fields that actually changed. Quantity- or
     * supplier-driven price recalculations are not described as manual price
     * edits. Labels never contain product, customer or monetary values.
     */
    public static function productChangeLabel(array $dirtyFields): ?string
    {
        $dirty = array_fill_keys($dirtyFields, true);
        $names = [];
        // Product name/category are synchronized automatically from Master Data.
        // Only a different selected catalog product counts as a product edit.
        if (isset($dirty['catalog_product_id'])) $names[] = 'product';
        if (isset($dirty['supplier_id'])) $names[] = 'supplier';
        if (isset($dirty['quantity'])) $names[] = 'quantity';
        // Quantity-based catalogue pricing can also change the unit price.
        if (isset($dirty['unit_price']) && ! isset($dirty['quantity'])
            && ! isset($dirty['catalog_product_id'])) $names[] = 'unit price';
        if (isset($dirty['supplier_unit_price']) && ! isset($dirty['quantity'])
            && ! isset($dirty['supplier_id']) && ! isset($dirty['catalog_product_id'])
            && ! isset($dirty['unit_price'])) {
            $names[] = 'supplier price';
        }
        if (isset($dirty['notes'])) $names[] = 'notes';
        if ($names === []) return null;

        if (count($names) === 1) {
            return match ($names[0]) {
                'product' => 'Order product details updated',
                'supplier' => 'Product supplier updated',
                'quantity' => 'Product quantity updated',
                'unit price' => 'Product unit price updated',
                'supplier price' => 'Product supplier price updated',
                'notes' => 'Product notes updated',
            };
        }

        $last = array_pop($names);
        return 'Product updated: '.implode(', ', $names).' and '.$last;
    }

    /**
     * Visual-only priority classification for sensitive operational changes.
     * Only existing activity data is used; never read records or interpolate values.
     */
    public static function redActivity(string $event, array $meta = [], string $description = ''): bool
    {
        if (in_array($event, [
            'job.hold_started', 'job.hold_released',
            'job.supplier_delivery_date_set', 'job.delivery_date_updated',
            'job.shipment_method_updated', 'job.shipment_urgency_updated',
        ], true)) {
            return true;
        }

        if ($event === 'job.product_updated') {
            return self::mentionsQuantityOrPrice($description)
                || in_array($meta['field'] ?? null, ['quantity', 'unit_price', 'supplier_unit_price'], true);
        }

        if ($event === 'job.shipping_updated') {
            return self::containsField($meta['fields'] ?? [], [
                'shipping_address', 'shipping_postal_code', 'shipping_source_address_id',
            ]);
        }

        if ($event === 'job.shipment_updated') {
            return self::containsField($meta['changed_fields'] ?? [], [
                'address', 'city', 'state', 'postal_code', 'country', 'shipping_source_address_id',
                'shipment_method_id', 'shipment_urgency_id', 'quantity',
            ]);
        }

        return false;
    }

    /** Notifications have no activity-event column; classify safe existing titles only. */
    public static function redNotification(string $title): bool
    {
        $title = strtolower(trim($title));
        if ($title === '') return false;

        return self::mentionsQuantityOrPrice($title)
            || str_contains($title, 'address updated')
            || str_contains($title, 'address changed')
            || str_contains($title, 'placed on hold')
            || str_contains($title, 'hold released')
            || str_contains($title, 'hand date updated')
            || str_contains($title, 'supplier delivery date')
            || str_contains($title, 'shipment method updated')
            || str_contains($title, 'shipment urgency updated')
            || str_contains($title, 'shipment service updated')
            || str_contains($title, 'shipping service updated');
    }

    public static function shipmentChangeLabel(array $changedFields): string
    {
        if (self::containsField($changedFields, [
            'address', 'city', 'state', 'postal_code', 'country', 'shipping_source_address_id',
        ])) {
            return 'Shipment address updated';
        }
        if (self::containsField($changedFields, ['shipment_method_id', 'shipment_urgency_id'])) {
            return 'Shipment service updated';
        }
        if (self::containsField($changedFields, ['quantity'])) {
            return 'Shipment quantity updated';
        }

        return 'Shipment information updated';
    }

    private static function containsField(mixed $fields, array $expected): bool
    {
        return is_array($fields) && array_intersect($expected, $fields) !== [];
    }

    private static function mentionsQuantityOrPrice(string $text): bool
    {
        return preg_match('/\b(?:quantity|qty|price|pricing)\b/i', $text) === 1;
    }

    public static function highlight(string $event): bool
    {
        return ($event === 'job.comment' || str_starts_with($event, 'task.')
            || (str_starts_with($event, 'job.') && ! in_array($event, self::SYSTEM_EVENTS, true)));
    }

    public static function sendFromActivity(string $event): bool
    {
        return str_starts_with($event, 'job.')
            // DocumentService already notifies recipients after a file update.
            && ! str_starts_with($event, 'job.document_')
            && ! in_array($event, self::SYSTEM_EVENTS, true)
            && ! in_array($event, self::DIRECTLY_NOTIFIED, true);
    }
}
