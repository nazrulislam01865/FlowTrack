<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Business\Services\WorkflowEngine;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobItem;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateJobAction
{
    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly WorkflowEngine $workflowEngine,
        private readonly WorkflowAutomationService $automation,
    ) {}

    public function execute(CreateJobData $data, User $actor): FlowJob
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->validateWorkspaceReferences($data);
            $productItems = $this->resolveProductItems($data);

            $workflow = WorkflowTemplate::query()
                ->where('workspace_id', $data->workspaceId)
                ->with(['phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence')])
                ->findOrFail($data->workflowTemplateId);

            $phase = WorkflowPhase::query()
                ->where('workflow_template_id', $workflow->id)
                ->where('is_active', true)
                ->findOrFail($data->startPhaseId);

            if ($phase->sequence > 1 && ! $data->startReason) {
                throw new DomainException('A start reason is required when a job begins after the first workflow phase.');
            }

            $primaryItem = $productItems->first();
            $totalQuantity = $productItems->isNotEmpty()
                ? $productItems->sum('quantity')
                : max(1, $data->quantity);

            $job = FlowJob::create([
                'workspace_id' => $data->workspaceId,
                'client_id' => $data->clientId,
                'workflow_template_id' => $workflow->id,
                // These legacy summary fields remain synchronized for reports,
                // filters and integrations that still expect one primary product.
                'product_id' => $primaryItem['product_id'] ?? $data->productId,
                'product_category_id' => $primaryItem['product_category_id'] ?? $data->productCategoryId,
                'currency_id' => $data->currencyId,
                'priority_id' => $data->priorityId,
                'owner_id' => $data->ownerId ?: $actor->id,
                'coordinator_id' => $data->coordinatorId ?: $actor->id,
                'code' => $this->identifiers->job(),
                'order_code' => $phase->sequence >= 5 ? $this->identifiers->order() : null,
                'title' => $data->title,
                'quantity' => $totalQuantity,
                'quoted_amount' => $data->quotedAmount,
                'delivery_date' => $data->deliveryDate,
                'status' => $data->draft ? 'draft' : 'new',
                'health' => 'on_track',
                'next_action' => $phase->entry_condition,
                'milestone' => $phase->exit_condition,
            ]);

            foreach ($productItems as $index => $item) {
                FlowJobItem::create([
                    'flow_job_id' => $job->id,
                    'product_id' => $item['product_id'],
                    'product_category_id' => $item['product_category_id'],
                    'quantity' => $item['quantity'],
                    'sort_order' => $index,
                ]);
            }

            $memberIds = collect($data->memberIds)
                ->push($job->owner_id)
                ->push($job->coordinator_id)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique();

            foreach ($memberIds as $memberId) {
                FlowJobMember::create([
                    'flow_job_id' => $job->id,
                    'user_id' => $memberId,
                    'access_level' => in_array($memberId, [$job->owner_id, $job->coordinator_id], true) ? 'manager' : 'member',
                    'can_manage_tasks' => in_array($memberId, [$job->owner_id, $job->coordinator_id], true),
                    'can_upload_documents' => true,
                    'can_view_financials' => $memberId === $job->owner_id,
                ]);
            }

            $job->setRelation('workflow', $workflow);
            $this->workflowEngine->initialize($job, $phase, $actor, $data->startReason);
            $this->automation->synchronize($job, $actor, 'job_creation');

            return $job->fresh(['client', 'workflow', 'currentPhase', 'items.product', 'items.productCategory']);
        });
    }

    /**
     * @return Collection<int, array{product_id:int, product_category_id:?int, quantity:int}>
     */
    private function resolveProductItems(CreateJobData $data): Collection
    {
        $items = collect($data->productItems)
            ->map(fn (array $item): array => [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'product_category_id' => filled($item['product_category_id'] ?? null)
                    ? (int) $item['product_category_id']
                    : null,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            ])
            ->filter(fn (array $item): bool => $item['product_id'] > 0)
            ->values();

        if ($items->isEmpty() && $data->productId !== null) {
            $items->push([
                'product_id' => $data->productId,
                'product_category_id' => $data->productCategoryId,
                'quantity' => max(1, $data->quantity),
            ]);
        }

        if ($items->pluck('product_id')->duplicates()->isNotEmpty()) {
            throw new DomainException('The same product cannot be added more than once to a Job.');
        }

        if ($items->isEmpty()) {
            return $items;
        }

        $products = MasterRecord::query()
            ->where('workspace_id', $data->workspaceId)
            ->where('type', MasterDataType::Product->value)
            ->where('status', 'active')
            ->whereIn('id', $items->pluck('product_id'))
            ->get(['id', 'parent_id'])
            ->keyBy('id');

        if ($products->count() !== $items->count()) {
            throw new DomainException('One or more selected products are invalid for this workspace.');
        }

        $categoryIds = $items
            ->map(fn (array $item) => $item['product_category_id'] ?: $products[$item['product_id']]->parent_id)
            ->filter()
            ->unique()
            ->values();

        if ($categoryIds->isNotEmpty()) {
            $validCategoryIds = MasterRecord::query()
                ->where('workspace_id', $data->workspaceId)
                ->where('type', MasterDataType::ProductCategory->value)
                ->where('status', 'active')
                ->whereIn('id', $categoryIds)
                ->pluck('id');

            if ($validCategoryIds->count() !== $categoryIds->count()) {
                throw new DomainException('One or more selected product categories are invalid for this workspace.');
            }
        }

        return $items->map(function (array $item) use ($products): array {
            $product = $products[$item['product_id']];
            $categoryId = $item['product_category_id'] ?: $product->parent_id;

            if ($categoryId !== null && $product->parent_id !== null && (int) $categoryId !== (int) $product->parent_id) {
                throw new DomainException('A selected product does not belong to the chosen product category.');
            }

            return [
                'product_id' => $item['product_id'],
                'product_category_id' => $categoryId ? (int) $categoryId : null,
                'quantity' => $item['quantity'],
            ];
        })->values();
    }

    private function validateWorkspaceReferences(CreateJobData $data): void
    {
        if (! Client::query()->whereKey($data->clientId)->where('workspace_id', $data->workspaceId)->where('status', 'active')->exists()) {
            throw new DomainException('The selected client is not available in this workspace.');
        }

        $masterReferences = [
            [$data->currencyId, MasterDataType::Currency],
            [$data->priorityId, MasterDataType::Priority],
        ];

        foreach ($masterReferences as [$id, $type]) {
            if ($id === null) {
                continue;
            }

            $valid = MasterRecord::query()
                ->whereKey($id)
                ->where('workspace_id', $data->workspaceId)
                ->where('type', $type->value)
                ->where('status', 'active')
                ->exists();

            if (! $valid) {
                throw new DomainException("The selected {$type->name} record is invalid for this workspace.");
            }
        }

        $userIds = collect($data->memberIds)
            ->push($data->ownerId)
            ->push($data->coordinatorId)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($userIds->isNotEmpty()) {
            $validCount = WorkspaceMembership::query()
                ->where('workspace_id', $data->workspaceId)
                ->where('status', 'active')
                ->whereIn('user_id', $userIds)
                ->distinct('user_id')
                ->count('user_id');

            if ($validCount !== $userIds->count()) {
                throw new DomainException('Every assigned user must be an active member of this workspace.');
            }
        }
    }
}
