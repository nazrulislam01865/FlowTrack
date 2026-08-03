<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Services\FlowLandingPageResolver;
use App\Services\FlowTrack\PageResponseService;
use App\Support\FlowTrackPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class FlowTrackAuthPageController extends Controller
{
    public function __invoke(
        Request $request,
        FlowLandingPageResolver $landing,
        PageResponseService $pages,
    ): Response|RedirectResponse {
        if ($request->user() !== null) {
            return redirect($landing->resolve($request->user())['url']);
        }

        return $pages->response($request, [
            'flowtrackAuthenticated' => false,
            'flowtrackPage' => FlowTrackPage::DASHBOARD,
            'flowtrackPageRoutes' => FlowTrackPage::paths(),
        ]);
    }
}
