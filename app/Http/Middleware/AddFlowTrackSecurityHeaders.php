<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddFlowTrackSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('flowtrack.force_https') && ! $request->secure()) {
            if ($request->isMethodSafe()) {
                return redirect()->secure($request->getRequestUri(), 301);
            }

            abort(400, 'FlowTrack write requests require HTTPS.');
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set(
            'Content-Security-Policy',
            "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
        );

        if ($request->secure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->routeIs(
            'login',
            'flowtrack.login.store',
            'flowtrack.template-api.profile.*',
            'flowtrack.template-api.access.*',
        )) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
