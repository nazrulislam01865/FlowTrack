<?php

namespace App\Livewire\Jobs\Concerns;

use App\Models\ClientShippingAddress;
use App\Models\OrderShipment;
use App\Models\Task;
use App\Services\AccessControlService;
use App\Services\OrderShipmentService;
use App\Services\LocationMasterDataService;
use App\Services\OrderTaskSequenceService;
use App\Services\OrderWorkflowActionService;
use App\Services\TaskService;
use App\Support\CreateOrderShippingMethodPresenter;
use Illuminate\Validation\ValidationException;

/**
 * Livewire coordinator for the Order Details multi-shipment prototype.
 * Persistence and workflow aggregation remain in OrderShipmentService.
 */
trait ManagesOrderShipments
{
    public bool $showShipmentModal = false;
    public bool $showShipmentSavedAddressPicker = false;
    public ?int $shipmentModalTaskId = null;
    public ?int $shipmentEditingId = null;
    public string $shipmentModalMode = OrderShipmentService::MODE_SAME_ADDRESS;
    /** @var array<string,mixed> */
    public array $shipmentForm = [];

    public ?int $shipmentInlineTaskId = null;
    public ?int $shipmentInlineEditingId = null;
    public string $shipmentInlineAddressMode = OrderShipmentService::MODE_SAME_ADDRESS;
    /** @var array<string,mixed> */
    public array $shipmentInlineForm = [];

    public bool $showShipmentDetailsModal = false;
    public ?int $shipmentDetailsId = null;

    public function confirmShipmentPlan(int $taskId): void
    {
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_CONFIRM_INFO', true);
        $this->resetValidation('shipmentSettings');

        try {
            app(OrderShipmentService::class)->confirmSetup($task, auth()->user());
        } catch (ValidationException $exception) {
            $this->applyShipmentValidation($exception);
            return;
        }

        session()->flash('success', 'Shipment details confirmed. Tracking setup is now available.');
    }

    public function openAddShipment(int $taskId, ?string $addressMode = null): void
    {
        $this->cancelInlineShipmentEdit();
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_CONFIRM_INFO');
        $job = $task->job()->with(['shipments.shippingMethod', 'shipments.shipmentUrgency', 'shipments.courier'])->firstOrFail();
        abort_unless((int) $job->workflow_phase_id === (int) $task->workflow_phase_id, 422, 'Shipments can only be added while the Shipment stage is active.');

        $primary = $job->shipments->firstWhere('is_primary', true) ?: $job->shipments->first();
        $orderShippingSelection = app(OrderShipmentService::class)->orderDefaultShippingSelection($job);
        $defaultMethodId = $orderShippingSelection['shipment_method_id'] ?: $primary?->shipment_method_id;
        $defaultUrgencyId = $orderShippingSelection['shipment_method_id']
            ? $orderShippingSelection['shipment_urgency_id']
            : $primary?->shipment_urgency_id;
        $this->shipmentModalTaskId = $task->id;
        $this->shipmentEditingId = null;
        // New Shipment-stage rows follow the same product rule as Create Order:
        // Add shipment means another independently editable delivery address.
        // Keep an explicit same_address argument working for legacy callers only.
        $this->shipmentModalMode = $addressMode === OrderShipmentService::MODE_SAME_ADDRESS
            ? OrderShipmentService::MODE_SAME_ADDRESS
            : OrderShipmentService::MODE_MULTIPLE_ADDRESS;
        $this->shipmentForm = [
            'recipient' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? (string) ($primary?->recipient ?? '') : '',
            'phone_country_code' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS
                ? (filled($primary?->phone_country_code) ? (string) $primary?->phone_country_code : $this->defaultShipmentPhoneCountryCode())
                : $this->defaultShipmentPhoneCountryCode(),
            'phone' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? (string) ($primary?->phone ?? '') : '',
            'address' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? (string) ($primary?->address ?? '') : '',
            'city' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? (string) ($primary?->city ?? '') : '',
            'state' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? (string) ($primary?->state ?? '') : '',
            'postal_code' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? (string) ($primary?->postal_code ?? '') : '',
            'country' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? (string) ($primary?->country ?? $this->defaultShipmentCountry()) : $this->defaultShipmentCountry(),
            'shipping_source_address_id' => $this->shipmentModalMode === OrderShipmentService::MODE_SAME_ADDRESS ? $primary?->shipping_source_address_id : null,
            'shipment_method_id' => $defaultMethodId,
            'shipment_urgency_id' => $defaultUrgencyId,
            'quantity' => null,
            'package_reference' => '',
        ];
        $this->showShipmentSavedAddressPicker = false;
        $this->resetShipmentPrototypeErrors();
        $this->showShipmentModal = true;
    }

