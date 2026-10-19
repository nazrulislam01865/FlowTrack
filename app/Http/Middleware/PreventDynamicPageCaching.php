<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic FlowTrack HTML contains per-session CSRF tokens and user-specific
 * content. It must never be cached by a browser proxy/CDN as reusable HTML.
 */
class PreventDynamicPageCaching
{
    /** Routes whose controllers own an explicit immutable asset cache policy. */
    private const CACHE_MANAGED_ASSET_ROUTES = [
        'branding-assets.show',
        'profile-images.show',
        'client-logos.show',
        'master-data.product-image',
        'master-data.product-option-image',
        'rich-text-images.show',
        'rich-text-images.download',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Binary/media controllers define their own private/public immutable
        // policy. Do not replace it when a fake/test filesystem reports an
        // imprecise MIME type such as text/html.
        if ($request->routeIs(self::CACHE_MANAGED_ASSET_ROUTES)) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type'));

        if (str_contains($contentType, 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
