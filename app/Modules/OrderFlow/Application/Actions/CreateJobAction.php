<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Business\Services\WorkflowEngine;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateJobAction
{
    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly WorkflowEngine $workflowEngine,
    ) {}

    public function execute(CreateJobData $data, User $actor): FlowJob
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->validateWorkspaceReferences($data);

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

            $job = FlowJob::create([
                'workspace_id' => $data->workspaceId,
                'client_id' => $data->clientId,
                'workflow_template_id' => $workflow->id,
                'product_id' => $data->productId,
                'product_category_id' => $data->productCategoryId,
                'currency_id' => $data->currencyId,
                'priority_id' => $data->priorityId,
                'owner_id' => $data->ownerId ?: $actor->id,
                'coordinator_id' => $data->coordinatorId ?: $actor->id,
                'code' => $this->identifiers->job(),
                'order_code' => $phase->sequence >= 5 ? $this->identifiers->order() : null,
                'title' => $data->title,
                'quantity' => $data->quantity,
                'quoted_amount' => $data->quotedAmount,
                'delivery_date' => $data->deliveryDate,
                'status' => $data->draft ? 'draft' : 'new',
                'health' => 'on_track',
                'next_action' => $phase->entry_condition,
                'milestone' => $phase->exit_condition,
            ]);

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

            return $job->fresh(['client', 'workflow', 'currentPhase']);
        });
    }

    private function validateWorkspaceReferences(CreateJobData $data): void
    {
        if (! Client::query()->whereKey($data->clientId)->where('workspace_id', $data->workspaceId)->where('status', 'active')->exists()) {
            throw new DomainException('The selected client is not available in this workspace.');
        }

        $masterReferences = [
            [$data->productId, MasterDataType::Product],
            [$data->productCategoryId, MasterDataType::ProductCategory],
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
