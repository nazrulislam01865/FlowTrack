<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TaskPackController extends AbstractPageController
{
    public function index(Request $request): Response
    {
        return $this->pages->render($request, 'taskpacksetup', ['workflow', 'view']);
    }

    public function create(Request $request): Response
    {
        return $this->pages->render($request, 'taskpacksetup', ['workflow', 'create']);
    }

    public function edit(Request $request, string $record): Response
    {
        return $this->pages->render(
            $request,
            'taskpacksetup',
            fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'workflow', 'edit_own')
                || $authorization->allows($user, 'workflow', 'edit_all'),
        );
    }
}
