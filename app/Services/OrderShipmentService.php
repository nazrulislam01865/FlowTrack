<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\OrderShipment;
use App\Models\Task;
use App\Models\User;
use App\Support\BoardLaneResolver;
use App\Support\CreateOrderShippingMethodPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns Order shipment persistence and the aggregate Shipment-stage lifecycle.
 *
 * One Order can have many physical shipments. Workflow tasks stay singular:
 * Task 5.1 confirms the shipment plan, Task 5.2 completes when every active
 * shipment has tracking, and Task 5.3 completes only when every shipment has
 * been dispatched. That keeps the existing seven-stage workflow intact while
 * supporting the prototype's multi-shipment / multi-address behavior.
 */
final class OrderShipmentService
{
    public const MODE_SAME_ADDRESS = 'same_address';
    public const MODE_MULTIPLE_ADDRESS = 'multiple_address';

    /**
     * Resolve the Order-level shipping choice used as the default for Shipment 1
     * and newly-added shipments.
     *
     * Older Orders can have a Shipment Urgency without the newer
     * shipment_method_ids field. In that case the urgency belongs to Standard
     * Express Shipping, so infer the active Express method instead of silently
     * falling back to "Normal" or leaving the method empty.
     *
     * @return array{shipment_method_id:?int,shipment_urgency_id:?int}
     */
    public function orderDefaultShippingSelection(FlowJob $job): array
    {
        $methodId = $this->firstValidMasterId((array) ($job->shipment_method_ids ?? []), 'shipment_method');
        $urgencyId = $this->firstValidMasterId((array) ($job->shipment_urgency_ids ?? []), 'shipment_urgency');

        if (! $methodId && $urgencyId) {
            $expressMethod = $this->activeExpressMethod();
            $methodId = $expressMethod?->id ? (int) $expressMethod->id : null;
        }

        return [
            'shipment_method_id' => $methodId,
            'shipment_urgency_id' => $this->normalizeUrgencyForMethod($methodId, $urgencyId),
        ];
    }

    /**
     * Keep the Order-level Shipment urgency and the primary Shipment row on one
     * canonical shipping selection. The legacy Order fields represent the
     * default/primary shipment; additional shipment rows may still use their
     * own methods without overwriting that Order-level choice.
     *
     * Choosing any Order-level urgency means Standard Express Shipping. An
     * empty urgency is the existing virtual "Normal Service" Express option.
     */
    public function syncPrimaryShipmentFromOrderUrgency(FlowJob $job, User $actor, ?int $urgencyId): ?OrderShipment
    {
        $expressMethod = $this->activeExpressMethod();

        if (! $expressMethod) {
            throw ValidationException::withMessages([
                'shipmentUrgencyIds' => 'Standard Express Shipping is not available in Shipment Method master data.',
            ]);
        }

        return $this->syncPrimaryShipmentFromOrderShippingSelection(
            $job,
            $actor,
            (int) $expressMethod->id,
            $urgencyId,
        );
    }

    /**
     * Persist the complete Order-level shipping selection and mirror it to the
     * primary Shipment row. This is the canonical two-way bridge between the
     * Planning & ownership control and Task 5.1.
     *
     * Sea/Air/Road carry no shipment urgency. Standard Express may carry the
     * virtual Normal level (null) or one active Urgent/Super Urgent master ID.
     */
    public function syncPrimaryShipmentFromOrderShippingSelection(
        FlowJob $job,
        User $actor,
        int $methodId,
        ?int $urgencyId = null,
    ): ?OrderShipment {
        return DB::transaction(function () use ($job, $actor, $methodId, $urgencyId): ?OrderShipment {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $method = $this->validatedShippingMethod($methodId);
            $normalizedUrgencyId = $this->validatedUrgencyId($urgencyId, $method);

            $lockedJob->update([
                'shipment_method_ids' => [(int) $method->id],
                'shipment_urgency_ids' => $normalizedUrgencyId ? [(int) $normalizedUrgencyId] : [],
            ]);

            $primary = OrderShipment::query()
                ->where('flow_job_id', $lockedJob->id)
                ->orderByDesc('is_primary')
                ->orderBy('sequence')
                ->lockForUpdate()
                ->first();

            if (! $primary) {
                return null;
            }

            abort_if($primary->dispatched_at, 422, 'The primary shipment method cannot be changed after dispatch.');

            $changed = (int) $primary->shipment_method_id !== (int) $method->id
                || (int) ($primary->shipment_urgency_id ?? 0) !== (int) ($normalizedUrgencyId ?? 0);

            if ($changed) {
                $hadTracking = filled($primary->tracking_number) || (bool) $primary->label_printed_at;
                $primary->update([
                    'shipment_method_id' => $method?->id,
                    'shipment_urgency_id' => $normalizedUrgencyId,
                    // A shipping selection change invalidates any label that
                    // was generated for the previous method/service level.
                    'tracking_number' => $hadTracking ? null : $primary->tracking_number,
                    'label_printed_at' => $hadTracking ? null : $primary->label_printed_at,
                    'updated_by' => $actor->id,
                ]);

                if ($hadTracking) {
                    $this->reopenTrackingTask($lockedJob, $actor);
                }
            }

            return $primary->refresh();
        }, 3);
    }