    public function openEditShipment(int $taskId, int $shipmentId): void
    {
        $this->cancelInlineShipmentEdit();
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_CONFIRM_INFO');
        $shipment = $this->shipmentForTask($task, $shipmentId);
        abort_if($shipment->dispatched_at, 422, 'A dispatched shipment can no longer be edited.');

        $this->shipmentModalTaskId = $task->id;
        $this->shipmentEditingId = $shipment->id;

        $taskShipments = $task->job?->shipments ?? collect();
        $primary = $taskShipments->firstWhere('is_primary', true) ?: $taskShipments->sortBy('sequence')->first();
        $orderShippingSelection = app(OrderShipmentService::class)->orderDefaultShippingSelection($task->job);
        $useOrderShippingDefault = (bool) $shipment->is_primary || ! $shipment->shipment_method_id;
        $defaultMethodId = $useOrderShippingDefault && $orderShippingSelection['shipment_method_id']
            ? $orderShippingSelection['shipment_method_id']
            : $shipment->shipment_method_id;
        $defaultUrgencyId = $useOrderShippingDefault && $orderShippingSelection['shipment_method_id']
            ? $orderShippingSelection['shipment_urgency_id']
            : $shipment->shipment_urgency_id;
        $this->shipmentModalMode = $shipment->is_primary
            ? OrderShipmentService::MODE_MULTIPLE_ADDRESS
            : ($primary && OrderShipmentService::sameDeliveryAddress($shipment, $primary)
                ? OrderShipmentService::MODE_SAME_ADDRESS
                : OrderShipmentService::MODE_MULTIPLE_ADDRESS);
        $this->shipmentForm = [
            'recipient' => (string) ($shipment->recipient ?? ''),
            'phone_country_code' => filled($shipment->phone_country_code)
                ? (string) $shipment->phone_country_code
                : $this->defaultShipmentPhoneCountryCode(),
            'phone' => (string) ($shipment->phone ?? ''),
            'address' => (string) ($shipment->address ?? ''),
            'city' => (string) ($shipment->city ?? ''),
            'state' => (string) ($shipment->state ?? ''),
            'postal_code' => (string) ($shipment->postal_code ?? ''),
            'country' => (string) ($shipment->country ?? $this->defaultShipmentCountry()),
            'shipping_source_address_id' => $shipment->shipping_source_address_id,
            'shipment_method_id' => $defaultMethodId,
            'shipment_urgency_id' => $defaultUrgencyId,
            'quantity' => $shipment->quantity !== null ? (int) $shipment->quantity : null,
            'package_reference' => (string) ($shipment->package_reference ?? ''),
        ];
        $this->showShipmentSavedAddressPicker = false;
        $this->resetShipmentPrototypeErrors();
        $this->showShipmentModal = true;
    }

