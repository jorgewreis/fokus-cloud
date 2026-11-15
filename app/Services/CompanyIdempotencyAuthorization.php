<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Locks the current administrator and the grant until the caller commits. */
final class CompanyIdempotencyAuthorization
{
    public function lockAuthorizedActor(string $actor): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Authorization must be locked within a transaction.');
        }
        $admin = DB::table('platform_admins')->where('id', $actor)->lockForUpdate()->first();
        abort_unless($admin && $admin->status === 'ativo' && $admin->manual_blocked_at === null
            && $admin->deactivated_at === null && ($admin->locked_until === null || Carbon::parse($admin->locked_until)->isPast()), 403, 'Permissão interna insuficiente.');
        $permission = DB::table('platform_permissions')->where('code', 'platform.companies.manage')->sharedLock()->first(['id']);
        abort_unless($permission && $admin->platform_role_id, 403, 'Permissão interna insuficiente.');
        $grant = DB::table('platform_role_permissions')->where('platform_role_id', $admin->platform_role_id)
            ->where('platform_permission_id', $permission->id)->sharedLock()->first();
        abort_unless($grant, 403, 'Permissão interna insuficiente.');
    }
}
