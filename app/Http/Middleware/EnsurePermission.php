<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user?->canAccess($permission)) {
            return $next($request);
        }

        // Reports are intentionally invisible to roles without Report access.
        // If somebody reaches a report URL directly (bookmark/history), fail
        // closed without exposing a 403/error screen.
        if ($permission === 'reports.view') {
            if ($user?->canAccess('dashboard.view')) {
                return redirect()->route('dashboard');
            }

            return response('', 204);
        }

        abort(403);
    }
}
