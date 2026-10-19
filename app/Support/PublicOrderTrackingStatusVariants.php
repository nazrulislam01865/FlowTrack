<?php

namespace App\Support;

/**
 * Customer-safe status variant catalogue used by the approved tracking
 * prototypes. The catalogue is static UI metadata only and performs no reads.
 *
 * Copy mirrors Order-Tracking-Status-Map.xlsx. It is intentionally kept
 * separate from workflow querying so design-preview rendering cannot introduce
 * database work or expose internal task metadata.
 */
final class PublicOrderTrackingStatusVariants
{
    /** @return array<string,mixed> */
    public static function forStage(int $stage): array
    {
        return match (max(1, min(7, $stage))) {
            2 => self::artwork(),
            3 => self::production(),
            4 => self::qc(),
            5 => self::shipment(),
            6 => self::billing(),
            7 => self::payment(),
            default => self::newOrder(),
        };
    }

    /** @return array<string,mixed> */
    private static function newOrder(): array
    {
        return self::section(
            1,
            'New Order',
            'stacked',
            [
                self::item('order-received', 'Order received', 'We have received your order.', 'document', 'success'),
                self::item('order-review', 'Order being reviewed', 'We are reviewing your order details.', 'eye', 'info'),
                self::item('preparing-artwork', 'Preparing artwork', 'Your order is being sent to our artwork team.', 'palette', 'purple'),
            ],
        );
    }

    /** @return array<string,mixed> */
    private static function artwork(): array
    {
        return self::section(
            2,
            'Artwork',
            'five',
            [
                self::item('artwork-progress', 'Artwork in progress', 'We are preparing your artwork.', 'document', 'purple-blue'),
                self::item('artwork-review', 'Artwork under review', 'Our team is checking your artwork. Your artwork is then prepared for approval.', 'document', 'purple-soft'),
                self::item('artwork-approval', 'Awaiting artwork approval', 'Please review and approve your artwork.', 'document', 'approval'),
                self::item('artwork-revision', 'Artwork revision in progress', 'We are updating your artwork based on your feedback.', 'document', 'violet'),
                self::item('artwork-approved', 'Artwork approved', 'Your artwork is approved. We are preparing for production.', 'document', 'success'),
            ],
        );
    }

    /** @return array<string,mixed> */
    private static function production(): array
    {
        return self::section(
            3,
            'Production',
            'five',
            [
                self::item('production-scheduling', 'Scheduling production', 'We are confirming the production schedule.', 'calendar', 'info'),
                self::item('production-ready', 'Ready for production', 'Your order is scheduled for production.', 'clock', 'purple'),
                self::item('production', 'In production', 'Your order is being made.', 'gear', 'orange'),
                self::item('production-hold', 'Production on hold', 'Production is temporarily on hold. We will share an update.', 'stop', 'pink'),
                self::item('production-completed', 'Production completed', 'Production is complete. Your order is ready for quality checking.', 'check', 'success'),
            ],
            'These are alternate status components for the Production stage. Only one is shown on the order tracking page at a time.',
            true,
        );
    }

    /** @return array<string,mixed> */
    private static function qc(): array
    {
        return self::section(
            4,
            'QC',
            'three',
            [
                self::item('quality-check', 'Quality check in progress', 'We are checking the quality of your order.', 'search', 'teal'),
                self::item('quality-issue', 'Quality issue being resolved', 'We are resolving a quality issue before shipment.', 'warning-triangle', 'orange'),
                self::item('quality-passed', 'Quality check passed', 'Your order has passed quality checking.', 'check', 'success'),
            ],
        );
    }

    /** @return array<string,mixed> */
    private static function shipment(): array
    {
        return self::section(
            5,
            'Shipment',
            'seven',
            [
                self::item('shipment-preparing', 'Preparing shipment', 'We are confirming the shipment details.', 'truck', 'info'),
                self::item('shipment-ready', 'Ready for shipment', 'Your order is ready to be handed to the courier.', 'truck', 'purple'),
                self::item('dispatched', 'Dispatched', 'Your order has been handed to the courier.', 'truck', 'success'),
                self::item('in-transit', 'In transit', 'Your shipment is on its way.', 'truck', 'orange'),
                self::item('delivered', 'Delivered', 'Your shipments have been delivered.', 'truck', 'info'),
                self::item('partial-dispatched', 'Partially dispatched', 'Some shipments have been handed to the courier.', 'truck', 'pink'),
                self::item('partial-delivered', 'Partially delivered', 'Some shipments have been delivered.', 'truck', 'success'),
            ],
            'Partial statuses apply only to multiple shipments. Transit and delivery require courier confirmation.',
            true,
        );
    }

    /** @return array<string,mixed> */
    private static function billing(): array
    {
        return self::section(
            6,
            'Billing',
            'three',
            [
                self::item('invoice-preparing', 'Preparing invoice', 'We are preparing your invoice.', 'billing', 'pink'),
                self::item('invoice-ready', 'Invoice ready', 'Your invoice is ready and will be sent to you.', 'billing', 'pink'),
                self::item('invoice-sent', 'Invoice sent', 'Your invoice has been sent to your registered email.', 'billing', 'pink'),
            ],
            null,
            false,
            true,
        );
    }

    /** @return array<string,mixed> */
    private static function payment(): array
    {
        return self::section(
            7,
            'Payment',
            'five',
            [
                self::item('awaiting-payment', 'Awaiting payment', 'Payment is pending for your order.', 'payment', 'info'),
                self::item('payment-review', 'Payment under review', 'We are checking your payment.', 'clock', 'orange'),
                self::item('partial-payment', 'Partially paid', 'A payment has been received. A balance remains.', 'billing', 'pink'),
                self::item('paid', 'Paid', 'Your payment has been confirmed.', 'check', 'success'),
                self::item('order-completed', 'Order completed', 'Your order is delivered and payment is confirmed.', 'check', 'success'),
            ],
            null,
            false,
            false,
            'Payment states are proposed. Order completed requires all applicable tasks complete, all shipments delivered and required payment verified.',
            '07 · Payment',
        );
    }

    /** @return array<string,mixed> */
    private static function section(
        int $stage,
        string $name,
        string $layout,
        array $items,
        ?string $note = null,
        bool $inlineHeading = false,
        bool $softPanel = false,
        ?string $footerNote = null,
        ?string $footerLabel = null,
    ): array {
        return [
            'stage' => $stage,
            'stage_label' => sprintf('%02d · %s', $stage, $name),
            'title' => 'Status variants for this stage',
            'layout' => $layout,
            'items' => $items,
            'note' => $note,
            'inline_heading' => $inlineHeading,
            'soft_panel' => $softPanel,
            'footer_note' => $footerNote,
            'footer_label' => $footerLabel,
        ];
    }

    /** @return array{key:string,title:string,message:string,icon:string,tone:string} */
    private static function item(string $key, string $title, string $message, string $icon, string $tone): array
    {
        return compact('key', 'title', 'message', 'icon', 'tone');
    }
}
