<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep platform support sessions outside customer APIs unless explicitly allowed.
 *
 * During a support session the customer identity endpoint answers exactly like an
 * unauthenticated request, so no customer profile, company list or commercial
 * context is exposed. Only the CSRF token and the support exit stay reachable,
 * which is what the portal needs to end the session.
 */
final class RejectLawAccessDuringSupport
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('support_session_id') && $request->is('api/*')) {
            if ($request->is('api/auth/me')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $allowed = $request->is('api/csrf-token')
                || $request->is('api/backoffice/support/exit');

            if (! $allowed) {
                return response()->json([
                    'code' => 'support_customer_api_denied',
                    'message' => 'O acesso de suporte não pode acessar APIs operacionais de clientes.',
                ], 403);
            }
        }

        return $next($request);
    }
}
