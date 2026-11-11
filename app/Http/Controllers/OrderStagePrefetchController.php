<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\TaskLink;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Queries\Orders\OrderWorkflowDisplayQuery;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\JobService;
use App\Services\OrderWorkflowBindingService;
use App\Services\OrderWorkflowSetupService;
use App\Support\JobDetailPresenter;
use App\Support\OrderDetailPresenter;
use App\Support\OrderTaskRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only task data for other accessible stages, fetched after the selected
 * stage has rendered. Never writes Tasks, runs auto-advance, or touches the
 * interactive Livewire component. A subsequent action always reauthorizes.
 */
final class OrderStagePrefetchController
{
    public function __invoke(Request $request, int $orderId): JsonResponse
    {
        abort_unless(
            (bool) config('flowtrack.order_three_level_loading_enabled', false)
            && (bool) config('flowtrack.workflow_display_query_enabled', false),
            404
        );

        $viewer = $request->user();
        abort_unless($viewer instanceof User, 403);
        $data = $request->validate(['active_phase_id' => ['required', 'integer', 'min:1']]);
        $excludedId = (int) $data['active_phase_id'];

        // The existing visible-order boundary applies tenant/role permissions.
        $order = app(VisibleOrderQuery::class)->base($viewer, $orderId);
        $currentSequence = (int) WorkflowPhase::query()
            ->whereKey((int) $order->workflow_phase_id)->value('sequence');
        abort_unless($currentSequence > 0, 404);

        $published = ! $order->completed_at
            && ! in_array((string) $order->status, JobService::INACTIVE_STATUSES, true)
            && app(OrderWorkflowSetupService::class)->isActiveOrderWorkflow((int) $order->workflow_id);
        if ($published) {
            $phases = app(OrderWorkflowBindingService::class)
                ->publishedPhasesForRead((int) $order->workflow_id);
        } else {
            // Only historical orders need the fallback graph. Eager load the
            // Task Pack once, not one relation per stage/task.
            $order->load('workflow.phases.taskPack.items.documentCategory');
            $phases = $order->workflow?->phases ?? collect();
        }

        // Future locked stages must not leak task titles or attachment counts.
        // Their stage cards remain visible through the normal overview shell.
        $accessible = $phases->filter(static fn (WorkflowPhase $phase): bool =>
            (int) $phase->sequence <= $currentSequence
            && (int) $phase->id !== $excludedId
        )->values();
        if ($accessible->isEmpty()) {
            return $this->response($orderId, $excludedId, []);
        }

        // One bounded task SELECT with existing scope, existing Task Pack
        // membership, eager assignees and document/link EXISTS. No per-task SQL.
        $reader = app(OrderWorkflowDisplayQuery::class);
        $phaseIds = $accessible->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $projection = $reader->loadForAuthorizedOrder(
            $order, $viewer, $accessible, $phaseIds, $phaseIds
        );

        $taskIds = $projection->tasks->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $documentCounts = $taskIds === [] ? collect() : Document::query()
            ->where('flow_job_id', $orderId)
            ->whereIn('task_id', $taskIds)
            ->selectRaw('task_id, COUNT(*) AS aggregate')
            ->groupBy('task_id')->pluck('aggregate', 'task_id');
        $linkCounts = $taskIds === [] ? collect() : TaskLink::query()
            ->whereIn('task_id', $taskIds)
            ->selectRaw('task_id, COUNT(*) AS aggregate')
            ->groupBy('task_id')->pluck('aggregate', 'task_id');

        $rows = $accessible->map(function (WorkflowPhase $phase) use ($order, $documentCounts, $linkCounts): array {
            $allPhaseTasks = JobDetailPresenter::phaseTasks($order, $phase);
            $visibleTasks = OrderDetailPresenter::displayTasksForPhase($order, $phase);
            $applicable = $allPhaseTasks->filter(fn ($task): bool => OrderDetailPresenter::isApplicableTask($task));
            $total = $applicable->count();
            $completed = $applicable->filter(fn ($task): bool => OrderDetailPresenter::isCompletedTask($task))->count();

            return [
                'phase_id' => (int) $phase->id,
                'completed' => $completed,
                'total' => $total,
                'progress' => $total ? (int) round($completed * 100 / $total) : 0,
                'tasks' => $visibleTasks->map(static fn ($task): array => [
                    'id' => (int) $task->id,
                    'title' => (string) $task->title,
                    'status' => (string) $task->status,
                    'assignee' => (string) ($task->assignee?->name ?? ''),
                    'due_date' => $task->due_date?->toDateString(),
                    'required' => OrderTaskRequirement::isRequired($task),
                    'attachment_count' => (int) $documentCounts->get((int) $task->id, 0)
                        + (int) $linkCounts->get((int) $task->id, 0),
                ])->values()->all(),
            ];
        })->all();

        return $this->response($orderId, $excludedId, $rows);
    }

    /** @param array<int, array<string, mixed>> $phases */
    private function response(int $orderId, int $excludedId, array $phases): JsonResponse
    {
        return response()->json([
            'order_id' => $orderId,
            'active_phase_id' => $excludedId,
            'phases' => array_values($phases),
        ])->header('Cache-Control', 'private, no-store');
    }
}
