<?php

namespace App\Http\Middleware;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FlowActionMiddleware
{
    public function __construct(private readonly FlowAuthorizationService $authorization)
    {
    }

    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        abort_unless($request->user() && $this->authorization->allows($request->user(), $module, $action), 403);

        return $next($request);
    }
}
