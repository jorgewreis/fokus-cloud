<?php

namespace App\Http\Middleware;

use App\Services\LawAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLawPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        app(LawAuthorizationService::class)->authorize($request, $permission);
        return $next($request);
    }
}
