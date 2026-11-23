<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep platform support sessions outside customer APIs unless explicitly allowed. */
final class RejectLawAccessDuringSupport
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('support_session_id') && $request->is('api/*')) {
            $allowed = $request->is('api/auth/me')
                || $request->is('api/backoffice/*')
                || $request->is('api/webhooks/*')
                || $request->is('api/integrations/*');

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
