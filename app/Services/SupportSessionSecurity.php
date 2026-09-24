<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupportSessionSecurity
{
    public function end(Request $request, string $reason = 'Acesso de suporte encerrado.', bool $automatic = false): bool
    {
        $id = $request->session()->get('support_session_id');
        if (! $id) {
            return false;
        }

        $support = DB::table('platform_support_sessions')->where('id', $id)->whereNull('ended_at')->first();
        if ($support && DB::table('platform_support_sessions')->where('id', $id)->whereNull('ended_at')->update([
            'ended_at' => now(),
            'end_ip' => $request->ip(),
            'end_user_agent' => app(AuditSanitizer::class)->sanitizeText((string) $request->userAgent()),
            'updated_at' => now(),
        ])) {
            app(PlatformAudit::class)->record(
                $automatic ? null : $support->platform_admin_id,
                'backoffice.support_access_ended',
                'platform_support_session',
                $id,
                $support->company_id,
                $reason,
                before: ['status' => 'active'],
                after: ['status' => 'ended'],
                request: $request,
                actorType: $automatic ? 'system' : 'admin',
            );
        }

        Auth::guard('web')->logout();
        $request->session()->forget(['support_session_id', 'active_company_id']);

        return true;
    }
}
