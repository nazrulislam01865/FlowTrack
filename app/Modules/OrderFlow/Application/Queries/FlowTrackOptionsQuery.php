<?php

namespace App\Modules\OrderFlow\Application\Queries;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use App\Modules\OrderFlow\Data\Repositories\MasterDataRepository;

class FlowTrackOptionsQuery
{
    public function __construct(
        private readonly MasterDataRepository $masterData,
        private readonly FlowAuthorizationService $authorization,
    ) {
    }

    public function forWorkspace(User $user): array
    {
        $workspaceId = $user->currentWorkspaceId();
        $types = array_map(fn (MasterDataType $type) => $type->value, MasterDataType::cases());
        $canAssignPeople = $this->authorization->allows($user, 'jobs', 'assign')
            || $this->authorization->allows($user, 'tasks', 'assign')
            || $this->authorization->allows($user, 'clients', 'assign')
            || $this->authorization->allows($user, 'users', 'view');
        $canUseClients = $this->authorization->allows($user, 'clients', 'view')
            || $this->authorization->allows($user, 'jobs', 'create');
        $canUseWorkflows = $this->authorization->allows($user, 'workflow', 'view')
            || $this->authorization->allows($user, 'jobs', 'create');
        $canUseMaster = $this->authorization->allows($user, 'masterdata', 'view')
            || $this->authorization->allows($user, 'jobs', 'create')
            || $this->authorization->allows($user, 'documents', 'create')
            || $this->authorization->allows($user, 'workflow', 'view');

        return [
            'clients' => $canUseClients
                ? $this->authorization->applyClientVisibility(Client::query()->where('status', 'active'), $user)
                    ->orderBy('name')->get(['id', 'code', 'name'])
                : collect(),
            'users' => $canAssignPeople
                ? User::query()->whereHas('workspaceMemberships', fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'active'))
                    ->orderBy('name')->get(['id', 'name', 'initials'])
                : User::query()->whereKey($user->id)->get(['id', 'name', 'initials']),
            'workflows' => $canUseWorkflows
                ? WorkflowTemplate::query()->where('workspace_id', $workspaceId)->where('is_active', true)
                    ->with(['phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence')])
                    ->orderBy('name')->get()
                : collect(),
            'master' => $canUseMaster ? $this->masterData->grouped($workspaceId, $types) : collect(),
        ];
    }
}
