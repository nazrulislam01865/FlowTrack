<?php

namespace App\DTOs\OrderTracking;

/**
 * Read-only tracking snapshot detached from FlowTrack's Eloquent models.
 *
 * This is intentionally a public-safe business read model: no internal database
 * IDs, email addresses, people profiles, supplier/client records, addresses,
 * phones, notes, documents, costs, invoice totals, or payment amounts cross the
 * tracking boundary.
 */
final readonly class PublicOrderTrackingSnapshot
{
    /**
     * @param array{sequence:int,name:string,short_name:string} $phase
     * @param list<array{automation_key:string,title:string,status:string,progress:int,completed_at:?string,updated_at:?string}> $tasks
     * @param list<array{event:string,created_at:?string,shipment_sequence:?int}> $activities
     * @param list<array{sequence:int,is_primary:bool,courier:?string,tracking_number:?string,dispatched_at:?string,updated_at:?string}> $shipments
     * @param list<array{sequence:int,status:string,payment_state:string,sent_at:?string,emailed_at:?string,updated_at:?string}> $invoices
     */
    public function __construct(
        public string $orderNumber,
        public string $referenceNumber,
        public string $orderStatus,
        public bool $allowMultipleShipments,
        public ?string $completedAt,
        public ?string $updatedAt,
        public array $phase,
        public array $tasks,
        public array $activities,
        public array $shipments,
        public array $invoices,
    ) {
    }
}
