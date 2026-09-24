<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require an explicit session token on browser API mutations.
 * Laravel's fetch-metadata shortcut accepts same-origin requests without a
 * token; the application intentionally requires both same-origin context and
 * an unpredictable token for its cookie-authenticated API.
 */
class RequireApiCsrfToken
{
    private const EXEMPT_PATHS = [
        'api/webhooks/mercado-pago',
        'api/integrations/usage',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing') || $request->isMethodSafe() || $request->is(self::EXEMPT_PATHS)) {
            return $next($request);
        }

        $provided = $request->header('X-CSRF-TOKEN') ?? $request->header('X-XSRF-TOKEN');
        $expected = $request->session()->token();

        if (! is_string($provided) || ! is_string($expected) || ! hash_equals($expected, $provided)) {
            throw new TokenMismatchException('CSRF token mismatch.');
        }

        return $next($request);
    }
}
