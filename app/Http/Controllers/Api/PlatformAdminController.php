<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PlatformAudit;
use App\Services\PlatformSecurity;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformAdminController extends Controller
{
    public function index()
    {
        return response()->json(['admins' => PlatformAdmin::with('role.permissions')->orderBy('name')->get()->map(fn (PlatformAdmin $admin) => $this->payload($admin))]);
    }

    public function updateProfile(Request $request, PlatformAdmin $admin, PlatformAudit $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('platform_admins', 'email')->ignore($admin->id)],
        ]);
        $name = trim($data['name']);
        $email = Str::lower(trim($data['email']));
        $before = ['name' => $admin->name, 'email' => $admin->email];
        $pendingEmail = null;

        DB::transaction(function () use ($admin, $name, $email, &$pendingEmail): void {
            $locked = PlatformAdmin::query()->lockForUpdate()->findOrFail($admin->id);
            $locked->forceFill(['name' => $name])->save();
            if ($email === Str::lower($locked->email)) {
                return;
            }

            abort_if(PlatformAdmin::where('email', $email)->where('id', '!=', $locked->id)->exists(), 422, 'Este e-mail já pertence a uma conta interna.');
            DB::table('platform_admin_email_changes')->where('platform_admin_id', $locked->id)->whereNull('used_at')->whereNull('superseded_at')->update(['superseded_at' => now(), 'updated_at' => now()]);
            $plain = Str::random(64);
            DB::table('platform_admin_email_changes')->insert([
                'id' => PrefixedUlid::make('PAE'),
                'platform_admin_id' => $locked->id,
                'new_email' => $email,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addHours(24),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $pendingEmail = ['email' => $email, 'token' => $plain, 'old_email' => $locked->email];
        });

        if ($pendingEmail) {
            $url = rtrim(config('app.url'), '/').'/backoffice/confirmar-email?token='.urlencode($pendingEmail['token']);
            Mail::raw("Foi solicitada uma alteração do e-mail da sua conta interna Fokus Cloud. Confirme o novo endereço em até 24 horas: {$url}", fn ($mail) => $mail->to($pendingEmail['email'])->subject('Fokus Cloud: confirme seu novo e-mail interno'));
            Mail::raw('Foi solicitada a alteração do e-mail desta conta interna. O endereço atual continuará ativo até que o novo endereço seja confirmado.', fn ($mail) => $mail->to($pendingEmail['old_email'])->subject('Fokus Cloud: alteração de e-mail solicitada'));
        }

        $updated = $admin->fresh('role');
        $after = ['name' => $updated->name, 'email' => $pendingEmail['email'] ?? $updated->email, 'email_change_pending' => (bool) $pendingEmail];
        $audit->record($request->user()->id, 'backoffice.admin_profile_updated', 'platform_admin', $admin->id, reason: 'Atualização de nome e/ou solicitação de alteração de e-mail.', before: $before, after: $after, request: $request);
        return response()->json([
            'message' => $pendingEmail ? 'Nome atualizado. Confirme o novo e-mail para concluir a alteração; o endereço atual continua ativo até lá.' : 'Dados da conta atualizados.',
            'email_change_pending' => (bool) $pendingEmail,
            'admin' => $this->payload($updated),
        ]);
    }

    public function confirmEmailChange(Request $request, PlatformAudit $audit, PlatformSecurity $security)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $result = DB::transaction(function () use ($data): array {
            $change = DB::table('platform_admin_email_changes')->where('token_hash', hash('sha256', $data['token']))
                ->whereNull('used_at')->whereNull('superseded_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($change, 422, 'Link inválido ou expirado.');
            $admin = PlatformAdmin::query()->lockForUpdate()->findOrFail($change->platform_admin_id);
            abort_if(PlatformAdmin::where('email', $change->new_email)->where('id', '!=', $admin->id)->exists(), 422, 'Este e-mail já pertence a outra conta interna. Solicite um novo link com outro endereço.');
            DB::table('platform_admin_email_changes')->where('platform_admin_id', $admin->id)->whereNull('used_at')->whereNull('superseded_at')->update(['superseded_at' => now(), 'updated_at' => now()]);
            DB::table('platform_admin_email_changes')->where('id', $change->id)->update(['used_at' => now(), 'updated_at' => now()]);
            $oldEmail = $admin->email;
            $admin->forceFill(['email' => $change->new_email, 'email_verified_at' => now()])->save();
            return ['admin' => $admin->fresh('role'), 'old_email' => $oldEmail, 'new_email' => $change->new_email];
        });
        Mail::raw('O e-mail da conta interna Fokus Cloud foi alterado com sucesso. Se você não solicitou essa alteração, contate o suporte.', fn ($mail) => $mail->to($result['old_email'])->subject('Fokus Cloud: e-mail da conta alterado'));
        $audit->record($result['admin']->id, 'backoffice.admin_email_changed', 'platform_admin', $result['admin']->id, before: ['email' => $result['old_email']], after: ['email' => $result['new_email']], request: $request);
        $security->revokeSessions($result['admin']->id);

        return response()->json(['message' => 'Novo e-mail confirmado. Entre novamente com o endereço atualizado.']);
    }

    public function invite(Request $request, PlatformAudit $audit, PlatformSecurity $security)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255', 'unique:platform_admins,email'], 'role' => ['required', Rule::in(['superadministrador', 'administrador_comercial'])]]);
        $role = PlatformRole::where('code', $data['role'])->firstOrFail();
        $admin = PlatformAdmin::create(['id' => PrefixedUlid::make('PAD'), 'name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Hash::make(Str::random(64)), 'status' => 'suspenso', 'platform_role_id' => $role->id]);
        $token = Str::random(64);
        DB::table('platform_admin_invitations')->insert(['id' => PrefixedUlid::make('PAI'), 'platform_admin_id' => $admin->id, 'invited_by_platform_admin_id' => $request->user()->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(24), 'created_at' => now(), 'updated_at' => now()]);
        Mail::raw('Você recebeu um convite para o Backoffice Fokus Cloud. Ative sua conta em '.url('/backoffice/ativar?token='.$token).'. O link expira em 24 horas.', fn ($mail) => $mail->to($admin->email)->subject('Fokus Cloud: convite para Backoffice'));
        $audit->record($request->user()->id, 'backoffice.admin_invited', 'platform_admin', $admin->id, after: $security->maskedAdmin($admin), request: $request);

        return response()->json(['id' => $admin->id, 'message' => 'Convite enviado.'], 201);
    }

    public function updateRole(Request $request, PlatformAdmin $admin, PlatformAudit $audit, PlatformSecurity $security)
    {
        $data = $request->validate(['role' => ['required', Rule::in(['superadministrador', 'administrador_comercial'])], 'reason' => ['required', 'string', 'max:1000']]);
        $role = PlatformRole::where('code', $data['role'])->firstOrFail();
        abort_if($admin->role?->code === 'superadministrador' && $role->code !== 'superadministrador' && $this->isLastActiveSuperadmin($admin), 422, 'A última conta ativa de superadministrador não pode ter o perfil alterado.');
        $before = $security->maskedAdmin($admin);
        $admin->forceFill(['platform_role_id' => $role->id])->save();
        $security->revokeSessions($admin->id);
        $audit->record($request->user()->id, 'backoffice.admin_role_changed', 'platform_admin', $admin->id, reason: $data['reason'], before: $before, after: $security->maskedAdmin($admin), request: $request);

        return response()->json(['admin' => $this->payload($admin->fresh('role.permissions'))]);
    }

    public function block(Request $request, PlatformAdmin $admin, PlatformAudit $audit, PlatformSecurity $security)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_if($this->isLastActiveSuperadmin($admin), 422, 'A última conta ativa de superadministrador não pode ser bloqueada.');
        $before = $security->maskedAdmin($admin);
        $admin->forceFill(['manual_blocked_at' => now(), 'manual_blocked_by' => $request->user()->id, 'blocked_reason' => $data['reason']])->save();
        $security->revokeSessions($admin->id);
        $audit->record($request->user()->id, 'backoffice.admin_blocked', 'platform_admin', $admin->id, reason: $data['reason'], before: $before, after: $security->maskedAdmin($admin), request: $request);

        return response()->json(['message' => 'Conta interna bloqueada.']);
    }

    public function unblock(Request $request, PlatformAdmin $admin, PlatformAudit $audit, PlatformSecurity $security)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $before = $security->maskedAdmin($admin);
        $admin->forceFill(['manual_blocked_at' => null, 'manual_blocked_by' => null, 'blocked_reason' => null, 'locked_until' => null, 'failed_login_count' => 0, 'failed_login_window_started_at' => null])->save();
        $audit->record($request->user()->id, 'backoffice.admin_unblocked', 'platform_admin', $admin->id, reason: $data['reason'], before: $before, after: $security->maskedAdmin($admin), request: $request);

        return response()->json(['message' => 'Conta interna desbloqueada.']);
    }

    public function deactivate(Request $request, PlatformAdmin $admin, PlatformAudit $audit, PlatformSecurity $security)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_if($this->isLastActiveSuperadmin($admin), 422, 'A última conta ativa de superadministrador não pode ser desativada.');
        $before = $security->maskedAdmin($admin);
        $admin->forceFill(['status' => 'suspenso', 'deactivated_at' => now(), 'blocked_reason' => $data['reason']])->save();
        $security->revokeSessions($admin->id);
        $audit->record($request->user()->id, 'backoffice.admin_deactivated', 'platform_admin', $admin->id, reason: $data['reason'], before: $before, after: $security->maskedAdmin($admin), request: $request);

        return response()->json(['message' => 'Conta interna desativada.']);
    }

    public function securityEvents(PlatformAdmin $admin)
    {
        return response()->json(['events' => DB::table('platform_audit_events')->where('entity_type', 'platform_admin')->where('entity_id', $admin->id)->orderByDesc('created_at')->limit(100)->get()]);
    }

    private function isLastActiveSuperadmin(PlatformAdmin $admin): bool
    {
        if ($admin->role?->code !== 'superadministrador' || $admin->status !== 'ativo' || $admin->manual_blocked_at || $admin->deactivated_at) {
            return false;
        }

        return PlatformAdmin::where('status', 'ativo')->whereNull('manual_blocked_at')->whereNull('deactivated_at')->whereHas('role', fn ($query) => $query->where('code', 'superadministrador'))->count() === 1;
    }

    private function payload(PlatformAdmin $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => preg_replace('/^(.{2}).+(@.+)$/', '$1***$2', $admin->email), 'role' => $admin->role?->code, 'status' => $admin->deactivated_at ? 'desativado' : ($admin->manual_blocked_at ? 'bloqueado' : ($admin->locked_until && $admin->locked_until->isFuture() ? 'bloqueio_temporario' : $admin->status)), 'last_login_at' => $admin->last_login_at, 'locked_until' => $admin->locked_until];
    }
}
