<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FlowAuthorizationService
{
    public function isSuperAdmin(User $user): bool
    {
        return (bool) $user->is_super_admin;
    }

    public function isAdministrator(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $code = strtoupper((string) $user->currentWorkspaceMembership()?->role?->code);

        return in_array($code, config('flowtrack.administrator_role_codes', []), true);
    }

    public function allows(User $user, string $module, string $action, ?Model $record = null): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        $membership = $user->currentWorkspaceMembership();
        if (! $membership || $membership->status !== 'active' || ! $membership->role?->is_active) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        $access = $membership->role->moduleAccess->firstWhere('module_code', $module);
        if (! $access || ! $this->actionAllowed($access->actions ?? [], $action)) {
            return false;
        }

        if ($record === null) {
            return $access->record_scope !== 'none';
        }

        return $this->recordWithinScope($user, $record, (string) $access->record_scope);
    }

    public function scope(User $user, string $module): string
    {
        if ($this->isAdministrator($user)) {
            return 'all_records';
        }

        return (string) ($user->currentWorkspaceMembership()?->role?->moduleAccess
            ?->firstWhere('module_code', $module)?->record_scope ?? 'none');
    }

    public function canViewSensitiveField(User $user, string $field): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }

        return in_array($field, $user->currentWorkspaceMembership()?->role?->sensitive_fields ?? [], true);
    }

    public function applyJobVisibility(Builder $query, User $user, string $module = 'jobs'): Builder
    {
        $query->where('workspace_id', $user->currentWorkspaceId());

        if (! $this->allows($user, $module, 'view')) {
            return $query->whereRaw('1 = 0');
        }

        return match ($this->scope($user, $module)) {
            'all_records' => $query,
            'own_records' => $query->where(fn (Builder $builder) => $builder
                ->where('owner_id', $user->id)
                ->orWhere('coordinator_id', $user->id)),
            'selected_clients' => $query->where(fn (Builder $builder) => $builder
                ->whereHas('client', fn (Builder $client) => $client->where('account_manager_id', $user->id))
                ->orWhere(fn (Builder $assigned) => $this->assignedJobConditions($assigned, $user))),
            'department' => $this->applyDepartmentJobVisibility($query, $user),
            'assigned_jobs' => $query->where(fn (Builder $builder) => $this->assignedJobConditions($builder, $user)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function applyTaskVisibility(Builder $query, User $user): Builder
    {
        if (! $this->allows($user, 'tasks', 'view')) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('job', fn (Builder $job) => $this->applyJobVisibility($job, $user, 'tasks'));

        if ($this->scope($user, 'tasks') === 'own_records') {
            $query->where(fn (Builder $task) => $task
                ->where('assignee_id', $user->id)
                ->orWhere('created_by', $user->id));
        }

        return $query;
    }

    public function applyDocumentVisibility(Builder $query, User $user): Builder
    {
        $query->where('workspace_id', $user->currentWorkspaceId());

        if (! $this->allows($user, 'documents', 'view')) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('job', fn (Builder $job) => $this->applyJobVisibility($job, $user, 'documents'));

        if ($this->scope($user, 'documents') === 'own_records') {
            $query->where('uploaded_by', $user->id);
        }

        return $query;
    }

    public function applyClientVisibility(Builder $query, User $user): Builder
    {
        $query->where('workspace_id', $user->currentWorkspaceId());

        if (! $this->allows($user, 'clients', 'view')) {
            return $query->whereRaw('1 = 0');
        }

        return match ($this->scope($user, 'clients')) {
            'all_records' => $query,
            'own_records', 'selected_clients' => $query->where(fn (Builder $builder) => $builder
                ->where('account_manager_id', $user->id)
                ->orWhereHas('jobs', fn (Builder $job) => $job->where(fn (Builder $assigned) => $this->assignedJobConditions($assigned, $user)))),
            'department' => $query->whereHas('accountManager.workspaceMemberships', fn (Builder $membership) => $membership
                ->where('workspace_id', $user->currentWorkspaceId())
                ->where('department_id', $user->currentWorkspaceMembership()?->department_id)),
            'assigned_jobs' => $query->whereHas('jobs', fn (Builder $job) => $job->where(fn (Builder $assigned) => $this->assignedJobConditions($assigned, $user))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function actionAllowed(array $actions, string $requested): bool
    {
        if (in_array('manage', $actions, true)) {
            return true;
        }

        if ($requested === 'edit_own' && in_array('edit_all', $actions, true)) {
            return true;
        }

        return in_array($requested, $actions, true);
    }

    private function recordWithinScope(User $user, Model $record, string $scope): bool
    {
        if ($scope === 'all_records') {
            return true;
        }
        if ($scope === 'none') {
            return false;
        }

        if ($record instanceof FlowTask) {
            if ($scope === 'own_records') {
                return $record->assignee_id === $user->id || $record->created_by === $user->id;
            }

            return $this->recordWithinScope($user, $record->job, $scope);
        }

        if ($record instanceof FlowDocument) {
            if ($scope === 'own_records') {
                return $record->uploaded_by === $user->id;
            }

            return $this->recordWithinScope($user, $record->job, $scope);
        }

        if ($record instanceof Client) {
            return match ($scope) {
                'own_records', 'selected_clients' => $record->account_manager_id === $user->id
                    || $record->jobs()->where(fn (Builder $job) => $job->where(fn (Builder $assigned) => $this->assignedJobConditions($assigned, $user)))->exists(),
                'assigned_jobs' => $record->jobs()->where(fn (Builder $job) => $job->where(fn (Builder $assigned) => $this->assignedJobConditions($assigned, $user)))->exists(),
                'department' => $record->accountManager?->workspaceMemberships()
                    ->where('workspace_id', $user->currentWorkspaceId())
                    ->where('department_id', $user->currentWorkspaceMembership()?->department_id)
                    ->exists() ?? false,
                default => false,
            };
        }

        if ($record instanceof FlowJob) {
            return match ($scope) {
                'own_records' => $record->owner_id === $user->id || $record->coordinator_id === $user->id,
                'assigned_jobs' => $this->isAssignedToJob($user, $record),
                'selected_clients' => $record->client?->account_manager_id === $user->id || $this->isAssignedToJob($user, $record),
                'department' => $this->sameDepartmentAsJobOwner($user, $record),
                default => false,
            };
        }

        return false;
    }

    private function assignedJobConditions(Builder $builder, User $user): void
    {
        $builder->where('owner_id', $user->id)
            ->orWhere('coordinator_id', $user->id)
            ->orWhereHas('members', fn (Builder $member) => $member->where('user_id', $user->id))
            ->orWhereHas('tasks', fn (Builder $task) => $task->where('assignee_id', $user->id));
    }

    private function isAssignedToJob(User $user, FlowJob $job): bool
    {
        return $job->owner_id === $user->id
            || $job->coordinator_id === $user->id
            || $job->members()->where('user_id', $user->id)->exists()
            || $job->tasks()->where('assignee_id', $user->id)->exists();
    }

    private function applyDepartmentJobVisibility(Builder $query, User $user): Builder
    {
        $departmentId = $user->currentWorkspaceMembership()?->department_id;
        if (! $departmentId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $builder) => $builder
            ->whereHas('owner.workspaceMemberships', fn (Builder $membership) => $membership
                ->where('workspace_id', $user->currentWorkspaceId())->where('department_id', $departmentId))
            ->orWhereHas('coordinator.workspaceMemberships', fn (Builder $membership) => $membership
                ->where('workspace_id', $user->currentWorkspaceId())->where('department_id', $departmentId)));
    }

    private function sameDepartmentAsJobOwner(User $user, FlowJob $job): bool
    {
        $departmentId = $user->currentWorkspaceMembership()?->department_id;
        if (! $departmentId) {
            return false;
        }

        return ($job->owner?->workspaceMemberships()->where('workspace_id', $user->currentWorkspaceId())->where('department_id', $departmentId)->exists() ?? false)
            || ($job->coordinator?->workspaceMemberships()->where('workspace_id', $user->currentWorkspaceId())->where('department_id', $departmentId)->exists() ?? false);
    }
}
