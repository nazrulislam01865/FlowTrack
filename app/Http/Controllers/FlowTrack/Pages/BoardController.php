<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BoardController extends AbstractPageController
{
    public function index(Request $request): Response
    {
        return $this->pages->render(
            $request,
            'board',
            fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'jobs', 'view')
                || $authorization->allows($user, 'tasks', 'view'),
        );
    }
}
