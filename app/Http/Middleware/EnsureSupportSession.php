<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use App\Services\SupportSessionSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupportSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($id = $request->session()->get('support_session_id')) {
            $support = DB::table('platform_support_sessions')->where('id', $id)->whereNull('ended_at')->first();
            $admin = Auth::guard('platform')->user();
            $valid = $support
                && now()->lt(\Illuminate\Support\Carbon::parse($support->started_at)->addMinutes((int) config('security.support_session_minutes')))
                && $admin instanceof PlatformAdmin
                && $admin->id === $support->platform_admin_id
                && $admin->isAvailableForLogin()
                && $admin->hasPermission('platform.access')
                && Auth::guard('web')->id() === $support->target_user_id;

            if (! $valid) {
                app(SupportSessionSecurity::class)->end($request, 'Acesso de suporte encerrado por expiração ou perda de autorização.', true);
            }
        }

        return $next($request);
    }
}
