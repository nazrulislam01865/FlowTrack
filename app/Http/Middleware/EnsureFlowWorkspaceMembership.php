<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFlowWorkspaceMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = $user?->currentWorkspaceMembership();

        abort_unless(
            $user
            && $user->status === 'active'
            && $membership
            && $membership->status === 'active'
            && $membership->role?->is_active
            && $membership->workspace?->is_active,
            403,
            'Your FlowTrack workspace access is inactive or has not been assigned.',
        );

        return $next($request);
    }
}
