<?php

namespace App\Queries\Orders;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Support\JobDetailPresenter;
use App\Support\OrderDetailPresenter;
use App\Support\OrderTaskRequirement;
use Illuminate\Support\Collection;

/**
 * Read-only, one-order phase summaries. Non-selected phases use a compact task
 * projection rather than the full task-card/attachment graph. Conditional
 * task applicability is evaluated by the same presenter as the workflow UI.
 * The caller must authorize the order via VisibleOrderQuery first.
 */
final class OrderPhaseSummaryQuery
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    /**
     * @param Collection<int, WorkflowPhase> $phases Fully hydrated authorized phase definitions
     * @return array<int, array{completed: int, total: int, progress: int, ownerTask: ?Task, ownerName: string}>
     */
    public function forAuthorizedOrder(FlowJob $order, User $viewer, Collection $phases, int $loadedPhaseId): array
    {
        $otherPhaseIds = $phases->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $loadedPhaseId)
            ->values()->all();

        $otherTasks = collect();
        if ($otherPhaseIds !== []) {
            $query = Task::query()
                ->where('flow_job_id', (int) $order->id)
                ->whereIn('workflow_phase_id', $otherPhaseIds)
                ->select([
                    'id', 'flow_job_id', 'workflow_phase_id', 'task_pack_task_id',
                    'assignee_id', 'title', 'status', 'progress', 'completed_at',
                ])
                ->withExists([
                    'documents',
                    'links' => fn ($links) => $links->reorder(),
                ])
                ->with('assignee:id,name,profile_image_path');

            $this->access->applyTaskScope($query, $viewer);
            $compactTasks = $query->get();
            // Unusual old Orders can refer to a Task Pack template missing from
            // the published graph. Resolve those exceptions in ONE batched
            // fallback, preserving JobDetailPresenter's legacy membership rule.
            $packs = $phases->keyBy('id');
            $unresolved = $compactTasks->filter(static function (Task $task) use ($packs): bool {
                if (! $task->task_pack_task_id) return false;
                $phase = $packs->get((int) $task->workflow_phase_id);
                $pack = $phase && $phase->relationLoaded('taskPack') ? $phase->taskPack : null;
                if (! $pack || ! $pack->relationLoaded('items')) return false;
                return ! $pack->items->contains('id', (int) $task->task_pack_task_id);
            });
            if ($unresolved->isNotEmpty()) {
                $unresolved->loadMissing('setupTemplate:id,task_pack_id,is_required,sort_order,automation_key');
            }
            $otherTasks = $compactTasks->groupBy('workflow_phase_id');
        }

        $results = [];
        foreach ($phases as $phase) {
            $phaseId = (int) $phase->id;
            $tasks = $phaseId === $loadedPhaseId
                ? JobDetailPresenter::phaseTasks($order, $phase)
                : $this->eligibleSummaryTasks($otherTasks->get($phaseId, collect()), $phase);

            $applicable = $tasks->filter(
                fn (Task $task): bool => OrderDetailPresenter::isApplicableTask($task)
            );
            $total = $applicable->count();
            $completed = $applicable->filter(
                fn (Task $task): bool => OrderDetailPresenter::isCompletedTask($task)
            )->count();
            $progress = OrderDetailPresenter::phaseState($order, $phase) === 'completed'
                ? 100 : ($total ? (int) round($completed * 100 / $total) : 0);

            $orderedTasks = $tasks->sortBy(fn (Task $task): array => [
                OrderTaskRequirement::sequence($task), (int) $task->id,
            ]);
            $namedOwner = $orderedTasks->first(
                fn (Task $task): bool => filled($task->assignee?->name)
            );
            $results[$phaseId] = [
                'completed' => $completed,
                'total' => $total,
                'progress' => $progress,
                'ownerTask' => $orderedTasks->first(),
                'ownerName' => (string) ($namedOwner?->assignee?->name
                    ?: $order->coordinator?->name ?: $order->owner?->name ?: 'Unassigned'),
            ];
        }

        return $results;
    }

    /**
     * Match JobDetailPresenter's existing phase/Task Pack membership filter,
     * but never load full task relations for an unselected phase.
     *
     * @param Collection<int, Task> $tasks
     * @return Collection<int, Task>
     */
    private function eligibleSummaryTasks(Collection $tasks, WorkflowPhase $phase): Collection
    {
        $pack = $phase->relationLoaded('taskPack') ? $phase->getRelation('taskPack') : null;
        $items = $pack && $pack->relationLoaded('items') ? $pack->items : collect();
        $itemsById = $items->keyBy('id');

        return $tasks->filter(function (Task $task) use ($pack, $itemsById, $phase): bool {
            $itemId = (int) ($task->task_pack_task_id ?: 0);
            if ($itemId === 0) {
                $task->setRelation('setupTemplate', null);
                return true;
            }
            if (! $pack) {
                return false;
            }
            $item = $itemsById->get($itemId);
            if (! $item) {
                return (int) ($task->setupTemplate?->task_pack_id ?? 0) === (int) $phase->task_pack_id;
            }
            $task->setRelation('setupTemplate', $item);
            return true;
        })->values();
    }
}
