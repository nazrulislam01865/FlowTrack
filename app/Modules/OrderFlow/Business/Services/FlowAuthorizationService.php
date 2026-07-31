<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FlowAuthorizationService
{
    /** @var array<string, bool> */
    private array $jobAssignmentCache = [];

    /** @var array<string, bool> */
    private array $jobTaskManagementCache = [];

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

        if ($this->isAdministrator($user)) {
            return $query;
        }

        // Job access is always assignment-based for non-administrators. A broad
        // role scope must never reveal Jobs that do not include the user.
        return $query->where(fn (Builder $builder) => $this->assignedJobConditions($builder, $user));
    }

    public function applyTaskVisibility(Builder $query, User $user): Builder
    {
        if (! $this->allows($user, 'tasks', 'view')) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('job', function (Builder $job) use ($user): void {
            $job->where('workspace_id', $user->currentWorkspaceId());

            if (! $this->isAdministrator($user)) {
                $job->where(fn (Builder $assigned) => $this->assignedJobConditions($assigned, $user));
            }
        });

        return $query;
    }

    public function applyTaskBoardVisibility(Builder $query, User $user): Builder
    {
        return $this->applyTaskVisibility($query, $user);
    }

    public function applyJobBoardVisibility(Builder $query, User $user): Builder
    {
        $query->where('workspace_id', $user->currentWorkspaceId());

        if (! $this->allows($user, 'jobs', 'view')) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isAdministrator($user)) {
            return $query;
        }

        return $query->where(fn (Builder $builder) => $this->assignedJobConditions($builder, $user));
    }

    public function canViewTask(User $user, FlowTask $task): bool
    {
        if ($this->isAdministrator($user)) {
            return $task->job?->workspace_id === $user->currentWorkspaceId();
        }

        return $this->allows($user, 'tasks', 'view')
            && $task->job?->workspace_id === $user->currentWorkspaceId()
            && $task->job
            && $this->isAssignedToJob($user, $task->job);
    }

    public function canUpdateTask(User $user, FlowTask $task): bool
    {
        if ($this->isAdministrator($user)) {
            return $task->job?->workspace_id === $user->currentWorkspaceId();
        }

        // After a Task is completed it becomes an audit-controlled record.
        // Only Admin/Super Admin may edit, reopen, reassign or otherwise
        // change it. Assigned users can still view the Task and its history.
        if ($this->taskIsCompleted($task)) {
            return false;
        }

        return (int) $task->assignee_id === (int) $user->id
            && ($this->allows($user, 'tasks', 'edit_own', $task)
                || $this->allows($user, 'tasks', 'edit_all', $task));
    }

    public function canAssignExistingTask(User $user, FlowTask $task): bool
    {
        // Generated assignments come from Task Setup. Only an Admin or Super
        // Admin may override the assignee after the Task has been generated.
        return $task->job?->workspace_id === $user->currentWorkspaceId()
            && $this->isAdministrator($user);
    }

    public function canDeleteTask(User $user, FlowTask $task): bool
    {
        // Task removal is intentionally restricted to Admin and Super Admin.
        // Job creators and ordinary assignees may update Tasks but cannot remove them.
        return $task->job?->workspace_id === $user->currentWorkspaceId()
            && $this->isAdministrator($user);
    }

    public function canDeleteJobPhase(User $user, FlowJob $job): bool
    {
        // Workflow phases are structural and can never be removed from an
        // individual Job. They may only be configured in Workflow Setup.
        return false;
    }

    public function canChangeTaskDueDate(User $user, FlowTask $task): bool
    {
        $task->loadMissing('job.members');
        $job = $task->job;
        if (! $job || ! $this->canViewTask($user, $task)) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        // Completed Tasks are locked for everyone except Admin/Super Admin,
        // including due date-only changes.
        if ($this->taskIsCompleted($task)) {
            return false;
        }

        if ((int) $job->created_by === (int) $user->id || $this->canUpdateTask($user, $task)) {
            return true;
        }

        return $this->canManageTasksForJob($user, $job)
            && ($this->allows($user, 'tasks', 'edit_all', $task)
                || $this->allows($user, 'tasks', 'edit_own', $task)
                || $this->allows($user, 'tasks', 'assign', $task));
    }

    private function taskIsCompleted(FlowTask $task): bool
    {
        if ($task->completed_at !== null) {
            return true;
        }

        $task->loadMissing('status:id,name,code');
        $status = strtoupper(trim((string) ($task->status?->code ?: $task->status?->name)));

        return in_array($status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'], true);
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
        if ($record instanceof FlowTask) {
            $record->loadMissing('job');

            return $record->job && $this->isAssignedToJob($user, $record->job);
        }

        if ($record instanceof FlowJob) {
            return $this->isAssignedToJob($user, $record);
        }

        if ($scope === 'all_records') {
            return true;
        }
        if ($scope === 'none') {
            return false;
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

        return false;
    }

    private function assignedJobConditions(Builder $builder, User $user): void
    {
        $builder->where('owner_id', $user->id)
            ->orWhere('coordinator_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->orWhereHas('members', fn (Builder $member) => $member->where('user_id', $user->id))
            ->orWhereHas('tasks', fn (Builder $task) => $task->where('assignee_id', $user->id));
    }

    private function isAssignedToJob(User $user, FlowJob $job): bool
    {
        $cacheKey = $user->id.':'.$job->id;
        if (array_key_exists($cacheKey, $this->jobAssignmentCache)) {
            return $this->jobAssignmentCache[$cacheKey];
        }

        $directlyAssigned = (int) $job->created_by === (int) $user->id
            || (int) $job->owner_id === (int) $user->id
            || (int) $job->coordinator_id === (int) $user->id;

        if ($directlyAssigned) {
            return $this->jobAssignmentCache[$cacheKey] = true;
        }

        $memberAssigned = $job->relationLoaded('members')
            ? $job->members->contains(fn ($member): bool => (int) $member->user_id === (int) $user->id)
            : $job->members()->where('user_id', $user->id)->exists();

        if ($memberAssigned) {
            return $this->jobAssignmentCache[$cacheKey] = true;
        }

        return $this->jobAssignmentCache[$cacheKey] = $job->tasks()
            ->where('assignee_id', $user->id)
            ->exists();
    }

    private function canManageTasksForJob(User $user, FlowJob $job): bool
    {
        $cacheKey = $user->id.':'.$job->id;
        if (array_key_exists($cacheKey, $this->jobTaskManagementCache)) {
            return $this->jobTaskManagementCache[$cacheKey];
        }

        $directlyManages = (int) $job->created_by === (int) $user->id
            || (int) $job->owner_id === (int) $user->id
            || (int) $job->coordinator_id === (int) $user->id;

        if ($directlyManages) {
            return $this->jobTaskManagementCache[$cacheKey] = true;
        }

        $canManage = $job->relationLoaded('members')
            ? $job->members->contains(fn ($member): bool => (int) $member->user_id === (int) $user->id && (bool) $member->can_manage_tasks)
            : $job->members()->where('user_id', $user->id)->where('can_manage_tasks', true)->exists();

        return $this->jobTaskManagementCache[$cacheKey] = $canManage;
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
