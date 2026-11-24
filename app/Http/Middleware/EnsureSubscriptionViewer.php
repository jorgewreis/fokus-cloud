<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $membership = $request->attributes->get('active_membership');
        abort_unless($membership && $membership->role === 'admin', 403, 'Somente o administrador da empresa pode consultar assinaturas.');

        return $next($request);
    }
}
