<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\MoveJobPhaseRequest;
use App\Modules\OrderFlow\Application\Actions\MoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskAction;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
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

        $boardTasks = $tasks->board($request->user());
        $boardTasks->each(function (FlowTask $task) use ($request, $authorization): void {
            $task->setAttribute('can_update', $authorization->canUpdateTask($request->user(), $task));
            $task->setAttribute('can_assign', $authorization->canAssignExistingTask($request->user(), $task));
        });

        return Inertia::render('flowtrack/Board/Index', [
            'jobs' => $jobs->board($request->user()),
            'tasks' => $boardTasks,
            'options' => $options->forWorkspace($request->user()),
            'boardAccess' => [
                'isAdministrator' => $authorization->isAdministrator($request->user()),
                'taskVisibility' => $authorization->isAdministrator($request->user()) ? 'workspace' : 'assigned_only',
            ],
        ]);
    }

    public function moveJob(MoveJobPhaseRequest $request, FlowJob $job, JobRepository $jobs, FlowAuthorizationService $authorization, MoveJobPhaseAction $action): RedirectResponse
    {
        $visible = $jobs->findBoardVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'jobs', 'edit_all', $visible) || $authorization->allows($request->user(), 'jobs', 'edit_own', $visible), 403);

        try {
            $action->execute($visible, (int) $request->validated('phase_id'), $request->user(), $request->validated('reason'));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['phase_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Job phase updated.');
    }

    public function moveTask(Request $request, FlowTask $task, TaskRepository $tasks, FlowAuthorizationService $authorization, UpdateTaskAction $action): RedirectResponse
    {
        $data = $request->validate(['task_status_id' => ['required', 'integer', 'exists:master_records,id']]);
        $visible = $tasks->findVisible($request->user(), $task->id);
        abort_unless($authorization->canUpdateTask($request->user(), $visible), 403);

        try {
            $action->execute($visible, $data, $request->user(), 'task_board');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['task_status_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Task status updated.');
    }
}
