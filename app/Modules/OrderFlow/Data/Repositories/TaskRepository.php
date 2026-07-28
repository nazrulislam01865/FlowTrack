<?php

namespace App\Modules\OrderFlow\Data\Repositories;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TaskRepository
{
    public function __construct(private readonly FlowAuthorizationService $authorization)
    {
    }

    public function myWork(User $user, array $filters = []): Collection
    {
        $tasks = $this->authorization->applyTaskVisibility(FlowTask::query(), $user)
            ->with(['job.client', 'job.currentPhase', 'assignee', 'status', 'priority', 'phase'])
            ->when($filters['search'] ?? null, fn (Builder $query, $search) => $query->where(fn (Builder $nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('task_status_id', $status))
            ->when($filters['assignee'] ?? null, fn (Builder $query, $assignee) => $query->where('assignee_id', $assignee))
            ->whereNull('completed_at')
            ->orderByRaw('due_date is null, due_date asc')
            ->get();

        $this->hideFinancials($tasks, $user);

        return $tasks;
    }

    public function board(User $user): Collection
    {
        $tasks = $this->authorization->applyTaskVisibility(FlowTask::query(), $user)
            ->with(['job.client', 'assignee', 'status', 'priority', 'phase'])
            ->orderBy('sort_order')
            ->orderBy('due_date')
            ->get();

        $this->hideFinancials($tasks, $user);

        return $tasks;
    }

    public function findVisible(User $user, int $id): FlowTask
    {
        $task = $this->authorization->applyTaskVisibility(FlowTask::query(), $user)
            ->with(['job.client', 'phase', 'parent', 'dependency', 'creator', 'assignee', 'status', 'priority', 'checklistItems', 'comments.user'])
            ->findOrFail($id);

        if (! $this->canViewFinancials($user)) {
            $task->job?->makeHidden(['quoted_amount', 'currency_id']);
        }

        return $task;
    }

    private function hideFinancials(Collection $tasks, User $user): void
    {
        if (! $this->canViewFinancials($user)) {
            $tasks->each(fn (FlowTask $task) => $task->job?->makeHidden(['quoted_amount', 'currency_id']));
        }
    }

    private function canViewFinancials(User $user): bool
    {
        return $this->authorization->allows($user, 'invoice', 'view')
            && $this->authorization->canViewSensitiveField($user, 'invoice_amount');
    }
}
