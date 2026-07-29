<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\OperationalMetricsService;
use App\Modules\OrderFlow\Data\Repositories\TaskRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyWorkController extends Controller
{
    public function __invoke(Request $request, TaskRepository $tasks, FlowTrackOptionsQuery $options, FlowAuthorizationService $authorization, OperationalMetricsService $metrics): Response
    {
        abort_unless($authorization->allows($request->user(), 'tasks', 'view'), 403);
        return Inertia::render('flowtrack/MyWork/Index', [
            'tasks' => $tasks->myWork($request->user(), $request->only(['search', 'status', 'assignee'])),
            'filters' => $request->only(['search', 'status', 'assignee']),
            'options' => $options->forWorkspace($request->user()),
            'metrics' => $metrics->forUser($request->user())['myWork'],
        ]);
    }
}
