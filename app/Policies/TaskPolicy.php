<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Services\AccessControlService;

final class TaskPolicy
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function viewAny(User $user): bool { return $this->access->can($user, 'tasks', 'view'); }
    public function view(User $user, Task $task): bool { return $this->access->applyTaskScope(Task::query()->whereKey($task->id), $user)->exists(); }
    public function create(User $user, object $job): bool { return $this->access->canCreateJobTask($user, $job); }
    public function update(User $user, Task $task): bool { return $this->access->canEditTask($user, $task); }
    public function assign(User $user, Task $task): bool { return $this->access->canAssignTask($user, $task); }
    public function delete(User $user, Task $task): bool { return $this->access->can($user, 'tasks', 'delete') && $this->view($user, $task); }
}
