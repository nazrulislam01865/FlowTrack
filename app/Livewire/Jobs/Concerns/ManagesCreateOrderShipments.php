<?php

namespace App\Livewire\Jobs\Concerns;

use App\Models\ClientShippingAddress;
use App\Models\MasterRecord;
use App\Services\ClientService;
use App\Services\MasterDataService;
use App\Services\OrderShipmentService;
use App\Support\CreateOrderShippingMethodPresenter;

/**
 * Create Order shipment-plan state and interactions.
 *
 * The persisted Order shipment aggregate already lives in OrderShipmentService.
 * This trait only owns the draft rows shown while a new Order is being created,
 * keeping Create Order UI state separate from Shipment-stage editing state.
 */
trait ManagesCreateOrderShipments
{
    public const CREATE_SHIPMENT_MODE_MULTIPLE = 'multiple_shipments';
    public const CREATE_SHIPMENT_MODE_SAME_ADDRESS = OrderShipmentService::MODE_SAME_ADDRESS;
    public const CREATE_SHIPMENT_MODE_MULTIPLE_ADDRESS = OrderShipmentService::MODE_MULTIPLE_ADDRESS;
    private const CREATE_SHIPMENT_LIMIT = 20;

    public function setCreateShipmentMode(string $mode): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless(in_array($mode, self::createShipmentModes(), true), 422, 'Choose a valid shipment setup option.');

        $this->createShipmentMode = $mode;
        $this->ensureCreateShipmentRows();

        // The Create Order UI now has two clear choices:
        // - Address: one shipment address only.
        // - Multiple address: one independently editable address per shipment.
        // Keep the legacy same-address mode accepted for already-open browser
        // snapshots, but never retain hidden extra rows when the user returns to
        // the single Address option.
        if ($mode === self::CREATE_SHIPMENT_MODE_MULTIPLE) {
            $this->createShipments = [array_values($this->createShipments)[0]];
            $this->syncLegacyCreateShippingFields();
        } elseif ($mode === self::CREATE_SHIPMENT_MODE_SAME_ADDRESS) {
            $this->syncCreateShipmentAddressesToPrimary();
        }

