<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\UpdateOrderCoordinator;
use App\Actions\Orders\UpdateOrderDeliveryDate;
use App\Actions\Orders\UpdateOrderOverview;
use App\Actions\Orders\UpdateOrderOwner;
use App\Actions\Orders\UpdateOrderPriority;
use App\Actions\Orders\UpdateOrderShippingDetails;
use App\Actions\Orders\UpdateOrderTextField;
use App\Actions\Orders\UpdateOrderUrgencies;
use App\Actions\Orders\UpdateOrderShippingSelection;
use App\Actions\Orders\AutoAdvanceOrder;
use App\Queries\Orders\VisibleOrderQuery;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Services\MasterDataService;
use App\Support\CreateOrderShippingMethodPresenter;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderDetail
{
    #[Json]
    public function updateJobUrgencies(int $jobId, string $type, array $ids): array
    {
        $config = match ($type) {
            'production' => ['masterType' => 'production_urgency', 'field' => 'production_urgency_ids', 'label' => 'production urgency'],
            'shipment' => ['masterType' => 'shipment_urgency', 'field' => 'shipment_urgency_ids', 'label' => 'shipment urgency'],
            default => null,
        };

        if (!$config) {
            return ['ok' => false, 'message' => 'That urgency field is not available.'];
        }

        return $this->persistInlineEdit($config['label'], function () use ($jobId, $ids, $config) {
            $ids = collect($ids)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
            abort_if(count($ids) > 1, 422, 'Select only one '.$config['label'].'.');
            $workspaceId = app(MasterDataService::class)->workspaceId();

            if ($ids) {
                $validIds = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType($config['masterType'])
                    ->active()
                    ->whereIn('id', $ids)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                abort_if(count($validIds) !== count($ids), 422, 'One or more selected urgency options are no longer available.');
                $ids = $validIds;
            }

            $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $jobId);
            app(UpdateOrderUrgencies::class)->handle($job, $config['field'], $ids, auth()->user());
        });
    }

    #[Json]
    public function updateJobShippingSelection(int $jobId, string $selection): array
    {
        $parsed = CreateOrderShippingMethodPresenter::parseSelectionValue($selection);
        $methodId = (int) ($parsed['method_id'] ?? 0);
        $urgencyId = $parsed['urgency_id'] ?? null;

        if ($methodId <= 0) {
            return ['ok' => false, 'message' => 'Select a shipping method.'];
        }

        $saved = null;
        $result = $this->persistInlineEdit('shipment method / urgency', function () use ($jobId, $methodId, $urgencyId, &$saved) {
            $workspaceId = app(MasterDataService::class)->workspaceId();
            $method = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('shipment_method')
                ->active()
                ->find($methodId);
            abort_unless($method, 422, 'The selected shipping method is no longer available.');

            if ($urgencyId) {
                $validUrgency = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('shipment_urgency')
                    ->active()
                    ->whereKey((int) $urgencyId)
                    ->exists();
                abort_unless($validUrgency, 422, 'The selected shipment urgency is no longer available.');
            }

            $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $jobId);
            $saved = app(UpdateOrderShippingSelection::class)->handle(
                $job,
                $methodId,
                $urgencyId ? (int) $urgencyId : null,
                auth()->user(),
            );
        });

        if ($result['ok'] ?? false) {
            $methods = app(MasterDataService::class)->active('shipment_method');
            $urgencies = app(MasterDataService::class)->active('shipment_urgency');
            $state = CreateOrderShippingMethodPresenter::orderShippingState(
                $methods,
                $urgencies,
                (array) ($saved?->shipment_method_ids ?? []),
                (array) ($saved?->shipment_urgency_ids ?? []),
            );
            $result['value'] = $state['value'];
            $result['display'] = $state['name'];
            $result['tone'] = $state['tone'];

            // Task 5.1 is isolated in its own Livewire component. Tell that
            // child to rerender so a Planning-side change is visible in the
            // Shipment stage immediately without a browser refresh.
            $this->dispatch('order-shipping-selection-updated', orderId: $jobId);
        }

        return $result;
    }

    #[Json]
    public function updateJobOwner(int $jobId, mixed $ownerId): array
    {
        $owner = null;
        $result = $this->persistInlineEdit('Order owner', function () use ($jobId, $ownerId, &$owner) {
            $ownerId = $ownerId === '' ? null : (int) $ownerId;
            $owner = app(UpdateOrderOwner::class)->handle(auth()->user(), $jobId, $ownerId);
        });

        if ($result['ok'] ?? false) {
            // Return the canonical saved owner so every visible owner control can
            // synchronize immediately without forcing a full Livewire re-render.
            $result['value'] = $owner ? (string) $owner->id : '';
            $result['display'] = $owner?->name ?? 'Unassigned';
            $result['avatarUrl'] = $owner?->profileImageUrl() ?? '';
        }

        return $result;
    }

    #[Json]
    public function updateJobCoordinator(int $jobId, mixed $coordinatorId): array
    {
        return $this->persistInlineEdit('Order coordinator', function () use ($jobId, $coordinatorId) {
            $coordinatorId = $coordinatorId === '' ? null : (int) $coordinatorId;
            app(UpdateOrderCoordinator::class)->handle(auth()->user(), $jobId, $coordinatorId);
        });
    }

    #[Json]
    public function updateJobDeliveryDate(int $jobId, mixed $date): array
    {
        return $this->persistInlineEdit('Hand Date', function () use ($jobId, $date) {
            app(UpdateOrderDeliveryDate::class)->handle(auth()->user(), $jobId, (string) $date);
        });
    }

    #[Json]
    public function updateJobPriority(int $jobId, mixed $priority): array
    {
        return $this->persistInlineEdit('priority', function () use ($jobId, $priority) {
            app(UpdateOrderPriority::class)->handle(auth()->user(), $jobId, (string) $priority);
        });
    }

    #[Json]
    public function updateJobShippingField(int $jobId, string $field, mixed $value): array
    {
        $labels = [
            'shipping_address' => 'shipping address',
            'shipping_postal_code' => 'shipping postal code',
        ];
        abort_unless(array_key_exists($field, $labels), 422, 'This shipping field cannot be edited inline.');

        return $this->persistInlineEdit($labels[$field], function () use ($jobId, $field, $value) {
            app(UpdateOrderShippingDetails::class)->handle(auth()->user(), $jobId, [$field => $value]);
        });
    }

    #[Json]
    public function updateJobShippingPhone(int $jobId, mixed $countryCode, mixed $phone): array
    {
        return $this->persistInlineEdit('shipping phone number', function () use ($jobId, $countryCode, $phone) {
            app(UpdateOrderShippingDetails::class)->handle(auth()->user(), $jobId, [
                'shipping_phone_country_code' => $countryCode,
                'shipping_phone' => $phone,
            ]);
        });
    }

    #[Json]
    public function updateJobOverviewDetails(int $jobId, mixed $title, mixed $description): array
    {
        return $this->persistInlineEdit('Order overview', function () use ($jobId, $title, $description) {
            app(UpdateOrderOverview::class)->handle(auth()->user(), $jobId, (string) $title, (string) $description);
        });
    }

    #[Json]
    public function updateJobShippingDetails(int $jobId, mixed $address, mixed $countryCode, mixed $phone, mixed $postalCode): array
    {
        return $this->persistInlineEdit('shipping details', function () use ($jobId, $address, $countryCode, $phone, $postalCode) {
            app(UpdateOrderShippingDetails::class)->handle(auth()->user(), $jobId, [
                'shipping_address' => $address,
                'shipping_phone_country_code' => $countryCode,
                'shipping_phone' => $phone,
                'shipping_postal_code' => $postalCode,
            ]);
        });
    }

    #[Json]
    public function updateJobTextField(int $jobId, string $field, mixed $value): array
    {
        $label = $field === 'title' ? 'Order name' : 'Order description';
        $updatedJob = null;

        $result = $this->persistInlineEdit($label, function () use ($jobId, $field, $value, &$updatedJob) {
            $updatedJob = app(UpdateOrderTextField::class)->handle(auth()->user(), $jobId, $field, (string) $value);
        });

        if (($result['ok'] ?? false) && $updatedJob) {
            $result['value'] = (string) ($updatedJob->{$field} ?? '');

            if ($field === 'description') {
                $result['displayHtml'] = app(\App\Services\MentionService::class)
                    ->render($result['value']);
            }
        }

        return $result;
    }

    private function prepareSelectedJob(int $id): void
    {
        // Order Details now treats the first request as a read-only shell load.
        // Workflow reconciliation, artwork self-healing and auto-advance are
        // performed by the isolated Workflow component immediately before that
        // section is rendered. This removes maintenance work from the critical
        // click -> first-paint path while preserving the same workflow safety
        // before users can interact with workflow tasks.
        app(VisibleOrderQuery::class)->scoped(
            auth()->user(),
            $id,
            [],
            ['id'],
        );
    }

    private function setDefaultDocumentTask(?FlowJob $job = null): void
    {
        if (!$this->selectedJobId && !$job) return;

        if (!$job) {
            $user = auth()->user();
            $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
            app(VisibleOrderQuery::class)->loadTab($job, $user, 'documents');
        } elseif (!$job->relationLoaded('tasks')) {
            app(VisibleOrderQuery::class)->loadTab($job, auth()->user(), 'documents');
        }

        $valid = $job->tasks->first(fn ($task) => (int) $task->id === (int) $this->jobDocumentTaskId && ($task->document_category_id || $task->setupTemplate?->document_category_id));
        if ($valid) return;

        $task = $job->tasks
            ->filter(fn ($task) => $task->document_category_id || $task->setupTemplate?->document_category_id)
            ->sortBy(fn ($task) => [
                (int) ($task->workflow_phase_id === $job->workflow_phase_id ? 0 : 1),
                (int) ($task->phase?->sequence ?? 999),
                (int) ($task->setupTemplate?->sort_order ?? 999),
            ])->first();
        $this->jobDocumentTaskId = $task?->id;
    }

}
