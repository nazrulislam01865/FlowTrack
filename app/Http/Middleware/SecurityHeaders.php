<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Central browser-security headers. CSP starts report-only to avoid UI regressions. */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $delegatedTicketRedirect = $request->isMethod('GET')
            && preg_match('~^supplier-product/access/[A-Za-z0-9]{64}$~', $request->path());
        $delegatedProductPage = $request->isMethod('GET')
            && ($delegatedTicketRedirect || ($request->is('master-data')
                && $request->user()
                && app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)->isPortalActor($request->user())))
            && rtrim($request->getSchemeAndHttpHost(), '/') === rtrim((string) config('supplier_bridge.product_module_url'), '/');
        if ($delegatedProductPage) {
            $portal = rtrim((string) config('supplier_bridge.url'), '/');
            $parts = parse_url($portal);
            if (is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                && preg_match('/^[a-zA-Z0-9.-]+$/', (string) ($parts['host'] ?? ''))
                && (! isset($parts['port']) || (int) $parts['port'] > 0 && (int) $parts['port'] <= 65535)
                && ($parts['scheme'] === 'https' || (app()->environment(['local', 'testing'])
                    && in_array($parts['host'], ['localhost', '127.0.0.1'], true)))) {
                $frameOrigin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                // X-Frame-Options SAMEORIGIN would block even an allowlisted portal.
                $headers->remove('X-Frame-Options');
                $headers->set('Content-Security-Policy', "frame-ancestors {$frameOrigin}; object-src 'none'; base-uri 'self'");
                $headers->set('Referrer-Policy', 'no-referrer');
            }
        }
        if (! $delegatedProductPage || $headers->get('Referrer-Policy') === null) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set(
            'Content-Security-Policy-Report-Only',
            "default-src 'self'; base-uri 'self'; frame-ancestors 'self'; object-src 'none'; form-action 'self'; " .
            "img-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; " .
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self' ws: wss:; media-src 'self' blob:; worker-src 'self' blob:"
        );

        if (app()->environment('production') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
