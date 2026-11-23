<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep platform support sessions outside the customer Law API surface. */
final class RejectLawAccessDuringSupport
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('support_session_id') && $request->is('api/law/*')) {
            abort(403, 'O acesso de suporte não pode acessar o ambiente operacional do Fokus Law.');
        }

        return $next($request);
    }
}
