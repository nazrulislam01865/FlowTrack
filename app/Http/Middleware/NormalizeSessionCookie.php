<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep the session cookie compatible with the host/scheme the user actually
 * used to reach FlowTrack.
 *
 * A stale SESSION_DOMAIN or SESSION_SECURE_COOKIE setting is a common cause
 * of Laravel 419 responses: the browser accepts the form, but silently omits
 * the session cookie on the POST, so the CSRF token can no longer be matched.
 * This middleware runs before StartSession and normalizes only cookie transport
 * attributes; it never disables CSRF validation.
 */
class NormalizeSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $configuredDomain = $this->normalizeDomain(config('session.domain'));

        // If FlowTrack is opened by an IP address or an alternate hostname,
        // do not emit a cookie for an unrelated SESSION_DOMAIN. A host-only
        // cookie is the only cookie the browser can reliably return there.
        if (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || ($configuredDomain !== null && !$this->hostMatchesDomain($host, $configuredDomain))
        ) {
            config()->set('session.domain', null);
        }

        // Make the Secure attribute follow the effective request scheme. This
        // prevents an HTTP deployment/IP visit from receiving a Secure cookie
        // that the browser will refuse to send back, while HTTPS still gets a
        // Secure cookie. Trusted proxy configuration (when present) is applied
        // before the web middleware group, so isSecure() sees forwarded HTTPS.
        $isSecure = $request->isSecure();
        config()->set('session.secure', $isSecure);

        // Browsers reject SameSite=None cookies unless they are Secure. Keep a
        // legacy/misconfigured HTTP deployment functional by falling back to
        // Lax instead of allowing the browser to discard the session cookie.
        if (!$isSecure && strtolower((string) config('session.same_site')) === 'none') {
            config()->set('session.same_site', 'lax');
        }

        if (!$isSecure && (bool) config('session.partitioned')) {
            config()->set('session.partitioned', false);
        }

        return $next($request);
    }

    private function normalizeDomain(mixed $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = ltrim($domain, '.');

        return $domain !== '' ? $domain : null;
    }

    private function hostMatchesDomain(string $host, string $domain): bool
    {
        if ($host === $domain) {
            return true;
        }

        return str_ends_with($host, '.'.$domain);
    }
}
