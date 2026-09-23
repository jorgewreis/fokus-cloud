<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();
        abort_unless($admin instanceof PlatformAdmin && $admin->isAvailableForLogin() && $admin->hasPermission('platform.access'), 401, 'Acesso interno não autenticado.');
        $request->setUserResolver(fn () => $admin);

        return $next($request);
    }
}