    public function startInlineShipmentEdit(int $taskId, int $shipmentId): void
    {
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_CONFIRM_INFO');
        $shipment = $this->shipmentForTask($task, $shipmentId);
        abort_if($shipment->dispatched_at, 422, 'A dispatched shipment can no longer be edited.');

        // Inline editing and the Add Shipment modal intentionally never compete
        // for the same temporary form state.
        $this->showShipmentModal = false;
        $this->showShipmentSavedAddressPicker = false;
        $this->shipmentModalTaskId = null;
        $this->shipmentEditingId = null;
        $this->shipmentModalMode = OrderShipmentService::MODE_SAME_ADDRESS;
        $this->shipmentForm = [];
        $this->resetShipmentPrototypeErrors();

        $taskShipments = $task->job?->shipments ?? collect();
        $primary = $taskShipments->firstWhere('is_primary', true) ?: $taskShipments->sortBy('sequence')->first();
        $orderShippingSelection = app(OrderShipmentService::class)->orderDefaultShippingSelection($task->job);
        $useOrderShippingDefault = (bool) $shipment->is_primary || ! $shipment->shipment_method_id;
        $defaultMethodId = $useOrderShippingDefault && $orderShippingSelection['shipment_method_id']
            ? $orderShippingSelection['shipment_method_id']
            : $shipment->shipment_method_id;
        $defaultUrgencyId = $useOrderShippingDefault && $orderShippingSelection['shipment_method_id']
            ? $orderShippingSelection['shipment_urgency_id']
            : $shipment->shipment_urgency_id;

        $this->shipmentInlineTaskId = $task->id;
        $this->shipmentInlineEditingId = $shipment->id;
        $this->shipmentInlineAddressMode = $shipment->is_primary
            ? OrderShipmentService::MODE_MULTIPLE_ADDRESS
            : ($primary && OrderShipmentService::sameDeliveryAddress($shipment, $primary)
                ? OrderShipmentService::MODE_SAME_ADDRESS
                : OrderShipmentService::MODE_MULTIPLE_ADDRESS);
        $this->shipmentInlineForm = [
            'recipient' => (string) ($shipment->recipient ?? ''),
            'phone_country_code' => (string) ($shipment->phone_country_code ?? ''),
            'phone' => (string) ($shipment->phone ?? ''),
            'address' => (string) ($shipment->address ?? ''),
            'city' => (string) ($shipment->city ?? ''),
            'state' => (string) ($shipment->state ?? ''),
            'postal_code' => (string) ($shipment->postal_code ?? ''),
            'country' => (string) ($shipment->country ?? $this->defaultShipmentCountry()),
            'shipping_source_address_id' => $shipment->shipping_source_address_id,
            'shipment_method_id' => $defaultMethodId,
            'shipment_urgency_id' => $defaultUrgencyId,
            'quantity' => $shipment->quantity !== null ? (int) $shipment->quantity : null,
            'package_reference' => (string) ($shipment->package_reference ?? ''),
        ];
        $this->resetInlineShipmentErrors();
    }

    public function cancelInlineShipmentEdit(): void
    {
        $this->shipmentInlineTaskId = null;
        $this->shipmentInlineEditingId = null;
        $this->shipmentInlineAddressMode = OrderShipmentService::MODE_SAME_ADDRESS;
        $this->shipmentInlineForm = [];
        $this->resetInlineShipmentErrors();
    }

    public function setInlineShipmentAddressMode(string $mode): void
    {
        $mode = $mode === OrderShipmentService::MODE_MULTIPLE_ADDRESS
            ? OrderShipmentService::MODE_MULTIPLE_ADDRESS
            : OrderShipmentService::MODE_SAME_ADDRESS;
        $this->shipmentInlineAddressMode = $mode;

        if (! $this->shipmentInlineTaskId || ! $this->shipmentInlineEditingId) return;

        $task = $this->shipmentPrototypeTask($this->shipmentInlineTaskId, 'SHIP_CONFIRM_INFO');
        $taskShipments = $task->job?->shipments ?? collect();
        $primary = $taskShipments->firstWhere('is_primary', true) ?: $taskShipments->sortBy('sequence')->first();
        $editing = $taskShipments->firstWhere('id', (int) $this->shipmentInlineEditingId)
            ?: $this->shipmentForTask($task, (int) $this->shipmentInlineEditingId);

        if ($editing->is_primary) {
            $this->shipmentInlineAddressMode = OrderShipmentService::MODE_MULTIPLE_ADDRESS;
            return;
        }

        if ($mode === OrderShipmentService::MODE_SAME_ADDRESS) {
            if ($primary) {
                foreach (['recipient', 'phone_country_code', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'shipping_source_address_id'] as $field) {
                    $this->shipmentInlineForm[$field] = $primary->{$field};
                }
            }
        } else {
            foreach (['recipient', 'phone_country_code', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'shipping_source_address_id'] as $field) {
                $this->shipmentInlineForm[$field] = $editing->{$field};
            }
        }

        $this->resetInlineShipmentErrors();
    }

    public function selectInlineShipmentMethod(int $methodId, ?int $urgencyId = null): void
    {
        $this->shipmentInlineForm['shipment_method_id'] = $methodId > 0 ? $methodId : null;
        $this->shipmentInlineForm['shipment_urgency_id'] = $urgencyId && $urgencyId > 0 ? $urgencyId : null;
        $this->resetValidation('shipmentInlineMethod');
    }

