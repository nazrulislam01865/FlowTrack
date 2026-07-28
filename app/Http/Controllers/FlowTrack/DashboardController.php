<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Data\Repositories\DashboardReadRepository;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardReadRepository $dashboard, FlowTrackOptionsQuery $options): Response
    {
        return Inertia::render('flowtrack/Dashboard', [
            ...$dashboard->get($request->user()),
            'options' => $options->forWorkspace($request->user()),
        ]);
    }
}
