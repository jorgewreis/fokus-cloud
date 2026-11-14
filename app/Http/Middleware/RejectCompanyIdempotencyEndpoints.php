<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** This guard remains closed until the full contract is independently approved. */
final class RejectCompanyIdempotencyEndpoints
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(503, 'A idempotência de empresas ainda não está disponível.');
    }
}
