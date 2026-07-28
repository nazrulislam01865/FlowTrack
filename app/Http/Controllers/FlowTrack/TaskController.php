<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\UpdateTaskRequest;
use App\Http\Requests\FlowTrack\StoreTaskRequest;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskAction;
use App\Modules\OrderFlow\Application\Actions\CreateTaskAction;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Repositories\TaskRepository;
use App\Modules\OrderFlow\Data\Repositories\JobRepository;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function store(StoreTaskRequest $request, FlowJob $job, JobRepository $jobs, FlowAuthorizationService $authorization, CreateTaskAction $action): RedirectResponse
    {
        $visibleJob = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'tasks', 'create', $visibleJob), 403);

        $data = $request->validated();
        if (! empty($data['assignee_id']) && (int) $data['assignee_id'] !== (int) $request->user()->id) {
            abort_unless($authorization->allows($request->user(), 'tasks', 'assign', $visibleJob), 403);
        }

        try {
            $action->execute($visibleJob, $data, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        return back()->with('success', 'Task created successfully.');
    }

    public function show(Request $request, FlowTask $task, TaskRepository $tasks): JsonResponse
    {
        return response()->json($tasks->findVisible($request->user(), $task->id));
    }

    public function update(UpdateTaskRequest $request, FlowTask $task, TaskRepository $tasks, AccessResolver $access, FlowAuthorizationService $authorization, UpdateTaskAction $action): RedirectResponse
    {
        $visibleTask = $tasks->findVisible($request->user(), $task->id);
        abort_unless($access->canManageTasks($request->user(), $visibleTask->job) || $visibleTask->assignee_id === $request->user()->id, 403);

        $data = $request->validated();
        if (array_key_exists('assignee_id', $data) && (int) ($data['assignee_id'] ?? 0) !== (int) ($visibleTask->assignee_id ?? 0)) {
            abort_unless($authorization->allows($request->user(), 'tasks', 'assign', $visibleTask), 403);
        }

        try {
            $action->execute($visibleTask, $data, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['blocker_reason' => $exception->getMessage()]);
        }

        return back()->with('success', 'Task update saved.');
    }
    public function destroy(Request $request, FlowTask $task, TaskRepository $tasks, FlowAuthorizationService $authorization, ProgressCalculator $progressCalculator): RedirectResponse
    {
        $visibleTask = $tasks->findVisible($request->user(), $task->id);
        abort_unless($authorization->allows($request->user(), 'tasks', 'delete', $visibleTask), 403);
        $job = $visibleTask->job;
        $visibleTask->delete();
        $progressCalculator->recalculateJob($job);

        return back()->with('success', 'Task deleted successfully.');
    }

}
