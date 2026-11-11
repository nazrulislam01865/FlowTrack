<?php

namespace Tests\Unit;

use App\Support\OrderActivityNotice;
use PHPUnit\Framework\TestCase;

final class OrderActivityNoticeTest extends TestCase
{
    public function test_each_requested_action_has_a_specific_private_label(): void
    {
        $actions = [
            'job.hold_started' => 'Order placed on hold',
            'job.hold_released' => 'Order hold released',
            'job.delivery_date_updated' => 'Hand date updated',
            'job.estimated_delivery_date_set' => 'Estimated delivery date updated',
            'job.shipment_updated' => 'Shipment information updated',
            'job.package_shipped' => 'Shipment dispatched and shipping date recorded',
            'job.product_updated' => 'Order product details updated',
            'job.comment' => 'New order comment',
        ];
        foreach ($actions as $event => $label) {
            self::assertSame($label, OrderActivityNotice::label($event));
            self::assertTrue(OrderActivityNotice::highlight($event));
        }
        self::assertSame('Product unit price updated', OrderActivityNotice::label('job.product_updated', ['field' => 'unit_price']));
        self::assertSame('Product quantity updated', OrderActivityNotice::label('job.product_updated', ['field' => 'quantity']));
        self::assertSame('Product supplier updated', OrderActivityNotice::label('job.product_updated', ['field' => 'supplier_id']));
    }

    public function test_product_edit_reports_only_changed_fields_without_values(): void
    {
        self::assertSame('Product quantity updated', OrderActivityNotice::productChangeLabel(['quantity', 'unit_price', 'supplier_unit_price', 'updated_by']));
        self::assertSame('Product unit price updated', OrderActivityNotice::productChangeLabel(['unit_price', 'updated_by']));
        self::assertSame('Product supplier updated', OrderActivityNotice::productChangeLabel(['supplier_id', 'supplier_unit_price']));
        self::assertSame('Product updated: supplier and quantity', OrderActivityNotice::productChangeLabel(['supplier_id', 'quantity']));
        self::assertSame('Product notes updated', OrderActivityNotice::productChangeLabel(['notes']));
        self::assertSame('Product quantity updated', OrderActivityNotice::productChangeLabel(['product_name', 'category_name', 'quantity']));
        self::assertSame('Order product details updated', OrderActivityNotice::productChangeLabel(['catalog_product_id', 'product_name', 'category_name', 'unit_price']));
        self::assertNull(OrderActivityNotice::productChangeLabel(['updated_by']));
    }

    public function test_existing_notifications_and_system_events_are_not_duplicated(): void
    {
        foreach (['job.comment', 'job.product_updated', 'job.product_added',
            'job.delivery_date_updated', 'job.owner_updated', 'job.completed',
            'job.artwork_cancelled', 'job.document_uploaded', 'job.document_linked',
            'job.created', 'job.task_activity', 'job.status_auto_changed'] as $event) {
            self::assertFalse(OrderActivityNotice::sendFromActivity($event), $event);
        }
        foreach (['job.hold_started', 'job.hold_released',
            'job.estimated_delivery_date_set', 'job.shipment_added',
            'job.shipment_updated', 'job.package_shipped',
            'job.finance_updated', 'job.sample_required'] as $event) {
            self::assertTrue(OrderActivityNotice::sendFromActivity($event), $event);
        }
    }

    public function test_unknown_metadata_can_never_be_displayed_in_a_notice_label(): void
    {
        $private = 'PRIVATE-TRACKING-123';
        $label = OrderActivityNotice::label('job.shipment_updated', ['tracking_number' => $private, 'carrier' => $private]);
        self::assertSame('Shipment information updated', $label);
        self::assertStringNotContainsString($private, $label);
        self::assertSame('', OrderActivityNotice::label('task.not_supported'));
    }
    public function test_red_activity_highlight_is_limited_to_requested_changes(): void
    {
        foreach (['job.hold_started', 'job.hold_released', 'job.supplier_delivery_date_set',
            'job.delivery_date_updated', 'job.shipment_method_updated', 'job.shipment_urgency_updated'] as $event) {
            self::assertTrue(OrderActivityNotice::redActivity($event), $event);
        }
        self::assertTrue(OrderActivityNotice::redActivity('job.product_updated', [], 'Product quantity updated'));
        self::assertTrue(OrderActivityNotice::redActivity('job.product_updated', ['field' => 'unit_price']));
        self::assertTrue(OrderActivityNotice::redActivity('job.product_updated', [], 'Product updated: supplier and quantity'));
        self::assertTrue(OrderActivityNotice::redActivity('job.shipping_updated', ['fields' => ['shipping_address']]));
        self::assertFalse(OrderActivityNotice::redActivity('job.shipping_updated', ['fields' => ['shipping_phone']]));
        self::assertTrue(OrderActivityNotice::redActivity('job.shipment_updated', ['changed_fields' => ['address']]));
        self::assertTrue(OrderActivityNotice::redActivity('job.shipment_updated', ['changed_fields' => ['shipment_urgency_id']]));
        self::assertFalse(OrderActivityNotice::redActivity('job.shipment_updated', ['shipment_id' => 1]));
        self::assertFalse(OrderActivityNotice::redActivity('job.product_updated', [], 'Product supplier updated'));
        self::assertFalse(OrderActivityNotice::redActivity('job.comment', [], 'Hello'));
        self::assertFalse(OrderActivityNotice::redActivity('task.status_updated'));
    }

    public function test_red_notifications_are_selected_using_only_non_sensitive_titles(): void
    {
        foreach (['Product quantity updated', 'Product unit price updated', 'Product supplier price updated',
            'Order placed on hold', 'Order hold released', 'Supplier delivery date updated',
            'Order hand date updated', 'Order shipping address updated', 'Shipment address updated',
            'Order shipment method updated', 'Order shipment urgency updated', 'Shipment service updated'] as $title) {
            self::assertTrue(OrderActivityNotice::redNotification($title), $title);
        }
        foreach (['New order comment', 'Product supplier updated', 'Order shipping details updated',
            'Product notes updated', 'Task completed', 'Order product details updated'] as $title) {
            self::assertFalse(OrderActivityNotice::redNotification($title), $title);
        }
        self::assertSame('Shipment address updated', OrderActivityNotice::shipmentChangeLabel(['address']));
        self::assertSame('Shipment service updated', OrderActivityNotice::shipmentChangeLabel(['shipment_method_id']));
        self::assertSame('Shipment quantity updated', OrderActivityNotice::shipmentChangeLabel(['quantity']));
        self::assertSame('Shipment information updated', OrderActivityNotice::shipmentChangeLabel(['package_reference']));
    }

}