    public function seedPrimaryShipment(FlowJob $job, ?User $actor = null): OrderShipment
    {
        return DB::transaction(function () use ($job, $actor): OrderShipment {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $existing = OrderShipment::query()
                ->where('flow_job_id', $lockedJob->id)
                ->orderBy('sequence')
                ->first();
            if ($existing) {
                return $existing;
            }

            $sourceAddress = $lockedJob->shippingSourceAddress()->first();
            $latestShipmentActivity = $lockedJob->activities()
                ->where('event', 'job.shipment_information_confirmed')
                ->latest('id')
                ->first(['meta']);
            $latestShipmentMeta = (array) ($latestShipmentActivity?->meta ?? []);

            // Backfill pre-multi-shipment Orders without losing their existing
            // Shipment-stage state. Querying the Activity model (rather than
            // value('meta')) keeps its array cast intact.
            $latestTrackingActivity = $lockedJob->activities()
                ->where('event', 'job.courier_label_generated')
                ->latest('id')
                ->first(['meta']);
            $latestTrackingMeta = (array) ($latestTrackingActivity?->meta ?? []);
            $latestDispatchActivity = $lockedJob->activities()
                ->where('event', 'job.package_shipped')
                ->latest('id')
                ->first(['created_at']);

            $shippingSelection = $this->orderDefaultShippingSelection($lockedJob);
            $methodId = $shippingSelection['shipment_method_id'];
            $urgencyId = $shippingSelection['shipment_urgency_id'];

            return OrderShipment::create([
                'flow_job_id' => $lockedJob->id,
                'sequence' => 1,
                'is_primary' => true,
                'recipient' => $this->nullableString($latestShipmentMeta['contact_name'] ?? $latestShipmentMeta['recipient'] ?? $lockedJob->shipping_contact_name ?? $sourceAddress?->recipient),
                'phone_country_code' => $this->nullableString($latestShipmentMeta['phone_country_code'] ?? $lockedJob->shipping_phone_country_code),
                'phone' => $this->nullableString($latestShipmentMeta['phone_number'] ?? $lockedJob->shipping_phone),
                'address' => $this->nullableString($latestShipmentMeta['address'] ?? $lockedJob->shipping_address ?? ($sourceAddress ? $this->addressText($sourceAddress) : null)),
                'city' => $this->nullableString($latestShipmentMeta['city'] ?? $sourceAddress?->city),
                'state' => $this->nullableString($latestShipmentMeta['state'] ?? $sourceAddress?->state),
                'postal_code' => $this->nullableString($latestShipmentMeta['postal_code'] ?? $lockedJob->shipping_postal_code ?? $sourceAddress?->zip),
                'country' => $this->nullableString($latestShipmentMeta['country'] ?? $sourceAddress?->country),
                'shipping_source_address_id' => $sourceAddress?->id,
                'shipment_method_id' => $methodId,
                'shipment_urgency_id' => $urgencyId,
                'courier_id' => $this->resolveCourierIdByName($latestTrackingMeta['carrier'] ?? null),
                'tracking_number' => $this->nullableString($latestTrackingMeta['tracking_number'] ?? null),
                'dispatched_at' => $latestDispatchActivity?->created_at,
                'created_by' => $actor?->id ?: $lockedJob->created_by,
                'updated_by' => $actor?->id ?: $lockedJob->created_by,
            ]);
        }, 3);
    }

    /**
     * Persist the shipment rows prepared in Create Order as one aggregate.
     *
     * Shipping method is deliberately optional here because Create Order has
     * historically allowed the Shipment stage to finish that choice later.
     * The Shipment confirmation task still requires every row to have a method
     * before it can be completed.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return Collection<int,OrderShipment>
     */
    public function createInitialShipments(FlowJob $job, User $actor, array $rows): Collection
    {
        return DB::transaction(function () use ($job, $actor, $rows): Collection {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $existing = OrderShipment::query()
                ->where('flow_job_id', $lockedJob->id)
                ->orderBy('sequence')
                ->get();
            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $created = collect();
            foreach (array_values($rows) as $offset => $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                $methodId = filled($payload['shipment_method_id'] ?? null)
                    ? (int) $payload['shipment_method_id']
                    : null;
                $method = $methodId ? $this->validatedShippingMethod($methodId) : null;
                $urgencyId = $method
                    ? $this->validatedUrgencyId($payload['shipment_urgency_id'] ?? null, $method)
                    : null;

                if (! $method && filled($payload['shipment_urgency_id'] ?? null)) {
                    throw ValidationException::withMessages([
                        'createShipments.'.((int) $offset).'.shipment_urgency_id' => 'Choose a shipping method before selecting an urgency.',
                    ]);
                }

                $addressFields = $this->validatedAddressFields([
                    'recipient' => $payload['recipient'] ?? '',
                    'phone_country_code' => $payload['phone_country_code'] ?? '',
                    'phone' => $payload['phone'] ?? '',
                    'address' => $payload['address'] ?? '',
                    'city' => $payload['city'] ?? '',
                    'state' => $payload['state'] ?? '',
                    'postal_code' => $payload['postal_code'] ?? '',
                    'country' => $payload['country'] ?? '',
                    'shipping_source_address_id' => $payload['shipping_source_address_id'] ?? null,
                ], null, false);

                $created->push(OrderShipment::create(array_merge([
                    'flow_job_id' => $lockedJob->id,
                    'sequence' => $offset + 1,
                    'is_primary' => $offset === 0,
                    'shipment_method_id' => $method?->id,
                    'shipment_urgency_id' => $urgencyId,
                    'quantity' => $this->validatedQuantity($payload['quantity'] ?? null, 'createShipments.'.((int) $offset).'.quantity'),
                    'package_reference' => $this->nullableString($payload['package_reference'] ?? null),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ], $addressFields)));
            }

            if ($created->isEmpty()) {
                return collect([$this->seedPrimaryShipment($lockedJob, $actor)]);
            }

            $primary = $created->first();
            if ($primary instanceof OrderShipment) {
                $this->syncLegacyPrimaryAddress($lockedJob, $primary);
            }
            $this->syncPlanFromShipments($lockedJob);

            return $created->map(fn (OrderShipment $shipment): OrderShipment => $shipment->refresh())->values();
        }, 3);
    }