        $this->resetValidation(['createShipmentMode', 'createShipments']);
    }

    public function addCreateShipment(): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        $this->ensureCreateShipmentRows();
        abort_unless(
            $this->createShipmentMode === self::CREATE_SHIPMENT_MODE_MULTIPLE_ADDRESS,
            422,
            'Select Multiple address before adding another shipment.'
        );
        abort_if(count($this->createShipments) >= self::CREATE_SHIPMENT_LIMIT, 422, 'You can configure up to '.self::CREATE_SHIPMENT_LIMIT.' shipments while creating an Order.');

        // Multiple-address rows are intentionally independent. Do not clone the
        // primary delivery address into a new row; the user can fill it or pick
        // another saved address explicitly.
        $this->createShipments[] = $this->newCreateShipmentRow();
        $this->resetValidation('createShipments');
    }

    public function removeCreateShipment(int $index): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless(array_key_exists($index, $this->createShipments), 422, 'That shipment is no longer available.');
        abort_if($index === 0, 422, 'Shipment 1 is the primary shipment and cannot be removed.');

        unset($this->createShipments[$index]);
        $this->createShipments = array_values($this->createShipments);
        $this->resetValidation('createShipments');
        $this->syncLegacyCreateShippingFields();
    }

    public function selectCreateShipmentMethod(int $index, int $methodId, ?int $urgencyId = null): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless(array_key_exists($index, $this->createShipments), 422, 'That shipment is no longer available.');

        $method = app(MasterDataService::class)
            ->active('shipment_method')
            ->first(fn (MasterRecord $record): bool => (int) $record->id === $methodId);
        abort_unless($method, 422, 'Choose an active shipping method.');

        $normalizedUrgencyId = null;
        if ($urgencyId) {
            abort_unless(
                CreateOrderShippingMethodPresenter::methodKind($method) === 'express',
                422,
                'Shipping urgency is available only for Standard Express Shipping.',
            );
            $urgency = app(MasterDataService::class)
                ->active('shipment_urgency')
                ->first(fn (MasterRecord $record): bool => (int) $record->id === (int) $urgencyId);
            abort_unless($urgency, 422, 'Choose an active shipment urgency.');
            $normalizedUrgencyId = (int) $urgency->id;
        }

        // The Create Order form now owns one Order-level shipping method in
        // Schedule & owner. Keep this legacy per-row endpoint compatible with
        // browser snapshots that were opened before the deployment, but apply
        // the selected value to every shipment rather than allowing the draft
        // rows to diverge. Individual methods can still be changed later in the
        // Shipment stage.
        $this->setCreateShippingSelectionForAllShipments((int) $method->id, $normalizedUrgencyId);
    }

    /**
     * Store the Order-level Create Order shipping choice and mirror it onto every
     * draft shipment. The shipment rows are the persistence source of truth, so
     * this guarantees that each address arrives in the Shipment stage with the
     * Schedule & owner selection already attached.
     */
    private function setCreateShippingSelectionForAllShipments(?int $methodId, ?int $urgencyId = null): void
    {
        $this->ensureCreateShipmentRows();

        $this->shipmentMethodIds = $methodId ? [$methodId] : [];
        $this->shipmentUrgencyIds = $methodId && $urgencyId ? [$urgencyId] : [];
        $this->syncCreateShipmentMethodsFromGlobalSelection();

        $validationKeys = ['shipmentMethodIds', 'shipmentUrgencyIds'];
        foreach (array_keys($this->createShipments) as $shipmentIndex) {
            $validationKeys[] = "createShipments.$shipmentIndex.shipment_method_id";
            $validationKeys[] = "createShipments.$shipmentIndex.shipment_urgency_id";
        }
        $this->resetValidation($validationKeys);
    }

    /** Apply the current global selection to all draft rows without UI side effects. */
    private function syncCreateShipmentMethodsFromGlobalSelection(): void
    {
        $methodId = collect($this->shipmentMethodIds)
            ->map(fn ($value): int => (int) $value)
            ->first(fn (int $value): bool => $value > 0);
        $urgencyId = collect($this->shipmentUrgencyIds)
            ->map(fn ($value): int => (int) $value)
            ->first(fn (int $value): bool => $value > 0);

        foreach (array_keys($this->createShipments) as $shipmentIndex) {
            $this->createShipments[$shipmentIndex]['shipment_method_id'] = $methodId ?: null;
            $this->createShipments[$shipmentIndex]['shipment_urgency_id'] = $methodId && $urgencyId ? $urgencyId : null;
        }
    }

    /**
     * Livewire nested-array update hook. Country changes invalidate State, and
     * the primary row drives all address fields in same-address mode.
     */
    public function updatedCreateShipments(mixed $value, string $key): void
    {
        if (!$this->showCreate) return;

        if (preg_match('/^(\d+)\.country$/', $key, $matches) === 1) {
            $index = (int) $matches[1];
            if (array_key_exists($index, $this->createShipments)) {
                $this->createShipments[$index]['country'] = trim((string) $value);
                $this->createShipments[$index]['state'] = '';
                $this->createShipments[$index]['shipping_source_address_id'] = null;
                $this->resetValidation([
                    "createShipments.$index.country",
                    "createShipments.$index.state",
                    "createShipments.$index.shipping_source_address_id",
                ]);
            }
        }

        if ($this->createShipmentMode === self::CREATE_SHIPMENT_MODE_SAME_ADDRESS && str_starts_with($key, '0.')) {
            $this->syncCreateShipmentAddressesToPrimary();
        }

        if (str_starts_with($key, '0.')) {
            $this->syncLegacyCreateShippingFields();
        }
    }

    public function openSavedShippingAddressPickerForShipment(int $index): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless(array_key_exists($index, $this->createShipments), 422, 'That shipment is no longer available.');
        abort_unless($this->clientId, 422, 'Select a client first.');

        $clientAvailable = app(ClientService::class)
            ->referenceQuery(auth()->user(), 'create-job')
            ->where('is_active', true)
            ->whereKey($this->clientId)
            ->exists();
        abort_unless($clientAvailable, 403);

        $this->savedShippingAddressShipmentIndex = $index;
        $this->showSavedShippingAddressPicker = true;
        $this->resetValidation("createShipments.$index.address");
    }

    public function applySavedShippingAddressToCreateShipment(int $addressId): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless($this->clientId, 422, 'Select a client first.');

        $index = $this->savedShippingAddressShipmentIndex ?? 0;
        abort_unless(array_key_exists($index, $this->createShipments), 422, 'That shipment is no longer available.');

        $address = ClientShippingAddress::query()
            ->where('client_id', $this->clientId)
            ->findOrFail($addressId);

        $street = collect([$address->address_line1, $address->suite])
            ->filter(fn ($part) => filled($part))
            ->map(fn ($part) => trim((string) $part))
            ->implode(', ');

        $this->createShipments[$index] = array_merge($this->createShipments[$index], [
            'contact_name' => filled($address->recipient)
                ? trim((string) $address->recipient)
                : (string) ($this->createShipments[$index]['contact_name'] ?? ''),
            'address' => $street,
            'city' => trim((string) $address->city),
            'state' => trim((string) $address->state),
            'postal_code' => trim((string) $address->zip),
            'country' => trim((string) $address->country),
            'shipping_source_address_id' => (int) $address->id,
        ]);

        if ($index === 0 && $this->createShipmentMode === self::CREATE_SHIPMENT_MODE_SAME_ADDRESS) {
            $this->syncCreateShipmentAddressesToPrimary();
        }

        $this->shippingSourceAddressId = (int) $address->id;
        $this->showSavedShippingAddressPicker = false;
        $this->savedShippingAddressShipmentIndex = null;
        $this->syncLegacyCreateShippingFields();
        $this->resetValidation('createShipments');
    }

    private function initializeCreateShipments(): void
    {
        $seed = [
            'contact_name' => trim((string) $this->shippingContactName),
            'phone_country_code' => trim((string) $this->shippingPhoneCountryCode) ?: self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE,
            'phone' => trim((string) $this->shippingPhone),
        ];

        $this->createShipmentMode = self::CREATE_SHIPMENT_MODE_MULTIPLE;
        $this->createShipments = [$this->newCreateShipmentRow($seed, false)];
        $this->savedShippingAddressShipmentIndex = null;
        $this->showSavedShippingAddressPicker = false;
        $this->syncLegacyCreateShippingFields();
    }

    private function ensureCreateShipmentRows(): void
    {
        if ($this->createShipments !== []) return;
        $this->initializeCreateShipments();
    }

    /**
     * @param array<string,mixed>|null $source
     * @return array<string,mixed>
     */
    private function newCreateShipmentRow(?array $source = null, bool $copyAddress = true): array
    {
        $source ??= [];

        $selectedMethodId = collect($this->shipmentMethodIds)
            ->map(fn ($value): int => (int) $value)
            ->first(fn (int $value): bool => $value > 0);
        $selectedUrgencyId = collect($this->shipmentUrgencyIds)
            ->map(fn ($value): int => (int) $value)
            ->first(fn (int $value): bool => $value > 0);

        $row = [
            'contact_name' => '',
            'phone_country_code' => self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE,
            'phone' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'postal_code' => '',
            // The approved Create Order prototype captures the delivery address
            // as one free-text field plus postal code. Structured location data
            // is populated only when a saved address supplies it and can still
            // be completed later in the Shipment stage.
            'country' => '',
            'shipping_source_address_id' => null,
            'shipment_method_id' => $selectedMethodId ?: null,
            'shipment_urgency_id' => $selectedMethodId && $selectedUrgencyId ? $selectedUrgencyId : null,
            'quantity' => null,
            'package_reference' => '',
        ];

        foreach (['contact_name', 'phone_country_code', 'phone'] as $field) {
            if (array_key_exists($field, $source)) $row[$field] = trim((string) ($source[$field] ?? ''));
        }

        if ($copyAddress) {
            foreach (['address', 'city', 'state', 'postal_code', 'country', 'shipping_source_address_id'] as $field) {
                if (array_key_exists($field, $source)) $row[$field] = $source[$field];
            }
        }

        return $row;
    }

    private function normalizeCreateShipmentsBeforeSave(): void
    {
        $this->ensureCreateShipmentRows();

        if ($this->createShipmentMode === self::CREATE_SHIPMENT_MODE_MULTIPLE) {
            // Address mode is a single-address Create Order. Normalize here as a
            // final server-side guard so hidden/stale browser state can never
            // create extra shipment rows accidentally.
            $this->createShipments = [array_values($this->createShipments)[0]];
        } elseif ($this->createShipmentMode === self::CREATE_SHIPMENT_MODE_SAME_ADDRESS) {
            $this->syncCreateShipmentAddressesToPrimary();
        }

        // Compatibility for an already-open Create Order page from the previous
        // per-shipment method UI: if the global arrays are empty, promote the
        // primary row's method to the new Order-level selection, then copy it to
        // every address before validation/persistence.
        if (collect($this->shipmentMethodIds)->filter(fn ($value) => (int) $value > 0)->isEmpty()) {
            $primary = $this->createShipments[0] ?? [];
            if (filled($primary['shipment_method_id'] ?? null)) {
                $this->shipmentMethodIds = [(int) $primary['shipment_method_id']];
                $this->shipmentUrgencyIds = filled($primary['shipment_urgency_id'] ?? null)
                    ? [(int) $primary['shipment_urgency_id']]
                    : [];
            }
        }
        $this->syncCreateShipmentMethodsFromGlobalSelection();

        foreach ($this->createShipments as $index => $shipment) {
            foreach (['contact_name', 'phone_country_code', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'package_reference'] as $field) {
                $this->createShipments[$index][$field] = trim((string) ($shipment[$field] ?? ''));
            }
            $this->createShipments[$index]['shipping_source_address_id'] = filled($shipment['shipping_source_address_id'] ?? null)
                ? (int) $shipment['shipping_source_address_id']
                : null;
            $this->createShipments[$index]['shipment_method_id'] = filled($shipment['shipment_method_id'] ?? null)
                ? (int) $shipment['shipment_method_id']
                : null;
            $this->createShipments[$index]['shipment_urgency_id'] = filled($shipment['shipment_urgency_id'] ?? null)
                ? (int) $shipment['shipment_urgency_id']
                : null;
            $this->createShipments[$index]['quantity'] = filled($shipment['quantity'] ?? null)
                ? (int) $shipment['quantity']
                : null;
        }

        $this->syncLegacyCreateShippingFields();
    }

    private function syncCreateShipmentAddressesToPrimary(): void
    {
        if (count($this->createShipments) < 2) return;
        $primary = $this->createShipments[0] ?? null;
        if (!is_array($primary)) return;

        $fields = [
            'contact_name',
            'phone_country_code',
            'phone',
            'address',
            'city',
            'state',
            'postal_code',
            'country',
            'shipping_source_address_id',
        ];

        foreach (array_keys($this->createShipments) as $index) {
            if ($index === 0) continue;
            foreach ($fields as $field) {
                $this->createShipments[$index][$field] = $primary[$field] ?? null;
            }
        }
    }

    /** Keep the legacy FlowJob shipping columns sourced from Shipment 1. */
    private function syncLegacyCreateShippingFields(): void
    {
        $primary = $this->createShipments[0] ?? null;
        if (!is_array($primary)) return;

        $this->shippingContactType = 'end_customer';
        $this->shippingContactId = null;
        $this->shippingContactName = trim((string) ($primary['contact_name'] ?? ''));
        $this->shippingContactSelection = $this->shippingContactName !== '' ? 'custom:'.$this->shippingContactName : '';
        $this->shippingSaveContact = true;
        $this->shippingPhoneCountryCode = trim((string) ($primary['phone_country_code'] ?? '')) ?: self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
        $this->shippingPhone = trim((string) ($primary['phone'] ?? ''));
        $this->shippingPostalCode = trim((string) ($primary['postal_code'] ?? ''));
        $this->shippingSourceAddressId = filled($primary['shipping_source_address_id'] ?? null)
            ? (int) $primary['shipping_source_address_id']
            : null;
        $this->shipmentMethodIds = filled($primary['shipment_method_id'] ?? null)
            ? [(int) $primary['shipment_method_id']]
            : [];
        $this->shipmentUrgencyIds = filled($primary['shipment_urgency_id'] ?? null)
            ? [(int) $primary['shipment_urgency_id']]
            : [];

        $street = trim((string) ($primary['address'] ?? ''));
        $locality = collect([
            trim((string) ($primary['city'] ?? '')),
            trim((string) ($primary['state'] ?? '')),
            trim((string) ($primary['postal_code'] ?? '')),
        ])->filter()->implode(', ');
        $country = trim((string) ($primary['country'] ?? ''));

        $this->shippingAddress = collect([$street, $locality, $country])
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");
    }

    /** @return array<int,string> */
    private static function createShipmentModes(): array
    {
        return [
            self::CREATE_SHIPMENT_MODE_MULTIPLE,
            self::CREATE_SHIPMENT_MODE_SAME_ADDRESS,
            self::CREATE_SHIPMENT_MODE_MULTIPLE_ADDRESS,
        ];
    }
}
