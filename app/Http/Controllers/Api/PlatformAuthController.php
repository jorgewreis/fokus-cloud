<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\PlatformAudit;
use App\Services\PlatformSecurity;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PlatformAuthController extends Controller
{
    public function login(Request $request, PlatformAudit $audit, PlatformSecurity $security)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc'], 'password' => ['required', 'string']]);
        $email = strtolower($data['email']);
        $admin = PlatformAdmin::where('email', $email)->first();

        if ($security->originIsBlocked($request)) {
            $security->recordAttempt($admin, $email, $request, 'origin_locked');
            $audit->record($admin?->id, 'backoffice.login_origin_locked', 'platform_admin', $admin?->id, request: $request);

            return response()->json(['message' => 'Origem temporariamente bloqueada. Tente novamente mais tarde.'], 429);
        }

        if (! $admin || ! $admin->isAvailableForLogin() || ! Hash::check($data['password'], $admin->password)) {
            $security->recordAttempt($admin, $email, $request, $admin && ! $admin->isAvailableForLogin() ? 'account_locked' : 'password_failed');
            if ($admin && ! $admin->manual_blocked_at && ! $admin->deactivated_at) {
                $security->applyFailurePolicy($admin, $request, $audit);
            }
            $audit->record($admin?->id, 'backoffice.login_failed', 'platform_admin', $admin?->id, request: $request);

            return $this->denied();
        }

        $security->resetFailures($admin);

        return $this->issueMfa($request, $admin, $audit);
    }

    public function resendMfa(Request $request, PlatformAudit $audit)
    {
        $adminId = $request->session()->get('platform_pending_admin_id');
        abort_unless($adminId, 401, 'Inicie o acesso interno novamente.');
        $challenge = DB::table('platform_login_challenges')->where('platform_admin_id', $adminId)->whereNull('used_at')->latest('created_at')->first();
        abort_if(! $challenge || now()->lt($challenge->resend_available_at), 429, 'Aguarde um minuto para solicitar outro código.');

        return $this->issueMfa($request, PlatformAdmin::findOrFail($adminId), $audit);
    }

    public function verifyMfa(Request $request, PlatformAudit $audit)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $adminId = $request->session()->get('platform_pending_admin_id');
        abort_unless($adminId, 401, 'Inicie o acesso interno novamente.');
        $challenge = DB::table('platform_login_challenges')->where('platform_admin_id', $adminId)->whereNull('used_at')->where('expires_at', '>', now())->latest('created_at')->first();
        if (! $challenge || ! $this->matchesMfaCode($challenge->code_hash, $data['code'])) {
            if ($challenge) {
                $attempts = $challenge->attempt_count + 1;
                DB::table('platform_login_challenges')->where('id', $challenge->id)->update(['attempt_count' => $attempts, 'used_at' => $attempts >= 5 ? now() : null, 'updated_at' => now()]);
            }
            $audit->record($adminId, 'backoffice.mfa_failed', 'platform_admin', $adminId, request: $request);

            return response()->json(['message' => 'Código inválido ou expirado.'], 422);
        }

        DB::table('platform_login_challenges')->where('id', $challenge->id)->update(['used_at' => now(), 'updated_at' => now()]);
        $admin = PlatformAdmin::findOrFail($adminId);
        abort_unless($admin->isAvailableForLogin(), 401, 'Acesso interno indisponível.');
        Auth::guard('platform')->login($admin);
        $request->session()->forget('platform_pending_admin_id');
        $request->session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();
        $audit->record($admin->id, 'backoffice.login_succeeded', 'platform_admin', $admin->id, request: $request);

        return response()->json(['admin' => $this->adminPayload($admin)]);
    }

    public function activateInvitation(Request $request, PlatformAudit $audit)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        $admin = DB::transaction(function () use ($data) {
            $invitation = DB::table('platform_admin_invitations')
                ->where('token_hash', hash('sha256', $data['token']))
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            abort_unless($invitation, 422, 'Convite inválido ou expirado.');

            $admin = PlatformAdmin::lockForUpdate()->findOrFail($invitation->platform_admin_id);
            $admin->forceFill(['password' => Hash::make($data['password']), 'status' => 'ativo', 'email_verified_at' => now()])->save();
            DB::table('platform_admin_invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'updated_at' => now()]);

            return $admin;
        });
        $audit->record($admin->id, 'backoffice.admin_invitation_accepted', 'platform_admin', $admin->id, after: ['id' => $admin->id, 'status' => 'ativo'], request: $request);

        return response()->json(['message' => 'Conta interna ativada. Entre com seu e-mail e senha.']);
    }

    public function me(Request $request)
    {
        $admin = Auth::guard('platform')->user();
        abort_unless($admin, 401, 'Acesso interno não autenticado.');

        return response()->json(['admin' => $this->adminPayload($admin)]);
    }

    public function logout(Request $request, PlatformAudit $audit)
    {
        $adminId = Auth::guard('platform')->id();
        $supportId = $request->session()->get('support_session_id');
        if ($supportId) {
            $support = DB::table('platform_support_sessions')->where('id', $supportId)->whereNull('ended_at')->first();
            if ($support) {
                DB::table('platform_support_sessions')->where('id', $supportId)->update(['ended_at' => now(), 'end_ip' => $request->ip(), 'end_user_agent' => $request->userAgent(), 'updated_at' => now()]);
                $audit->record($adminId, 'backoffice.support_access_ended', 'platform_support_session', $supportId, $support->company_id, 'Sessão encerrada ao sair do Backoffice.', request: $request);
            }
        }
        $audit->record($adminId, 'backoffice.logout', request: $request);
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function issueMfa(Request $request, PlatformAdmin $admin, PlatformAudit $audit)
    {
        $code = (string) random_int(100000, 999999);
        DB::table('platform_login_challenges')->where('platform_admin_id', $admin->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
        try {
            Mail::raw("Seu código de acesso ao backoffice Fokus Cloud é: {$code}. Ele expira em 10 minutos.", fn ($mail) => $mail->to($admin->email)->subject('Fokus Cloud: código de acesso interno'));
        } catch (\Throwable) {
            $request->session()->forget('platform_pending_admin_id');
            $audit->record($admin->id, 'backoffice.mfa_delivery_failed', 'platform_admin', $admin->id, request: $request);

            return response()->json(['message' => 'Não foi possível enviar o código de acesso. Tente novamente em alguns minutos.'], 503);
        }
        DB::table('platform_login_challenges')->insert(['id' => PrefixedUlid::make('MFA'), 'platform_admin_id' => $admin->id, 'code_hash' => Hash::make($code), 'attempt_count' => 0, 'expires_at' => now()->addMinutes(10), 'resend_available_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
        $request->session()->put('platform_pending_admin_id', $admin->id);
        $audit->record($admin->id, 'backoffice.mfa_requested', 'platform_admin', $admin->id, request: $request);
        $response = response()->json(['mfa_required' => true, 'message' => 'Enviamos um código de acesso ao seu e-mail.']);
        if (! $request->cookie('platform_device_id')) {
            $response->withCookie(cookie('platform_device_id', Str::random(64), 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'Lax'));
        }

        return $response;
    }

    private function denied()
    {
        return response()->json(['message' => 'Credenciais internas inválidas.'], 422);
    }

    private function matchesMfaCode(string $storedHash, string $code): bool
    {
        // Existing challenges retain compatibility only until their 10-minute expiry.
        if (strlen($storedHash) === 64 && ctype_xdigit($storedHash)) {
            return hash_equals($storedHash, hash('sha256', $code));
        }

        return Hash::check($code, $storedHash);
    }

    private function adminPayload(PlatformAdmin $admin): array
    {
        $admin->loadMissing('role.permissions');

        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role?->code, 'permissions' => $admin->role?->permissions->pluck('code')->values() ?? []];
    }
}