    public function confirmSetup(Task $task, User $actor): Task
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_CONFIRM_INFO');
        $job = FlowJob::query()->findOrFail($task->flow_job_id);
        $shipments = $job->shipments()->get();
        if ($shipments->isEmpty()) {
            $shipments = collect([$this->seedPrimaryShipment($job, $actor)]);
        }

        // Derive the plan from the shipment rows so older Orders with stale
        // global flags cannot block the continue action.
        $this->syncPlanFromShipments($job);
        $job->refresh();

        if ($shipments->contains(fn (OrderShipment $shipment): bool => ! $shipment->shipment_method_id)) {
            throw ValidationException::withMessages([
                'shipmentSettings' => 'Choose a shipping method for every shipment before continuing.',
            ]);
        }

        if ($this->isTaskCompleted($task)) {
            return $task->refresh();
        }

        $this->record($job, $actor, 'job.shipment_plan_confirmed', 'Shipment details confirmed for '.count($shipments).' shipment(s).', [
            'shipment_ids' => $shipments->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]);

        return app(TaskService::class)->moveStatus($task, app(OrderTaskFlagService::class)->completedStatus(), $actor);
    }

    /** @param array<string,mixed> $payload */
    public function addShipment(Task $task, User $actor, array $payload): OrderShipment
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_CONFIRM_INFO');

        return DB::transaction(function () use ($task, $actor, $payload): OrderShipment {
            $job = FlowJob::query()->whereKey($task->flow_job_id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $job->workflow_phase_id === (int) $task->workflow_phase_id, 422, 'New shipments can only be added while the Shipment stage is active.');

            $shipments = OrderShipment::query()->where('flow_job_id', $job->id)->orderBy('sequence')->lockForUpdate()->get();
            if ($shipments->isEmpty()) {
                $this->seedPrimaryShipment($job, $actor);
                $shipments = OrderShipment::query()->where('flow_job_id', $job->id)->orderBy('sequence')->lockForUpdate()->get();
            }

            $addressMode = $this->normalizeAddressMode($payload['address_mode'] ?? $job->shipment_address_mode);
            $methodId = filled($payload['shipment_method_id'] ?? null)
                ? (int) $payload['shipment_method_id']
                : null;
            $method = $methodId ? $this->validatedShippingMethod($methodId) : null;
            $urgencyId = $method
                ? $this->validatedUrgencyId($payload['shipment_urgency_id'] ?? null, $method)
                : null;
            if (! $method && filled($payload['shipment_urgency_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'shipmentMethod' => 'Choose a shipping method before selecting an urgency.',
                ]);
            }
            $nextSequence = ((int) $shipments->max('sequence')) + 1;

            $values = [
                'flow_job_id' => $job->id,
                'sequence' => $nextSequence,
                'is_primary' => false,
                'shipment_method_id' => $method?->id,
                'shipment_urgency_id' => $urgencyId,
                'quantity' => $this->validatedQuantity($payload['quantity'] ?? null),
                'package_reference' => $this->nullableString($payload['package_reference'] ?? null),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ];

            if ($addressMode === self::MODE_SAME_ADDRESS) {
                $primary = $shipments->firstWhere('is_primary', true) ?: $shipments->first();
                $values = array_merge($values, $this->addressFields($primary));
            } else {
                // Shipment-stage add/edit now intentionally mirrors Create Order's
                // compact address form. Structured location fields stay supported
                // when supplied by a saved address, but they are no longer required
                // simply to add another shipment address.
                $values = array_merge($values, $this->validatedAddressFields($payload, null, false, true));
            }

            $shipment = OrderShipment::create($values);
            $this->syncPlanFromShipments($job);

            $this->reopenTrackingTask($job, $actor);
            $this->record($job, $actor, 'job.shipment_added', 'Shipment '.$shipment->sequence.' added.', $this->auditMeta($shipment));

            return $shipment->refresh();
        }, 3);
    }

    /** @param array<string,mixed> $payload */
    public function updateShipment(Task $task, OrderShipment $shipment, User $actor, array $payload): OrderShipment
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_CONFIRM_INFO');
        $this->assertBelongsToTask($task, $shipment);

        return DB::transaction(function () use ($task, $shipment, $actor, $payload): OrderShipment {
            $job = FlowJob::query()->whereKey($task->flow_job_id)->lockForUpdate()->firstOrFail();
            $locked = OrderShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->dispatched_at, 422, 'A dispatched shipment can no longer be edited.');

            $methodId = filled($payload['shipment_method_id'] ?? $locked->shipment_method_id)
                ? (int) ($payload['shipment_method_id'] ?? $locked->shipment_method_id)
                : null;
            $method = $methodId ? $this->validatedShippingMethod($methodId) : null;
            $urgencySource = $payload['shipment_urgency_id'] ?? $locked->shipment_urgency_id;
            $urgencyId = $method
                ? $this->validatedUrgencyId($urgencySource, $method)
                : null;
            if (! $method && filled($urgencySource)) {
                throw ValidationException::withMessages([
                    'shipmentMethod' => 'Choose a shipping method before selecting an urgency.',
                ]);
            }
            $addressMode = $locked->is_primary
                ? self::MODE_MULTIPLE_ADDRESS
                : $this->normalizeAddressMode($payload['address_mode'] ?? $job->shipment_address_mode);
            $shippingMethodChanged = (int) ($locked->shipment_method_id ?? 0) !== (int) ($method?->id ?? 0)
                || (int) ($locked->shipment_urgency_id ?? 0) !== (int) ($urgencyId ?? 0);
            $hadTrackingForPreviousMethod = $shippingMethodChanged
                && (filled($locked->tracking_number) || (bool) $locked->label_printed_at);

            $updates = [
                'shipment_method_id' => $method?->id,
                'shipment_urgency_id' => $urgencyId,
                'quantity' => array_key_exists('quantity', $payload)
                    ? $this->validatedQuantity($payload['quantity'])
                    : $locked->quantity,
                'package_reference' => $this->nullableString($payload['package_reference'] ?? $locked->package_reference),
                // Changing the service level invalidates carrier data that was
                // prepared for the previous shipping method, exactly like the
                // row-level shipping-method editor does.
                'tracking_number' => $hadTrackingForPreviousMethod ? null : $locked->tracking_number,
                'label_printed_at' => $hadTrackingForPreviousMethod ? null : $locked->label_printed_at,
                'updated_by' => $actor->id,
            ];

            if ($locked->is_primary || $addressMode === self::MODE_MULTIPLE_ADDRESS) {
                $isCompactAddressForm = (bool) ($payload['_shipment_address_form'] ?? false);
                $updates = array_merge($updates, $this->validatedAddressFields(
                    $payload,
                    $locked,
                    ! $isCompactAddressForm,
                    $isCompactAddressForm,
                ));
            } else {
                $primary = OrderShipment::query()
                    ->where('flow_job_id', $job->id)
                    ->where('is_primary', true)
                    ->first() ?: OrderShipment::query()->where('flow_job_id', $job->id)->orderBy('sequence')->firstOrFail();
                $updates = array_merge($updates, $this->addressFields($primary));
            }

            $locked->update($updates);

            if ($locked->is_primary) {
                $this->syncLegacyPrimaryAddress($job, $locked->refresh());
                if ($shippingMethodChanged) {
                    $this->syncLegacyPrimaryShippingSelection($job, $locked->refresh());
                }
                if ($job->shipment_address_mode === self::MODE_SAME_ADDRESS) {
                    OrderShipment::query()
                        ->where('flow_job_id', $job->id)
                        ->where('id', '!=', $locked->id)
                        ->whereNull('dispatched_at')
                        ->update(array_merge($this->addressFields($locked->refresh()), ['updated_by' => $actor->id, 'updated_at' => now()]));
                }
            }

            if ($hadTrackingForPreviousMethod) {
                $this->reopenTrackingTask($job, $actor);
            }

            $this->syncPlanFromShipments($job);
            $this->record($job, $actor, 'job.shipment_updated', 'Shipment '.$locked->sequence.' updated.', $this->auditMeta($locked->refresh()));

            return $locked->refresh();
        }, 3);
    }

    public function removeShipment(Task $task, OrderShipment $shipment, User $actor): void
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_CONFIRM_INFO');
        $this->assertBelongsToTask($task, $shipment);
        abort_if($shipment->is_primary, 422, 'The primary shipment cannot be removed.');
        abort_if($shipment->dispatched_at, 422, 'A dispatched shipment cannot be removed.');

        DB::transaction(function () use ($task, $shipment, $actor): void {
            $job = FlowJob::query()->whereKey($task->flow_job_id)->lockForUpdate()->firstOrFail();
            $locked = OrderShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->is_primary || $locked->dispatched_at, 422, 'This shipment can no longer be removed.');

            $meta = $this->auditMeta($locked);
            $locked->delete();
            $this->resequence($job->id);
            $this->syncPlanFromShipments($job);
            $this->record($job, $actor, 'job.shipment_removed', 'Shipment removed.', $meta);

            $this->completeAggregateTasksIfReady($job, $actor);
        }, 3);
    }

    public function updateShippingMethod(Task $task, OrderShipment $shipment, User $actor, int $methodId, ?int $urgencyId): OrderShipment
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_CONFIRM_INFO');
        $this->assertBelongsToTask($task, $shipment);

        return DB::transaction(function () use ($task, $shipment, $actor, $methodId, $urgencyId): OrderShipment {
            $job = FlowJob::query()->whereKey($task->flow_job_id)->lockForUpdate()->firstOrFail();
            $locked = OrderShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->dispatched_at, 422, 'A dispatched shipment method cannot be changed.');

            $method = $this->validatedShippingMethod($methodId);
            $normalizedUrgencyId = $this->validatedUrgencyId($urgencyId, $method);
            $changed = (int) $locked->shipment_method_id !== (int) $method->id
                || (int) ($locked->shipment_urgency_id ?? 0) !== (int) ($normalizedUrgencyId ?? 0);

            if ($changed) {
                $hadTracking = filled($locked->tracking_number) || (bool) $locked->label_printed_at;
                $locked->update([
                    'shipment_method_id' => $method->id,
                    'shipment_urgency_id' => $normalizedUrgencyId,
                    // A courier/method change invalidates the old label.
                    'tracking_number' => $hadTracking ? null : $locked->tracking_number,
                    'label_printed_at' => $hadTracking ? null : $locked->label_printed_at,
                    'updated_by' => $actor->id,
                ]);
                if ($hadTracking) {
                    $this->reopenTrackingTask($job, $actor);
                }
            }

            if ($locked->is_primary) {
                // The Order-level Shipment urgency is the primary/default
                // shipping selection. Keep it in sync when Task 5.1 changes
                // the primary shipment directly. Air/Sea/non-express methods
                // intentionally map back to Normal Service (empty urgency).
                $this->syncLegacyPrimaryShippingSelection($job, $locked->refresh());
            }

            $this->record($job, $actor, 'job.shipment_method_updated', 'Shipment '.$locked->sequence.' shipping method updated.', $this->auditMeta($locked->refresh()));

            return $locked->refresh();
        }, 3);
    }

    public function saveTracking(Task $task, OrderShipment $shipment, User $actor, int $courierId, string $trackingNumber): OrderShipment
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_LABEL');
        $this->assertBelongsToTask($task, $shipment);
        $courier = $this->validatedCourier($courierId);
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            throw ValidationException::withMessages(['shipmentTracking' => 'Enter a tracking number.']);
        }
        if (mb_strlen($trackingNumber) > 255) {
            throw ValidationException::withMessages(['shipmentTracking' => 'Tracking number must be 255 characters or fewer.']);
        }

        return DB::transaction(function () use ($task, $shipment, $actor, $courier, $trackingNumber): OrderShipment {
            $job = FlowJob::query()->whereKey($task->flow_job_id)->lockForUpdate()->firstOrFail();
            $locked = OrderShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'courier_id' => $courier->id,
                'tracking_number' => $trackingNumber,
                'updated_by' => $actor->id,
            ]);

            $this->record($job, $actor, 'job.courier_label_generated', 'Courier and tracking details recorded for Shipment '.$locked->sequence.'.', [
                'shipment_id' => (int) $locked->id,
                'shipment_sequence' => (int) $locked->sequence,
                'courier_id' => (int) $courier->id,
                'carrier' => (string) $courier->name,
                'tracking_number' => $trackingNumber,
            ]);

            $this->completeTrackingTaskIfReady($job, $task, $actor);

            return $locked->refresh()->load('courier');
        }, 3);
    }

    public function markLabelPrinted(Task $task, OrderShipment $shipment, User $actor): OrderShipment
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_LABEL');
        $this->assertBelongsToTask($task, $shipment);
        abort_if(blank($shipment->tracking_number), 422, 'Add a tracking number before printing the label.');

        $shipment->update(['label_printed_at' => now(), 'updated_by' => $actor->id]);
        $this->record($task->job()->firstOrFail(), $actor, 'job.courier_label_printed', 'Courier label printed for Shipment '.$shipment->sequence.'.', [
            'shipment_id' => (int) $shipment->id,
            'shipment_sequence' => (int) $shipment->sequence,
            'tracking_number' => (string) $shipment->tracking_number,
        ]);

        return $shipment->refresh();
    }

    public function dispatch(Task $task, OrderShipment $shipment, User $actor): OrderShipment
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $this->assertShipmentTask($task, 'SHIP_PACKAGE');
        $this->assertBelongsToTask($task, $shipment);
        abort_if(! $shipment->courier_id, 422, 'Select a courier before dispatching this shipment.');
        abort_if(blank($shipment->tracking_number), 422, 'Add a tracking number before dispatching this shipment.');

        return DB::transaction(function () use ($task, $shipment, $actor): OrderShipment {
            $job = FlowJob::query()->whereKey($task->flow_job_id)->lockForUpdate()->firstOrFail();
            $locked = OrderShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if (! $locked->dispatched_at) {
                $locked->update(['dispatched_at' => now(), 'updated_by' => $actor->id]);
                $locked->loadMissing('courier');
                $this->record($job, $actor, 'job.package_shipped', 'Shipment '.$locked->sequence.' marked as dispatched.', [
                    'shipment_id' => (int) $locked->id,
                    'shipment_sequence' => (int) $locked->sequence,
                    'courier_id' => $locked->courier_id ? (int) $locked->courier_id : null,
                    'carrier' => $locked->courier?->name,
                    'tracking_number' => (string) $locked->tracking_number,
                    'shipment_date' => now()->toDateString(),
                ]);
            }

            $this->completeDispatchTaskIfReady($job, $task, $actor);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Derive the global shipment-plan flags from the shipment rows instead of
     * asking the user to maintain a separate global edit mode.
     */
    private function syncPlanFromShipments(FlowJob $job): void
    {
        $shipments = OrderShipment::query()
            ->where('flow_job_id', $job->id)
            ->orderBy('sequence')
            ->get();

        $allowMultiple = $shipments->count() > 1;
        $addressMode = self::MODE_SAME_ADDRESS;

        if ($allowMultiple) {
            $primary = $shipments->firstWhere('is_primary', true) ?: $shipments->first();
            $hasDifferentAddress = $shipments
                ->where('id', '!=', $primary?->id)
                ->contains(fn (OrderShipment $shipment): bool => ! self::sameDeliveryAddress($shipment, $primary));
            $addressMode = $hasDifferentAddress ? self::MODE_MULTIPLE_ADDRESS : self::MODE_SAME_ADDRESS;
        }

        $job->update([
            'allow_multiple_shipments' => $allowMultiple,
            'shipment_address_mode' => $addressMode,
        ]);
    }

    private function completeAggregateTasksIfReady(FlowJob $job, User $actor): void
    {
        $labelTask = $this->taskForKey($job, 'SHIP_LABEL');
        if ($labelTask && ! $this->isTaskCompleted($labelTask)) {
            $this->completeTrackingTaskIfReady($job, $labelTask, $actor);
        }

        $dispatchTask = $this->taskForKey($job, 'SHIP_PACKAGE');
        if ($dispatchTask && ! $this->isTaskCompleted($dispatchTask)) {
            $this->completeDispatchTaskIfReady($job, $dispatchTask, $actor);
        }
    }

    private function completeTrackingTaskIfReady(FlowJob $job, Task $task, User $actor): void
    {
        if ($this->isTaskCompleted($task)) return;
        $shipments = OrderShipment::query()->where('flow_job_id', $job->id)->get();
        if ($shipments->isEmpty() || $shipments->contains(fn (OrderShipment $shipment): bool => blank($shipment->tracking_number) || ! $shipment->courier_id)) return;

        app(TaskService::class)->moveStatus($task->refresh(), app(OrderTaskFlagService::class)->completedStatus(), $actor);
    }

    private function completeDispatchTaskIfReady(FlowJob $job, Task $task, User $actor): void
    {
        if ($this->isTaskCompleted($task)) return;
        $shipments = OrderShipment::query()->where('flow_job_id', $job->id)->get();
        if ($shipments->isEmpty() || $shipments->contains(fn (OrderShipment $shipment): bool => ! $shipment->dispatched_at)) return;

        app(TaskService::class)->moveStatus($task->refresh(), app(OrderTaskFlagService::class)->completedStatus(), $actor);
    }

    private function reopenTrackingTask(FlowJob $job, User $actor): void
    {
        $labelTask = $this->taskForKey($job, 'SHIP_LABEL');
        if (! $labelTask || ! $this->isTaskCompleted($labelTask)) return;

        $rules = app(OrderTaskFlagService::class);
        $ready = $rules->readyStatus();
        $labelTask->update([
            'status' => $ready,
            'order_task_status_id' => $rules->statusRecord($ready, false)?->id,
            'progress' => 0,
            'completed_at' => null,
        ]);

        $dispatchTask = $this->taskForKey($job, 'SHIP_PACKAGE');
        if ($dispatchTask && $this->isTaskCompleted($dispatchTask)) {
            $notStarted = $rules->notStartedStatus();
            $dispatchTask->update([
                'status' => $notStarted,
                'order_task_status_id' => $rules->statusRecord($notStarted, false)?->id,
                'progress' => 0,
                'completed_at' => null,
            ]);
        }

        app(OrderTaskSequenceService::class)->synchronizeCurrentPhase($job->refresh(), $actor);
        app(JobService::class)->recalculateProgress($job->refresh());
    }

    private function taskForKey(FlowJob $job, string $key): ?Task
    {
        return Task::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $job->workflow_phase_id)
            ->with('setupTemplate')
            ->get()
            ->first(fn (Task $task): bool => app(OrderWorkflowActionService::class)->automationKey($task) === $key);
    }

    private function activeExpressMethod(): ?MasterRecord
    {
        return MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('shipment_method')
            ->active()
            ->get()
            ->first(fn (MasterRecord $method): bool => CreateOrderShippingMethodPresenter::methodKind($method) === 'express');
    }

    private function validatedShippingMethod(int $methodId): MasterRecord
    {
        if ($methodId <= 0) {
            throw ValidationException::withMessages(['shipmentMethod' => 'Select a shipping method.']);
        }

        $method = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('shipment_method')
            ->active()
            ->find($methodId);
        if (! $method) {
            throw ValidationException::withMessages(['shipmentMethod' => 'The selected shipping method is no longer available.']);
        }

        return $method;
    }

    private function validatedCourier(int $courierId): MasterRecord
    {
        if ($courierId <= 0) {
            throw ValidationException::withMessages(['shipmentTracking' => 'Select a courier.']);
        }

        $courier = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('courier')
            ->active()
            ->find($courierId);
        if (! $courier) {
            throw ValidationException::withMessages(['shipmentTracking' => 'The selected courier is no longer available.']);
        }

        return $courier;
    }

    private function validatedUrgencyId(mixed $urgencyId, MasterRecord $method): ?int
    {
        if (CreateOrderShippingMethodPresenter::methodKind($method) !== 'express') {
            return null;
        }

        $urgencyId = filled($urgencyId) ? (int) $urgencyId : null;
        if (! $urgencyId) return null; // Virtual Normal express option.

        $valid = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('shipment_urgency')
            ->active()
            ->whereKey($urgencyId)
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['shipmentMethod' => 'The selected express urgency is no longer available.']);
        }

        return $urgencyId;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function validatedAddressFields(
        array $payload,
        ?OrderShipment $fallback = null,
        bool $requireStructuredLocation = true,
        bool $requirePhone = false,
    ): array
    {
        $values = [
            'recipient' => trim((string) ($payload['recipient'] ?? $fallback?->recipient ?? '')),
            'phone_country_code' => trim((string) ($payload['phone_country_code'] ?? $fallback?->phone_country_code ?? '')),
            'phone' => trim((string) ($payload['phone'] ?? $fallback?->phone ?? '')),
            'address' => trim((string) ($payload['address'] ?? $fallback?->address ?? '')),
            'city' => trim((string) ($payload['city'] ?? $fallback?->city ?? '')),
            'state' => trim((string) ($payload['state'] ?? $fallback?->state ?? '')),
            'postal_code' => trim((string) ($payload['postal_code'] ?? $fallback?->postal_code ?? '')),
            'country' => trim((string) ($payload['country'] ?? $fallback?->country ?? '')),
            'shipping_source_address_id' => filled($payload['shipping_source_address_id'] ?? null)
                ? (int) $payload['shipping_source_address_id']
                : ($fallback?->shipping_source_address_id),
        ];

        $errors = [];
        $requiredFields = [
            'recipient' => 'Contact person is required.',
            'address' => 'Address is required.',
            'postal_code' => 'Postal code is required.',
        ];
        if ($requirePhone) {
            $requiredFields['phone_country_code'] = 'Phone country code is required.';
            $requiredFields['phone'] = 'Phone number is required.';
        }
        if ($requireStructuredLocation) {
            $requiredFields['country'] = 'Country is required.';
        }

        foreach ($requiredFields as $field => $message) {
            if ($values[$field] === '') $errors['shipmentForm.'.$field] = $message;
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);

        foreach (['recipient' => 255, 'phone_country_code' => 12, 'phone' => 80, 'city' => 120, 'state' => 120, 'postal_code' => 30, 'country' => 120] as $field => $max) {
            if (mb_strlen((string) $values[$field]) > $max) {
                throw ValidationException::withMessages(['shipmentForm.'.$field => ucfirst(str_replace('_', ' ', $field)).' is too long.']);
            }
        }
        if (mb_strlen((string) $values['address']) > 2000) {
            throw ValidationException::withMessages(['shipmentForm.address' => 'Address must be 2,000 characters or fewer.']);
        }

        if ($requirePhone && $values['phone_country_code'] !== '') {
            $phoneCodeExists = app(MasterDataService::class)
                ->active('phone_country_code')
                ->contains(fn (MasterRecord $record): bool => trim((string) $record->name) === $values['phone_country_code']);
            if (! $phoneCodeExists) {
                throw ValidationException::withMessages([
                    'shipmentForm.phone_country_code' => 'Choose an active phone country code from Master Data.',
                ]);
            }
        }

        if ($requireStructuredLocation) {
            $this->validateLocationMasterSelection($values, $fallback);
        }

        return $values;
    }

    /** @param array<string,mixed> $values */
    private function validateLocationMasterSelection(array $values, ?OrderShipment $fallback): void
    {
        $locations = app(LocationMasterDataService::class);
        $country = (string) $values['country'];
        $state = (string) $values['state'];

        // Historical Orders may contain legacy free-text locations. Preserve
        // those exact values when editing unrelated fields, but every new or
        // changed location must resolve through active Country/State master data.
        $countryUnchanged = $fallback
            && strcasecmp(trim((string) $fallback->country), trim($country)) === 0;
        $stateUnchanged = $fallback
            && $countryUnchanged
            && strcasecmp(trim((string) $fallback->state), trim($state)) === 0;

        if (! $locations->countryExists($country) && ! $countryUnchanged) {
            throw ValidationException::withMessages([
                'shipmentForm.country' => 'Select an active country from Country master data.',
            ]);
        }

        $stateOptions = $locations->statesForCountry($country);
        if ($stateOptions->isEmpty()) {
            return;
        }

        if (trim($state) === '' && ! $stateUnchanged) {
            throw ValidationException::withMessages([
                'shipmentForm.state' => 'Please select a state.',
            ]);
        }

        if (! $locations->stateBelongsToCountry($country, $state) && ! $stateUnchanged) {
            throw ValidationException::withMessages([
                'shipmentForm.state' => 'Please select a valid state.',
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function addressFields(OrderShipment $shipment): array
    {
        return [
            'recipient' => $shipment->recipient,
            'phone_country_code' => $shipment->phone_country_code,
            'phone' => $shipment->phone,
            'address' => $shipment->address,
            'city' => $shipment->city,
            'state' => $shipment->state,
            'postal_code' => $shipment->postal_code,
            'country' => $shipment->country,
            'shipping_source_address_id' => $shipment->shipping_source_address_id,
        ];
    }

    private function syncLegacyPrimaryAddress(FlowJob $job, OrderShipment $shipment): void
    {
        $job->update(array_merge([
            'shipping_address' => $this->legacyShippingAddressText($shipment),
            'shipping_contact_name' => $shipment->recipient,
            'shipping_phone_country_code' => $shipment->phone_country_code,
            'shipping_phone' => $shipment->phone,
            'shipping_postal_code' => $shipment->postal_code,
            'shipping_source_address_id' => $shipment->shipping_source_address_id,
        ], $this->legacyPrimaryShippingSelection($shipment)));
    }

    private function syncLegacyPrimaryShippingSelection(FlowJob $job, OrderShipment $shipment): void
    {
        $job->update($this->legacyPrimaryShippingSelection($shipment));
    }

    /** @return array{shipment_method_ids:array<int,int>,shipment_urgency_ids:array<int,int>} */
    private function legacyPrimaryShippingSelection(OrderShipment $shipment): array
    {
        return [
            'shipment_method_ids' => $shipment->shipment_method_id ? [(int) $shipment->shipment_method_id] : [],
            'shipment_urgency_ids' => $shipment->shipment_urgency_id ? [(int) $shipment->shipment_urgency_id] : [],
        ];
    }

    private function legacyShippingAddressText(OrderShipment $shipment): ?string
    {
        $street = trim((string) $shipment->address);
        $locality = collect([
            trim((string) $shipment->city),
            trim((string) $shipment->state),
            trim((string) $shipment->postal_code),
        ])->filter()->implode(', ');
        $country = trim((string) $shipment->country);

        $address = collect([$street, $locality, $country])
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");

        return $address === '' ? null : $address;
    }

    private function resequence(int $jobId): void
    {
        $shipments = OrderShipment::query()->where('flow_job_id', $jobId)->orderBy('sequence')->orderBy('id')->get();
        foreach ($shipments as $offset => $shipment) {
            $shipment->update(['sequence' => 1000 + $offset + 1]);
        }
        foreach ($shipments->values() as $offset => $shipment) {
            $shipment->update(['sequence' => $offset + 1, 'is_primary' => $offset === 0]);
        }
    }

    private function assertShipmentTask(Task $task, string $key): void
    {
        abort_unless(app(OrderWorkflowActionService::class)->automationKey($task) === $key, 422, 'This action does not belong to the expected Shipment task.');
        abort_if(strcasecmp((string) $task->job?->status, 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');
    }

    private function assertBelongsToTask(Task $task, OrderShipment $shipment): void
    {
        abort_unless((int) $shipment->flow_job_id === (int) $task->flow_job_id, 422, 'This shipment does not belong to the Order.');
    }

    private function normalizeAddressMode(mixed $mode): string
    {
        $mode = trim((string) $mode);
        return $mode === self::MODE_MULTIPLE_ADDRESS ? self::MODE_MULTIPLE_ADDRESS : self::MODE_SAME_ADDRESS;
    }

    private function normalizeUrgencyForMethod(?int $methodId, ?int $urgencyId): ?int
    {
        if (! $methodId || ! $urgencyId) return null;
        $method = MasterRecord::query()->find($methodId);
        return $method && CreateOrderShippingMethodPresenter::methodKind($method) === 'express' ? $urgencyId : null;
    }

    private function resolveCourierIdByName(mixed $name): ?int
    {
        $name = trim((string) ($name ?? ''));
        if ($name === '') return null;

        return MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('courier')
            ->active()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');
    }

    private function firstValidMasterId(array $ids, string $type): ?int
    {
        $id = collect($ids)->map(fn ($value) => (int) $value)->first(fn (int $value) => $value > 0);
        if (! $id) return null;

        return MasterRecord::query()->whereKey($id)->where('type', $type)->exists() ? $id : null;
    }

    public static function sameDeliveryAddress(OrderShipment $left, OrderShipment $right): bool
    {
        // Address mode is about the physical destination. Contact person and
        // phone may legitimately differ between packages going to that address.
        foreach (['address', 'city', 'state', 'postal_code', 'country'] as $field) {
            if (mb_strtolower(trim((string) $left->{$field})) !== mb_strtolower(trim((string) $right->{$field}))) return false;
        }
        return true;
    }

    private function isTaskCompleted(Task $task): bool
    {
        return (bool) $task->completed_at || BoardLaneResolver::isCompleted((string) $task->status);
    }

    private function validatedQuantity(mixed $value, string $field = 'shipmentForm.quantity'): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (! preg_match('/^[0-9]+$/', $raw)) {
            throw ValidationException::withMessages([$field => 'Quantity must be a whole number.']);
        }

        $quantity = (int) $raw;
        if ($quantity < 1) {
            throw ValidationException::withMessages([$field => 'Quantity must be at least 1 when provided.']);
        }
        if ($quantity > 2147483647) {
            throw ValidationException::withMessages([$field => 'Quantity is too large.']);
        }

        return $quantity;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function addressText(mixed $address): string
    {
        return collect([
            data_get($address, 'address_line1'),
            data_get($address, 'suite'),
            data_get($address, 'city'),
            data_get($address, 'state'),
            data_get($address, 'zip'),
            data_get($address, 'country'),
        ])->map(fn ($part) => trim((string) $part))->filter()->implode(', ');
    }

    /** @return array<string,mixed> */
    private function auditMeta(OrderShipment $shipment): array
    {
        return [
            'shipment_id' => (int) $shipment->id,
            'shipment_sequence' => (int) $shipment->sequence,
            'is_primary' => (bool) $shipment->is_primary,
            'recipient' => (string) ($shipment->recipient ?? ''),
            'address' => (string) ($shipment->address ?? ''),
            'city' => (string) ($shipment->city ?? ''),
            'state' => (string) ($shipment->state ?? ''),
            'postal_code' => (string) ($shipment->postal_code ?? ''),
            'country' => (string) ($shipment->country ?? ''),
            'shipment_method_id' => $shipment->shipment_method_id ? (int) $shipment->shipment_method_id : null,
            'shipment_urgency_id' => $shipment->shipment_urgency_id ? (int) $shipment->shipment_urgency_id : null,
            'courier_id' => $shipment->courier_id ? (int) $shipment->courier_id : null,
            'quantity' => $shipment->quantity !== null ? (int) $shipment->quantity : null,
            'package_reference' => (string) ($shipment->package_reference ?? ''),
            'tracking_number' => (string) ($shipment->tracking_number ?? ''),
        ];
    }

    /** @param array<string,mixed> $meta */
    private function record(FlowJob $job, User $actor, string $event, string $description, array $meta = []): void
    {
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => $event,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }
}
