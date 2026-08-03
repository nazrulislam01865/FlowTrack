<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ClientController extends AbstractPageController
{
    public function index(Request $request): Response
    {
        return $this->pages->render($request, 'clients', ['clients', 'view']);
    }

    public function create(Request $request): Response
    {
        return $this->pages->render($request, 'clients', ['clients', 'create']);
    }

    public function show(Request $request, string $record): Response
    {
        return $this->pages->render($request, 'clients', ['clients', 'view']);
    }

    public function edit(Request $request, string $record): Response
    {
        return $this->pages->render(
            $request,
            'clients',
            fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'clients', 'edit_own')
                || $authorization->allows($user, 'clients', 'edit_all'),
        );
    }
}
