<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\MoveJobPhaseRequest;
use App\Modules\OrderFlow\Application\Actions\MoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Repositories\JobRepository;
use App\Modules\OrderFlow\Data\Repositories\TaskRepository;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function index(Request $request, JobRepository $jobs, TaskRepository $tasks, FlowTrackOptionsQuery $options, FlowAuthorizationService $authorization): Response
    {
        abort_unless($authorization->allows($request->user(), 'jobs', 'view') || $authorization->allows($request->user(), 'tasks', 'view'), 403);
        return Inertia::render('flowtrack/Board/Index', [
            'jobs' => $jobs->board($request->user()),
            'tasks' => $tasks->board($request->user()),
            'options' => $options->forWorkspace($request->user()),
        ]);
    }

    public function moveJob(MoveJobPhaseRequest $request, FlowJob $job, JobRepository $jobs, FlowAuthorizationService $authorization, MoveJobPhaseAction $action): RedirectResponse
    {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'jobs', 'edit_all', $visible) || $authorization->allows($request->user(), 'jobs', 'edit_own', $visible), 403);

        try {
            $action->execute($visible, (int) $request->validated('phase_id'), $request->user(), $request->validated('reason'));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['phase_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Job phase updated.');
    }

    public function moveTask(Request $request, FlowTask $task, TaskRepository $tasks, FlowAuthorizationService $authorization, ActivityLogger $activityLogger, ProgressCalculator $progressCalculator): RedirectResponse
    {
        $data = $request->validate(['task_status_id' => ['required', 'integer', 'exists:master_records,id']]);
        $visible = $tasks->findVisible($request->user(), $task->id);
        abort_unless($authorization->allows($request->user(), 'tasks', 'edit_all', $visible) || $authorization->allows($request->user(), 'tasks', 'edit_own', $visible), 403);

        $status = MasterRecord::query()
            ->where('workspace_id', $visible->job->workspace_id)
            ->where('type', MasterDataType::TaskStatus->value)
            ->findOrFail($data['task_status_id']);

        if ($status->code === 'BLK') {
            throw ValidationException::withMessages(['task_status_id' => 'Open the task details and add a blocker reason before marking it blocked.']);
        }

        $visible->loadMissing('dependency');
        if (in_array($status->code, ['IP', 'CMP'], true) && $visible->dependency && ! $visible->dependency->completed_at) {
            throw ValidationException::withMessages(['task_status_id' => "Complete dependency {$visible->dependency->code} before moving this task."]);
        }

        $visible->update([
            'task_status_id' => $status->id,
            'progress' => $progressCalculator->forTaskStatus($status),
            'completed_at' => $status->code === 'CMP' ? now() : null,
        ]);

        $activityLogger->log($visible->job->workspace_id, $request->user(), $visible, 'task.status_changed', "Task {$visible->code} moved to {$status->name}");
        $progressCalculator->recalculateForTask($visible);

        return back()->with('success', 'Task status updated.');
    }
}
