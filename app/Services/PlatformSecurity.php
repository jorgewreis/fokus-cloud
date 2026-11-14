<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformSecurity
{
    public function deviceHash(Request $request): string
    {
        return hash('sha256', $request->cookie('platform_device_id') ?: 'missing:'.$request->userAgent());
    }

    public function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public function recordAttempt(?PlatformAdmin $admin, string $email, Request $request, string $outcome): void
    {
        DB::table('platform_login_attempts')->insert(['id' => PrefixedUlid::make('PLA'), 'platform_admin_id' => $admin?->id, 'email_hash' => $this->emailHash($email), 'ip_address' => $request->ip(), 'device_hash' => $this->deviceHash($request), 'outcome' => $outcome, 'created_at' => now()]);
    }

    public function originIsBlocked(Request $request): bool
    {
        return DB::table('platform_login_attempts')->where('created_at', '>=', now()->subMinutes(10))->whereIn('outcome', ['password_failed', 'account_locked'])->where(fn ($query) => $query->where('ip_address', $request->ip())->orWhere('device_hash', $this->deviceHash($request)))->distinct('email_hash')->count('email_hash') >= 5;
    }

    public function applyFailurePolicy(PlatformAdmin $admin, Request $request, PlatformAudit $audit): void
    {
        $now = now();
        $recentFailures = DB::table('platform_login_attempts')->where('platform_admin_id', $admin->id)->where('outcome', 'password_failed')->where('created_at', '>=', $now->copy()->subMinutes(10))->get();
        $count = $recentFailures->count();
        $admin->forceFill(['failed_login_count' => $count, 'failed_login_window_started_at' => $recentFailures->min('created_at') ?: $now])->save();
        $dailyFailures = DB::table('platform_login_attempts')->where('platform_admin_id', $admin->id)->where('outcome', 'password_failed')->where('created_at', '>=', $now->copy()->subDay())->count();

        if ($dailyFailures >= 5) {
            $admin->forceFill(['manual_blocked_at' => $now, 'blocked_reason' => 'Cinco falhas de autenticação interna em 24 horas.', 'locked_until' => null])->save();
            $audit->record($admin->id, 'backoffice.account_manually_blocked', 'platform_admin', $admin->id, reason: $admin->blocked_reason, after: $this->maskedAdmin($admin), request: $request);
            $this->revokeSessions($admin->id);
        } elseif ($count >= 3) {
            $admin->forceFill(['locked_until' => $now->copy()->addMinutes(10)])->save();
            $audit->record($admin->id, 'backoffice.account_temporarily_locked', 'platform_admin', $admin->id, reason: 'Três falhas em dez minutos.', after: $this->maskedAdmin($admin), request: $request);
        }
    }

    public function resetFailures(PlatformAdmin $admin): void
    {
        $admin->forceFill(['failed_login_count' => 0, 'failed_login_window_started_at' => null, 'locked_until' => null])->save();
    }

    public function revokeSessions(string $adminId): void
    {
        app(PlatformSessionLifecycle::class)->revokeAll($adminId);
        DB::table('sessions')->where('user_id', $adminId)->delete();
    }

    public function maskedAdmin(PlatformAdmin $admin): array
    {
        return ['id' => $admin->id, 'email' => preg_replace('/^(.{2}).+(@.+)$/', '$1***$2', $admin->email), 'role_id' => $admin->platform_role_id, 'status' => $admin->status, 'locked_until' => $admin->locked_until?->toIso8601String(), 'manual_blocked_at' => $admin->manual_blocked_at?->toIso8601String(), 'deactivated_at' => $admin->deactivated_at?->toIso8601String()];
    }
}
