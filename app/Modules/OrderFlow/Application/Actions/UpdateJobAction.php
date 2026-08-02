<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Application\DTOs\UpdateJobData;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\JobHealthSynchronizer;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobItem;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UpdateJobAction
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly JobHealthSynchronizer $health,
    ) {}

    public function execute(FlowJob $job, UpdateJobData $data, User $actor): FlowJob
    {
        return DB::transaction(function () use ($job, $data, $actor): FlowJob {
            $this->validateReferences($job, $data);
            $items = $this->resolveProductItems($job, $data->productItems);
            $primary = $items->first();
            $previous = [
                'title' => $job->title,
                'owner_id' => $job->owner_id,
                'coordinator_id' => $job->coordinator_id,
                'delivery_date' => $job->delivery_date?->toDateString(),
                'priority_id' => $job->priority_id,
            ];

            $job->update([
                'client_id' => $data->clientId,
                'product_id' => $primary['product_id'] ?? null,
                'product_category_id' => $primary['product_category_id'] ?? null,
                'currency_id' => $data->currencyId ?? $job->currency_id,
                'priority_id' => $data->priorityId,
                'owner_id' => $data->ownerId ?: $actor->id,
                'coordinator_id' => $data->coordinatorId ?: $actor->id,
                'title' => $data->title,
                'request_description' => blank($data->requestDescription) ? null : trim($data->requestDescription),
                'quantity' => (int) $items->sum('quantity'),
                'quoted_amount' => $data->quotedAmount ?? $job->quoted_amount,
                'delivery_date' => $data->deliveryDate,
            ]);

            $job->items()->delete();
            foreach ($items as $index => $item) {
                FlowJobItem::create([
                    'flow_job_id' => $job->id,
                    'product_id' => $item['product_id'],
                    'product_category_id' => $item['product_category_id'],
                    'quantity' => $item['quantity'],
                    'sort_order' => $index,
                ]);
            }

            $this->synchronizeManagerMembers($job);
            $this->activity->log(
                $job->workspace_id,
                $actor,
                $job,
                'job.updated',
                "Job {$job->code} details updated",
                ['before' => $previous, 'after' => [
                    'title' => $job->title,
                    'owner_id' => $job->owner_id,
                    'coordinator_id' => $job->coordinator_id,
                    'delivery_date' => $job->delivery_date?->toDateString(),
                    'priority_id' => $job->priority_id,
                    'product_lines' => $items->count(),
                ]],
            );

            $this->health->synchronize($job->fresh(), $actor, 'job_update');

            return $job->fresh([
                'client', 'workflow', 'currentPhase', 'startedPhase', 'product', 'productCategory',
                'items.product', 'items.productCategory', 'currency', 'priority', 'owner', 'coordinator',
            ]);
        });
    }

    /** @return Collection<int, array{product_id:int, product_category_id:int, quantity:int}> */
    private function resolveProductItems(FlowJob $job, array $submitted): Collection
    {
        $items = collect($submitted)->map(fn (array $item): array => [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'product_category_id' => (int) ($item['product_category_id'] ?? 0),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
        ])->values();

        if ($items->isEmpty()) throw new DomainException('Add at least one product to the Job.');
        if ($items->pluck('product_id')->duplicates()->isNotEmpty()) throw new DomainException('The same product cannot be added more than once to a Job.');

        $products = MasterRecord::query()
            ->where('workspace_id', $job->workspace_id)
            ->where('type', MasterDataType::Product->value)
            ->where('status', 'active')
            ->whereIn('id', $items->pluck('product_id'))
            ->get(['id', 'parent_id'])
            ->keyBy('id');

        $categories = MasterRecord::query()
            ->where('workspace_id', $job->workspace_id)
            ->where('type', MasterDataType::ProductCategory->value)
            ->where('status', 'active')
            ->whereIn('id', $items->pluck('product_category_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($products->count() !== $items->count() || count(array_unique($categories)) !== $items->pluck('product_category_id')->unique()->count()) {
            throw new DomainException('One or more selected products or categories are invalid for this workspace.');
        }

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            if (! $product || (int) $product->parent_id !== (int) $item['product_category_id']) {
                throw new DomainException('A selected product does not belong to the chosen product category.');
            }
        }

        return $items;
    }

    private function validateReferences(FlowJob $job, UpdateJobData $data): void
    {
        if (! Client::query()->whereKey($data->clientId)->where('workspace_id', $job->workspace_id)->where('status', 'active')->exists()) {
            throw new DomainException('The selected client is not available in this workspace.');
        }

        foreach ([[$data->currencyId, MasterDataType::Currency], [$data->priorityId, MasterDataType::Priority]] as [$id, $type]) {
            if ($id === null) continue;
            if (! MasterRecord::query()->whereKey($id)->where('workspace_id', $job->workspace_id)->where('type', $type->value)->where('status', 'active')->exists()) {
                throw new DomainException("The selected {$type->name} record is invalid for this workspace.");
            }
        }

        $userIds = collect([$data->ownerId, $data->coordinatorId])->filter()->unique();
        if ($userIds->isNotEmpty()) {
            $count = WorkspaceMembership::query()
                ->where('workspace_id', $job->workspace_id)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                ->whereHas('role', fn ($query) => $query->where('is_active', true))
                ->whereHas('workspace', fn ($query) => $query->where('is_active', true))
                ->whereIn('user_id', $userIds)
                ->count();
            if ($count !== $userIds->count()) throw new DomainException('Every assigned user must be an active member of this workspace.');
        }
    }

    private function synchronizeManagerMembers(FlowJob $job): void
    {
        $managerIds = collect([$job->created_by, $job->owner_id, $job->coordinator_id])->filter()->map(fn ($id) => (int) $id)->unique();
        foreach ($managerIds as $memberId) {
            FlowJobMember::updateOrCreate(
                ['flow_job_id' => $job->id, 'user_id' => $memberId],
                [
                    'access_level' => 'manager',
                    'can_manage_tasks' => true,
                    'can_upload_documents' => true,
                    'can_view_financials' => $memberId === (int) $job->owner_id,
                ],
            );
        }

        $job->members()->whereNotIn('user_id', $managerIds)->update([
            'access_level' => 'member',
            'can_manage_tasks' => false,
            'can_view_financials' => false,
        ]);
    }
}