    public function updatedShipmentInlineForm(mixed $value, string $key): void
    {
        if ($key !== 'country') {
            return;
        }

        $this->shipmentInlineForm['country'] = trim((string) $value);
        $this->shipmentInlineForm['state'] = '';
        $this->resetValidation(['shipmentInlineForm.country', 'shipmentInlineForm.state']);
    }

    public function saveInlineShipment(): void
    {
        abort_unless($this->shipmentInlineTaskId && $this->shipmentInlineEditingId, 422);
        $task = $this->shipmentPrototypeTask((int) $this->shipmentInlineTaskId, 'SHIP_CONFIRM_INFO');
        $shipment = $this->shipmentForTask($task, (int) $this->shipmentInlineEditingId);
        $payload = array_merge($this->shipmentInlineForm, ['address_mode' => $this->shipmentInlineAddressMode]);
        $this->resetInlineShipmentErrors();

        try {
            app(OrderShipmentService::class)->updateShipment($task, $shipment, auth()->user(), $payload);
        } catch (ValidationException $exception) {
            $this->applyInlineShipmentValidation($exception);
            return;
        }

        $this->cancelInlineShipmentEdit();
        session()->flash('success', 'Shipment updated.');
    }

    public function setShipmentModalAddressMode(string $mode): void
    {
        $mode = $mode === OrderShipmentService::MODE_MULTIPLE_ADDRESS
            ? OrderShipmentService::MODE_MULTIPLE_ADDRESS
            : OrderShipmentService::MODE_SAME_ADDRESS;
        $this->shipmentModalMode = $mode;
        $this->showShipmentSavedAddressPicker = false;

        if (! $this->shipmentModalTaskId) return;

        $task = $this->shipmentPrototypeTask($this->shipmentModalTaskId, 'SHIP_CONFIRM_INFO');
        $taskShipments = $task->job?->shipments ?? collect();
        $primary = $taskShipments->firstWhere('is_primary', true) ?: $taskShipments->sortBy('sequence')->first();

        if ($mode === OrderShipmentService::MODE_SAME_ADDRESS) {
            if ($primary) {
                foreach (['recipient', 'phone_country_code', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'shipping_source_address_id'] as $field) {
                    $this->shipmentForm[$field] = $primary->{$field};
                }
            }
        } elseif ($this->shipmentEditingId) {
            // Restore the shipment's own address when an edit switches from
            // "same as Shipment 1" back to a different delivery address.
            $editing = $taskShipments->firstWhere('id', (int) $this->shipmentEditingId)
                ?: $this->shipmentForTask($task, (int) $this->shipmentEditingId);
            foreach (['recipient', 'phone_country_code', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'shipping_source_address_id'] as $field) {
                $this->shipmentForm[$field] = $editing->{$field};
            }
        } else {
            foreach (['recipient', 'phone', 'address', 'city', 'state', 'postal_code'] as $field) {
                $this->shipmentForm[$field] = '';
            }
            $this->shipmentForm['phone_country_code'] = $this->defaultShipmentPhoneCountryCode();
            $this->shipmentForm['country'] = $this->defaultShipmentCountry();
            $this->shipmentForm['shipping_source_address_id'] = null;
        }

        $this->resetShipmentPrototypeErrors();
    }

    public function openShipmentSavedAddressPicker(): void
    {
        abort_unless($this->showShipmentModal && $this->shipmentModalTaskId, 422, 'Open a shipment first.');
        $task = $this->shipmentPrototypeTask((int) $this->shipmentModalTaskId, 'SHIP_CONFIRM_INFO');
        abort_unless($task->job?->client_id, 422, 'This Order does not have a client with saved shipping addresses.');

        $this->showShipmentSavedAddressPicker = true;
        $this->resetValidation([
            'shipmentForm.recipient',
            'shipmentForm.address',
            'shipmentForm.postal_code',
        ]);
    }

    public function closeShipmentSavedAddressPicker(): void
    {
        $this->showShipmentSavedAddressPicker = false;
    }

