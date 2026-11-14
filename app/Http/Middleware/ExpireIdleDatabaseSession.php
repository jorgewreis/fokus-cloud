<?php

namespace App\Http\Middleware;

use App\Services\PlatformSessionBinding;
use App\Services\PlatformSessionLifecycle;
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
        $lastActivity = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('id', $session->getId())
            ->value('last_activity');

        // A request may have loaded an authenticated session just before GC or
        // logout deleted its row. Only enforce this experimental path when it
        // carries a platform binding; a new anonymous session has no row yet.
        if ($lastActivity === null && config('company_idempotency.session_authority_lifecycle', false) === true
            && (new PlatformSessionBinding)->current($session) !== null) {
            $actor = Auth::guard('platform')->id();
            app(PlatformSessionLifecycle::class)->retire($session, $actor === null ? null : (string) $actor);
            Auth::guard('web')->logout();
            Auth::guard('platform')->logout();
            $session->invalidate();
            $session->regenerateToken();
            Auth::forgetGuards();
        }

        // A missing row is a new anonymous session. An existing stale row must
        // be invalidated before route authentication reads either web guard.
        if ($lastActivity !== null) {
            $expiresAt = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;

            if ((int) $lastActivity <= $expiresAt) {
                $actor = Auth::guard('platform')->id();
                app(PlatformSessionLifecycle::class)->retire($session, $actor === null ? null : (string) $actor);
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
