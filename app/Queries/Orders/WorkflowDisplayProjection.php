<?php

namespace App\Queries\Orders;

use Illuminate\Support\Collection;

/**
 * Request-local, read-only lookup structure. Task models are still the
 * authoritative, permission-filtered rows; no snapshot or duplicate is saved.
 */
final readonly class WorkflowDisplayProjection
{
    public function __construct(
        public Collection $phases,
        public Collection $tasksById,
        public Collection $tasksByPhase,
        public Collection $tasks,
    ) {
    }
}
