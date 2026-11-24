<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Server-side authority for the two guards that share a Laravel session.
 *
 * It is deliberately disabled by default: an authority is meaningful only if
 * the writer acquires it in the same database transaction as its mutation.
 */
final class PlatformSessionAuthority
{
    public function enabled(): bool
    {
        return (bool) config('platform_session_authority.enabled');
    }

    public function activate(Request $request, PlatformAdmin $admin, ?User $webUser = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->assertCompatibleSessionStore();
        $sessionId = $this->sessionId($request);
        $webState = $webUser ? 'known' : 'none';

        DB::transaction(function () use ($sessionId, $admin, $webUser, $webState): void {
            $previous = DB::table('platform_session_authorities')->where('session_id', $sessionId)->lockForUpdate()->first();
            $generation = $previous ? ((int) $previous->generation + 1) : 1;
            DB::table('platform_session_authorities')->updateOrInsert(
                ['session_id' => $sessionId],
                [
                    'platform_admin_id' => $admin->id,
                    'web_principal_state' => $webState,
                    'web_user_id' => $webUser?->id,
                    'generation' => $generation,
                    'state' => 'active',
                    'expires_at' => now()->addMinutes((int) config('session.lifetime', 120)),
                    'revoked_at' => null,
                    'created_at' => $previous?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    public function invalidate(Request $request): void
    {
        if (! $this->enabled() || ! $request->hasSession()) {
            return;
        }

        $this->invalidateSessionId($request->session()->getId());
    }

    /** @return list<string> session ids that must be removed with the revocation */
    public function revokeForAdmin(string $adminId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $this->assertCompatibleSessionStore();

        return DB::transaction(function () use ($adminId): array {
            $rows = DB::table('platform_session_authorities')->where('platform_admin_id', $adminId)->where('state', 'active')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                return [];
            }
            DB::table('platform_session_authorities')->whereIn('session_id', $rows->pluck('session_id'))->update([
                'state' => 'revoked',
                'generation' => DB::raw('generation + 1'),
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

            return $rows->pluck('session_id')->all();
        });
    }

    /**
     * Execute a write only while the durable authority, Laravel database
     * session, administrator and any web identity are locked together.
     *
     * @template T
     *
     * @param  callable():T  $writer
     * @return T
     */
    public function withinMutation(Request $request, string $permission, callable $writer, ?string $companyId = null, ?string $expectedAdminId = null): mixed
    {
        if (! $this->enabled()) {
            throw new LogicException('Platform session authority is not enabled.');
        }
        $this->assertCompatibleSessionStore();
        $sessionId = $this->sessionId($request);
        $platformAdminId = Auth::guard('platform')->id();
        abort_unless(is_string($platformAdminId) && $platformAdminId !== '', 401, 'Acesso interno não autenticado.');
        abort_if($expectedAdminId !== null && ! hash_equals($expectedAdminId, $platformAdminId), 403, 'Permissão interna insuficiente.');

        return DB::transaction(function () use ($sessionId, $platformAdminId, $permission, $writer, $companyId): mixed {
            if ($companyId !== null) {
                DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();
            }
            // Read only the authority identity first so a known web principal can
            // be locked before the platform admin. Its full state is re-read
            // under lock below, before any request record can be modified.
            $authoritySnapshot = DB::table('platform_session_authorities')->where('session_id', $sessionId)->first();
            abort_unless($authoritySnapshot && $authoritySnapshot->platform_admin_id === $platformAdminId, 401, 'A autoridade da sessão expirou. Entre novamente.');
            if ($authoritySnapshot->web_principal_state === 'known') {
                $web = DB::table('users')->where('id', $authoritySnapshot->web_user_id)->lockForUpdate()->first();
                abort_unless($web && $web->status === 'ativa' && Auth::guard('web')->id() === $authoritySnapshot->web_user_id, 401, 'O contexto da sessão mudou. Entre novamente.');
            }
            if ($authoritySnapshot->web_principal_state === 'none') {
                abort_if(Auth::guard('web')->id() !== null, 401, 'O contexto da sessão mudou. Entre novamente.');
            }
            abort_if($authoritySnapshot->web_principal_state === 'unknown', 401, 'O contexto da sessão exige nova autenticação.');
            $admin = DB::table('platform_admins')->where('id', $platformAdminId)->lockForUpdate()->first();
            abort_unless($admin && $admin->status === 'ativo' && $admin->manual_blocked_at === null && $admin->deactivated_at === null
                && ($admin->locked_until === null || Carbon::parse($admin->locked_until)->isPast()), 403, 'Permissão interna insuficiente.');
            $authority = DB::table('platform_session_authorities')->where('session_id', $sessionId)->lockForUpdate()->first();
            abort_unless($authority && $authority->state === 'active' && $authority->platform_admin_id === $platformAdminId
                && $authority->web_principal_state === $authoritySnapshot->web_principal_state
                && $authority->web_user_id === $authoritySnapshot->web_user_id
                && Carbon::parse($authority->expires_at)->isFuture(), 401, 'A autoridade da sessão expirou. Entre novamente.');
            $session = DB::table(config('session.table', 'sessions'))->where('id', $sessionId)->lockForUpdate()->first();
            abort_unless($session, 401, 'A autoridade da sessão expirou. Entre novamente.');
            $idleDeadline = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;
            abort_if((int) $session->last_activity <= $idleDeadline, 401, 'A sessão expirou. Entre novamente.');
            $grant = DB::table('platform_role_permissions as grant')
                ->join('platform_permissions as permission', 'permission.id', '=', 'grant.platform_permission_id')
                ->where('grant.platform_role_id', $admin->platform_role_id)->where('permission.code', $permission)->sharedLock()->exists();
            abort_unless($grant, 403, 'Permissão interna insuficiente.');

            return $writer();
        });
    }

    private function invalidateSessionId(string $sessionId): void
    {
        $this->assertCompatibleSessionStore();
        DB::transaction(function () use ($sessionId): void {
            $authority = DB::table('platform_session_authorities')->where('session_id', $sessionId)->lockForUpdate()->first();
            if (! $authority || $authority->state === 'revoked') {
                return;
            }
            DB::table('platform_session_authorities')->where('session_id', $sessionId)->update([
                'state' => 'revoked',
                'generation' => (int) $authority->generation + 1,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function assertCompatibleSessionStore(): void
    {
        $connection = config('session.connection') ?: config('database.default');
        abort_unless(config('session.driver') === 'database' && $connection === DB::getDefaultConnection(), 503, 'A autoridade da sessão requer armazenamento de sessão no banco principal.');
    }

    private function sessionId(Request $request): string
    {
        abort_unless($request->hasSession() && is_string($request->session()->getId()) && $request->session()->getId() !== '', 503, 'Não foi possível obter a sessão atual.');

        return $request->session()->getId();
    }
}