    public function applyShipmentSavedAddress(int $addressId): void
    {
        abort_unless($this->showShipmentModal && $this->shipmentModalTaskId, 422, 'Open a shipment first.');
        $task = $this->shipmentPrototypeTask((int) $this->shipmentModalTaskId, 'SHIP_CONFIRM_INFO');
        $clientId = (int) ($task->job?->client_id ?? 0);
        abort_unless($clientId > 0, 422, 'This Order does not have a client with saved shipping addresses.');

        $address = ClientShippingAddress::query()
            ->where('client_id', $clientId)
            ->findOrFail($addressId);

        $street = collect([$address->address_line1, $address->suite])
            ->filter(fn ($part) => filled($part))
            ->map(fn ($part) => trim((string) $part))
            ->implode(', ');

        if (filled($address->recipient)) {
            $this->shipmentForm['recipient'] = trim((string) $address->recipient);
        }
        $this->shipmentForm['address'] = $street;
        $this->shipmentForm['city'] = trim((string) $address->city);
        $this->shipmentForm['state'] = trim((string) $address->state);
        $this->shipmentForm['postal_code'] = trim((string) $address->zip);
        $this->shipmentForm['country'] = trim((string) $address->country);
        $this->shipmentForm['shipping_source_address_id'] = (int) $address->id;
        $this->shipmentModalMode = OrderShipmentService::MODE_MULTIPLE_ADDRESS;
        $this->showShipmentSavedAddressPicker = false;

        $this->resetValidation([
            'shipmentForm.recipient',
            'shipmentForm.address',
            'shipmentForm.postal_code',
            'shipmentForm.country',
            'shipmentForm.state',
        ]);
    }

    public function selectShipmentModalMethod(int $methodId, ?int $urgencyId = null): void
    {
        $this->shipmentForm['shipment_method_id'] = $methodId > 0 ? $methodId : null;
        $this->shipmentForm['shipment_urgency_id'] = $urgencyId && $urgencyId > 0 ? $urgencyId : null;
        $this->resetValidation('shipmentMethod');
    }

    /**
     * Keep State selection tied to the Country master row. Livewire calls this
     * hook when the reusable Country search-select updates shipmentForm.country.
     */
    public function updatedShipmentForm(mixed $value, string $key): void
    {
        if ($key !== 'country') {
            return;
        }

        $this->shipmentForm['country'] = trim((string) $value);
        $this->shipmentForm['state'] = '';
        $this->resetValidation(['shipmentForm.country', 'shipmentForm.state']);
    }

    public function saveShipment(): void
    {
        abort_unless($this->shipmentModalTaskId, 422);
        $task = $this->shipmentPrototypeTask((int) $this->shipmentModalTaskId, 'SHIP_CONFIRM_INFO');
        $payload = array_merge($this->shipmentForm, [
            'address_mode' => $this->shipmentModalMode,
            '_shipment_address_form' => true,
        ]);
        $this->resetShipmentPrototypeErrors();

        if (! filled($payload['shipment_method_id'] ?? null)) {
            $this->addError('shipmentMethod', 'Choose a shipping method.');
            return;
        }

        try {
            if ($this->shipmentEditingId) {
                $shipment = $this->shipmentForTask($task, (int) $this->shipmentEditingId);
                $savedShipment = app(OrderShipmentService::class)->updateShipment($task, $shipment, auth()->user(), $payload);
                $message = 'Shipment updated.';
            } else {
                $savedShipment = app(OrderShipmentService::class)->addShipment($task, auth()->user(), $payload);
                $message = 'Shipment added.';
            }
        } catch (ValidationException $exception) {
            $this->applyShipmentValidation($exception);
            return;
        }

        if ($savedShipment->is_primary) {
            $this->dispatchPrimaryShipmentShippingSelection($savedShipment);
        }

        $this->closeShipmentModal();
        session()->flash('success', $message);
    }

    public function closeShipmentModal(): void
    {
        $this->showShipmentModal = false;
        $this->showShipmentSavedAddressPicker = false;
        $this->shipmentModalTaskId = null;
        $this->shipmentEditingId = null;
        $this->shipmentModalMode = OrderShipmentService::MODE_SAME_ADDRESS;
        $this->shipmentForm = [];
        $this->resetShipmentPrototypeErrors();
    }

