<?php

namespace App\Services\Orders;

use App\Services\LegacyJobService;

final class OrderWorkflowService
{
    public function __construct(private readonly LegacyJobService $legacy)
    {
    }

    public function syncWorkflowTasks(mixed ...$arguments): mixed
    {
        return $this->legacy->syncWorkflowTasks(...$arguments);
    }

    public function appendTask(mixed ...$arguments): mixed
    {
        return $this->legacy->appendTask(...$arguments);
    }

    public function syncAutomaticStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->syncAutomaticStatus(...$arguments);
    }

    public function recalculateProgress(mixed ...$arguments): mixed
    {
        return $this->legacy->recalculateProgress(...$arguments);
    }

    public function maybeAutoAdvance(mixed ...$arguments): mixed
    {
        return $this->legacy->maybeAutoAdvance(...$arguments);
    }

    public function completePhase(mixed ...$arguments): mixed
    {
        return $this->legacy->completePhase(...$arguments);
    }

    public function moveToPhase(mixed ...$arguments): mixed
    {
        return $this->legacy->moveToPhase(...$arguments);
    }



}
