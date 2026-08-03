<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class JobController extends AbstractPageController
{
    public function index(Request $request): Response
    {
        return $this->pages->render($request, 'jobs', ['jobs', 'view']);
    }

    public function create(Request $request): Response
    {
        return $this->pages->render($request, 'jobs', ['jobs', 'create']);
    }

    public function show(Request $request, string $record): Response
    {
        return $this->pages->render($request, 'jobs', ['jobs', 'view']);
    }

    public function edit(Request $request, string $record): Response
    {
        return $this->pages->render(
            $request,
            'jobs',
            fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'jobs', 'edit_own')
                || $authorization->allows($user, 'jobs', 'edit_all'),
        );
    }
}
