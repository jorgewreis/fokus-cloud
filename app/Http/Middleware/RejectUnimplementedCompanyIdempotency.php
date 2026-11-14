<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reject keyed requests until all nodes support the complete contract. */
final class RejectUnimplementedCompanyIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->headers->has('Idempotency-Key'), 503, 'A solicitação com chave de idempotência ainda não está disponível.');

        return $next($request);
    }
}
