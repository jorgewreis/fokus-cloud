<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ExpireIdleDatabaseSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('session.driver') !== 'database' || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $lastActivity = DB::table(config('session.table', 'sessions'))
            ->where('id', $session->getId())
            ->value('last_activity');

        // A missing row is a new anonymous session. An existing stale row must
        // be invalidated before route authentication reads either web guard.
        if ($lastActivity !== null) {
            $expiresAt = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;

            if ((int) $lastActivity <= $expiresAt) {
                Auth::guard('web')->logout();
                Auth::guard('platform')->logout();
                $session->invalidate();
                $session->regenerateToken();
                Auth::forgetGuards();
            }
        }

        return $next($request);
    }
}
