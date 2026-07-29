<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowTask;

/**
 * Backward-compatible facade. Every interface now reaches the same
 * TaskTransitionAction and therefore the same validation and workflow sync.
 */
class UpdateTaskAction
{
    public function __construct(private readonly TaskTransitionAction $transitions) {}

    public function execute(FlowTask $task, array $data, User $actor, string $source = 'task_drawer'): FlowTask
    {
        return $this->transitions->execute($task, $data, $actor, $source);
    }
}