    public function removeOrderShipment(int $taskId, int $shipmentId): void
    {
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_CONFIRM_INFO');
        $shipment = $this->shipmentForTask($task, $shipmentId);

        try {
            app(OrderShipmentService::class)->removeShipment($task, $shipment, auth()->user());
        } catch (ValidationException $exception) {
            $this->applyShipmentValidation($exception);
            return;
        }

        session()->flash('success', 'Shipment removed.');
    }

    public function selectOrderShipmentMethod(int $taskId, int $shipmentId, int $methodId, ?int $urgencyId = null): void
    {
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_CONFIRM_INFO');
        $shipment = $this->shipmentForTask($task, $shipmentId);
        $this->resetValidation('shipmentMethod');

        try {
            $updatedShipment = app(OrderShipmentService::class)->updateShippingMethod($task, $shipment, auth()->user(), $methodId, $urgencyId);
        } catch (ValidationException $exception) {
            $this->applyShipmentValidation($exception);
            return;
        }

        if ($updatedShipment->is_primary) {
            $this->dispatchPrimaryShipmentShippingSelection($updatedShipment);
        }
    }

    private function dispatchPrimaryShipmentShippingSelection(OrderShipment $shipment): void
    {
        if (! $shipment->is_primary || ! $shipment->shipment_method_id) {
            return;
        }

        // The header/planning selector lives in the lightweight parent Order
        // shell while Task 5.1 is an isolated Workflow child. Keep it in sync
        // whether the method changes inline or inside Add/Edit Shipment.
        $shipment->loadMissing(['shippingMethod', 'shipmentUrgency']);
        $method = $shipment->shippingMethod;
        $urgency = $shipment->shipmentUrgency;
        $methodKind = CreateOrderShippingMethodPresenter::methodKind($method);
        $selectionName = $methodKind === 'express'
            ? ($urgency ? CreateOrderShippingMethodPresenter::urgencyLabel($urgency) : 'Normal Service')
            : CreateOrderShippingMethodPresenter::methodLabel($method);
        $selectionTone = $methodKind === 'express' && $urgency
            ? CreateOrderShippingMethodPresenter::urgencyKind($urgency)
            : 'normal';
        $selectionValue = CreateOrderShippingMethodPresenter::selectionValue(
            (int) $shipment->shipment_method_id,
            $shipment->shipment_urgency_id ? (int) $shipment->shipment_urgency_id : null,
        );

        $this->dispatch(
            'ft-shipment-urgency-updated',
            jobId: (int) $shipment->flow_job_id,
            value: $selectionValue,
            id: $selectionValue,
            name: $selectionName,
            tone: $selectionTone,
        );
        $this->dispatch('order-runtime-refreshed', orderId: (int) $shipment->flow_job_id);
    }

    public function saveOrderShipmentTracking(int $taskId, int $shipmentId, int $courierId, string $trackingNumber): void
    {
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_LABEL', true);
        $shipment = $this->shipmentForTask($task, $shipmentId);
        $this->resetValidation('shipmentTracking');

        try {
            app(OrderShipmentService::class)->saveTracking($task, $shipment, auth()->user(), $courierId, $trackingNumber);
        } catch (ValidationException $exception) {
            $this->applyShipmentValidation($exception);
            return;
        }

        session()->flash('success', 'Courier and tracking number saved.');
    }

    public function dispatchOrderShipment(int $taskId, int $shipmentId): void
    {
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_PACKAGE', true);
        $shipment = $this->shipmentForTask($task, $shipmentId);
        $this->resetValidation('shipmentDispatch');

        try {
            app(OrderShipmentService::class)->dispatch($task, $shipment, auth()->user());
        } catch (ValidationException $exception) {
            $this->applyShipmentValidation($exception);
            return;
        }

        $this->syncOverviewWorkflowSelectionToCurrentPhase();
        session()->flash('success', 'Shipment marked as dispatched.');
    }

    public function openOrderShipmentDetails(int $taskId, int $shipmentId): void
    {
        $task = $this->shipmentPrototypeTask($taskId, 'SHIP_PACKAGE');
        $shipment = $this->shipmentForTask($task, $shipmentId);
        $this->shipmentDetailsId = $shipment->id;
        $this->showShipmentDetailsModal = true;
    }

