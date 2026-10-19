<?php

namespace App\DTOs\Orders;

final readonly class OrderCreateData
{
    public function __construct(public array $attributes)
    {
    }

    public static function fromLivewire(array $data, bool $draft): self
    {
        $first = collect($data['jobItems'])->first();
        $rawInquiryIds = (array) ($data['createInquiryIds'] ?? []);
        if ($rawInquiryIds === [] && filled($data['createInquiryId'] ?? null)) {
            // Graceful compatibility for a Create Order page left open across a
            // deployment from the previous single-Inquiry implementation.
            $rawInquiryIds = [(int) $data['createInquiryId']];
        }
        $inquiryIds = collect($rawInquiryIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        // The Create Order prototype now owns an explicit list of shipment
        // rows. Keep the previous single-address fields as a compatibility
        // fallback for old browser snapshots and direct DTO callers.
        $initialShipments = self::initialShipments($data);
        $primaryShipment = $initialShipments[0] ?? null;
        $createShipmentMode = trim((string) ($data['createShipmentMode'] ?? ''));

        return new self([
            'order_number' => $data['referenceNumber'],
            'is_repeat_order' => (bool) $data['isRepeatedOrder'],
            'repeat_order_number' => $data['isRepeatedOrder'] ? trim((string) $data['repeatedOrderNumber']) : null,
            'title' => self::generateTitle($data['referenceNumber'], $data['jobItems']),
            'product' => $first['product'] ?? null,
            'category' => $first['category'] ?? null,
            'quantity' => collect($data['jobItems'])->sum('quantity'),
            'items' => $data['jobItems'],
            'priority' => 'Medium',
            'production_urgency_ids' => [],
            'shipment_method_ids' => self::singlePositiveId(
                $primaryShipment ? [$primaryShipment['shipment_method_id'] ?? null] : ($data['shipmentMethodIds'] ?? []),
            ),
            'shipment_urgency_ids' => self::singlePositiveId(
                $primaryShipment ? [$primaryShipment['shipment_urgency_id'] ?? null] : ($data['shipmentUrgencyIds'] ?? []),
            ),
            'allow_multiple_shipments' => $initialShipments !== []
                ? count($initialShipments) > 1
                : (bool) ($data['allowMultipleShipments'] ?? false),
            // The generic "Allow multiple shipments" option intentionally does
            // not force one address mode. The persisted aggregate derives the
            // effective mode from its rows after creation.
            'shipment_address_mode' => $createShipmentMode === 'multiple_address'
                ? 'multiple_address'
                : 'same_address',
            'initial_shipments' => $initialShipments,
            'client_id' => $data['clientId'],
            // The first selected Inquiry remains the legacy primary/source id;
            // the full ordered set is consumed by LegacyJobService inside the
            // same transaction so an Order can start with multiple Inquiry links.
            'source_inquiry_id' => $inquiryIds->first(),
            'source_inquiry_ids' => $inquiryIds->all(),
            'workflow_id' => $data['workflowId'],
            'workflow_phase_id' => $data['workflowPhaseId'],
            'owner_id' => $data['ownerId'],
            'coordinator_id' => $data['coordinatorId'] ?: $data['ownerId'],
            'delivery_date' => $data['deliveryDate'] ?: null,
            'estimated_delivery_date' => $data['estimatedDeliveryDate'] ?: null,
            'description' => $data['description'],
            'shipping_address' => $primaryShipment
                ? self::legacyAddress($primaryShipment)
                : (blank($data['shippingAddress'] ?? null) ? null : trim((string) $data['shippingAddress'])),
            'shipping_contact_type' => $primaryShipment
                ? 'end_customer'
                : (blank($data['shippingContactType'] ?? null) ? null : trim((string) $data['shippingContactType'])),
            'shipping_contact_name' => $primaryShipment
                ? self::nullableTrim($primaryShipment['recipient'] ?? null)
                : (blank($data['shippingContactName'] ?? null) ? null : trim((string) $data['shippingContactName'])),
            'shipping_phone_country_code' => $primaryShipment
                ? self::nullableTrim($primaryShipment['phone_country_code'] ?? null)
                : (blank($data['shippingPhoneCountryCode'] ?? null) ? null : trim((string) $data['shippingPhoneCountryCode'])),
            'shipping_phone' => $primaryShipment
                ? self::nullableTrim($primaryShipment['phone'] ?? null)
                : (blank($data['shippingPhone'] ?? null) ? null : trim((string) $data['shippingPhone'])),
            'shipping_postal_code' => $primaryShipment
                ? self::nullableTrim($primaryShipment['postal_code'] ?? null)
                : (blank($data['shippingPostalCode'] ?? null) ? null : trim((string) $data['shippingPostalCode'])),
            'shipping_source_address_id' => $primaryShipment
                ? ($primaryShipment['shipping_source_address_id'] ?? null)
                : ($data['shippingSourceAddressId'] ?? null),
            'draft' => $draft,
        ]);
    }

    /**
     * Convert the Livewire-only Create Order rows into a persistence-neutral
     * shipment payload consumed by OrderShipmentService.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function initialShipments(array $data): array
    {
        $rows = $data['createShipments'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'recipient' => trim((string) ($row['contact_name'] ?? $row['recipient'] ?? '')),
                'phone_country_code' => trim((string) ($row['phone_country_code'] ?? '')),
                'phone' => trim((string) ($row['phone'] ?? '')),
                'address' => trim((string) ($row['address'] ?? '')),
                'city' => trim((string) ($row['city'] ?? '')),
                'state' => trim((string) ($row['state'] ?? '')),
                'postal_code' => trim((string) ($row['postal_code'] ?? '')),
                'country' => trim((string) ($row['country'] ?? '')),
                'shipping_source_address_id' => filled($row['shipping_source_address_id'] ?? null)
                    ? (int) $row['shipping_source_address_id']
                    : null,
                'shipment_method_id' => filled($row['shipment_method_id'] ?? null)
                    ? (int) $row['shipment_method_id']
                    : null,
                'shipment_urgency_id' => filled($row['shipment_urgency_id'] ?? null)
                    ? (int) $row['shipment_urgency_id']
                    : null,
                'quantity' => filled($row['quantity'] ?? null) ? (int) $row['quantity'] : null,
                'package_reference' => self::nullableTrim($row['package_reference'] ?? null),
            ])
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $shipment */
    private static function legacyAddress(array $shipment): ?string
    {
        $street = trim((string) ($shipment['address'] ?? ''));
        $locality = collect([
            trim((string) ($shipment['city'] ?? '')),
            trim((string) ($shipment['state'] ?? '')),
            trim((string) ($shipment['postal_code'] ?? '')),
        ])->filter()->implode(', ');
        $country = trim((string) ($shipment['country'] ?? ''));

        $address = collect([$street, $locality, $country])
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");

        return $address === '' ? null : $address;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    /**
     * Persist at most one selected Master Data id while retaining the existing
     * JSON-array schema used by FlowJob and reporting.
     *
     * @param array<int,mixed> $ids
     * @return array<int,int>
     */
    private static function singlePositiveId(array $ids): array
    {
        $id = collect($ids)
            ->map(fn ($value) => (int) $value)
            ->first(fn (int $value) => $value > 0);

        return $id ? [$id] : [];
    }

    /**
     * Create the human-readable Order title without duplicating user input.
     *
     * Examples:
     *   FO-333119 – Premium Cotton T-Shirt
     *   FO-333119 – Premium Cotton T-Shirt + 2 more
     *
     * FlowTrack's generated job_number/Order Code is deliberately independent
     * from this title and continues to be assigned by LegacyJobService.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function generateTitle(string $clientReference, array $items): string
    {
        $reference = trim($clientReference);
        $products = collect($items)
            ->map(fn (array $item): string => trim((string) ($item['product'] ?? '')))
            ->filter(fn (string $name): bool => $name !== '')
            ->values();

        $firstProduct = (string) ($products->first() ?? 'Product');
        $remaining = max(0, $products->count() - 1);
        $suffix = $remaining > 0 ? ' + '.$remaining.' more' : '';
        $prefix = $reference.' – ';
        $title = $prefix.$firstProduct.$suffix;

        // flow_jobs.title is VARCHAR(255). Normal product/reference names fit
        // comfortably, but keep pathological long master-data values safe while
        // preserving the client reference and the "+ N more" indicator where possible.
        if (mb_strlen($title) <= 255) {
            return $title;
        }

        $availableForProduct = 255 - mb_strlen($prefix) - mb_strlen($suffix);
        if ($availableForProduct > 1) {
            return $prefix.mb_substr($firstProduct, 0, $availableForProduct - 1).'…'.$suffix;
        }

        return mb_substr($title, 0, 255);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}