    public function closeOrderShipmentDetails(): void
    {
        $this->showShipmentDetailsModal = false;
        $this->shipmentDetailsId = null;
    }

    /** Reset Shipment-only transient UI state when changing/closing Orders. */
    private function resetShipmentPrototypeUi(): void
    {
        $this->showShipmentModal = false;
        $this->showShipmentSavedAddressPicker = false;
        $this->shipmentModalTaskId = null;
        $this->shipmentEditingId = null;
        $this->shipmentModalMode = OrderShipmentService::MODE_SAME_ADDRESS;
        $this->shipmentForm = [];
        $this->shipmentInlineTaskId = null;
        $this->shipmentInlineEditingId = null;
        $this->shipmentInlineAddressMode = OrderShipmentService::MODE_SAME_ADDRESS;
        $this->shipmentInlineForm = [];
        $this->showShipmentDetailsModal = false;
        $this->shipmentDetailsId = null;
        $this->resetShipmentPrototypeErrors();
    }

    private function shipmentPrototypeTask(int $taskId, string $expectedKey, bool $mustBeActionable = false): Task
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job.shipments.shippingMethod', 'job.shipments.shipmentUrgency', 'job.shipments.courier', 'job.phase', 'setupTemplate'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(app(OrderWorkflowActionService::class)->automationKey($task) === $expectedKey, 422);
        abort_if(strcasecmp((string) $task->job?->status, 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');

        $completed = (bool) $task->completed_at || \App\Support\BoardLaneResolver::isCompleted((string) $task->status);
        $currentStage = (int) $task->workflow_phase_id === (int) $task->job?->workflow_phase_id;
        abort_unless($completed || $currentStage, 422, 'This Shipment task is not available yet.');

        if ($mustBeActionable && ! $completed) {
            app(OrderTaskSequenceService::class)->assertStatusActionable($task);
        }

        return $task;
    }

    private function shipmentForTask(Task $task, int $shipmentId): OrderShipment
    {
        return OrderShipment::query()
            ->with(['shippingMethod', 'shipmentUrgency', 'courier'])
            ->where('flow_job_id', $task->flow_job_id)
            ->findOrFail($shipmentId);
    }

    private function resetShipmentPrototypeErrors(): void
    {
        $this->resetValidation([
            'shipmentSettings',
            'shipmentMethod',
            'shipmentTracking',
            'shipmentDispatch',
            'shipmentForm',
            'shipmentForm.recipient',
            'shipmentForm.phone_country_code',
            'shipmentForm.phone',
            'shipmentForm.address',
            'shipmentForm.city',
            'shipmentForm.state',
            'shipmentForm.postal_code',
            'shipmentForm.country',
            'shipmentForm.quantity',
        ]);
    }

    private function resetInlineShipmentErrors(): void
    {
        $this->resetValidation([
            'shipmentInlineMethod',
            'shipmentInlineForm',
            'shipmentInlineForm.recipient',
            'shipmentInlineForm.phone_country_code',
            'shipmentInlineForm.phone',
            'shipmentInlineForm.address',
            'shipmentInlineForm.city',
            'shipmentInlineForm.state',
            'shipmentInlineForm.postal_code',
            'shipmentInlineForm.country',
            'shipmentInlineForm.quantity',
        ]);
    }

    private function applyInlineShipmentValidation(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $inlineField = $field === 'shipmentMethod'
                ? 'shipmentInlineMethod'
                : (str_starts_with($field, 'shipmentForm.')
                    ? 'shipmentInlineForm.'.substr($field, strlen('shipmentForm.'))
                    : $field);

            foreach ((array) $messages as $message) {
                $this->addError($inlineField, $message);
            }
        }
    }

    private function defaultShipmentPhoneCountryCode(): string
    {
        $codes = app(\App\Services\MasterDataService::class)->active('phone_country_code');
        $preferred = $codes->first(fn ($record): bool => trim((string) $record->name) === '+1');
        $first = $preferred ?: $codes->first();

        return trim((string) ($first?->name ?? '+1')) ?: '+1';
    }

    private function defaultShipmentCountry(): string
    {
        return app(LocationMasterDataService::class)->defaultCountryName();
    }

    private function applyShipmentValidation(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ((array) $messages as $message) {
                $this->addError($field, $message);
            }
        }
    }
}
