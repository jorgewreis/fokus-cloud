<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LawAuthorizationService
{
    private const SYSTEM_PERMISSION_RESOURCES = ['company', 'units', 'subscription', 'notifications', 'users', 'roles'];

    private const MODULE_PERMISSION_RESOURCES = [
        'process' => 'processos',
        'processes' => 'processos',
        'cases' => 'processos',
        'contacts' => 'contatos',
        'expeditions' => 'expedicoes',
        'filings' => 'expedicoes',
        'tasks' => 'tarefas',
        'hearings' => 'audiencias',
        'reports' => 'relatorios',
    ];

    private const DEFAULT_ROLES = [
        'unit_admin' => ['Administrador do setor', 'law.company.view,law.notifications.view,law.notifications.update,law.contacts.view,law.contacts.create,law.contacts.update,law.contacts.delete,law.contacts.sensitive.view,law.contacts.merge,law.contacts.shared.view,law.hearings.view,law.hearings.create,law.hearings.update,law.hearings.delete,law.hearings.status.update,law.hearings.external_access.manage,law.users.view,law.users.manage,law.roles.view,law.roles.manage'],
        'chief_clerk' => ['Chefe / Escrivão', 'law.company.view,law.units.view,law.notifications.view,law.notifications.update,law.contacts.view,law.contacts.create,law.contacts.update,law.contacts.delete,law.contacts.sensitive.view,law.contacts.merge,law.contacts.shared.view,law.hearings.view,law.hearings.create,law.hearings.update,law.hearings.delete,law.hearings.status.update,law.hearings.external_access.manage,law.users.view,law.users.manage,law.roles.view'],
        'operator' => ['Operador', 'law.company.view,law.notifications.view,law.notifications.update,law.contacts.view,law.contacts.create,law.contacts.update,law.contacts.shared.view,law.hearings.view,law.hearings.create,law.hearings.update,law.hearings.status.update'],
        'viewer' => ['Somente leitura', 'law.company.view,law.notifications.view,law.contacts.view,law.contacts.shared.view,law.hearings.view,law.roles.view'],
    ];

    public function isCompanyAdmin(Request $request): bool
    {
        return $request->attributes->get('active_membership')?->role === 'admin';
    }

    public function activeUnitId(Request $request): ?string
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $unitId = DB::table('law_user_active_units as active')
            ->join('law_units as unit', function ($join): void {
                $join->on('unit.id', '=', 'active.law_unit_id')->on('unit.company_id', '=', 'active.company_id');
            })
            ->where('active.user_id', $request->user()->id)
            ->where('active.company_id', $companyId)
            ->where('unit.status', 'ativo')
            ->value('active.law_unit_id');

        if (! $unitId) return null;
        if ($this->isCompanyAdmin($request)) return (string) $unitId;

        $authorized = DB::table('law_unit_memberships')
            ->where('company_id', $companyId)->where('law_unit_id', $unitId)
            ->where('company_membership_id', $request->attributes->get('active_membership')->id)
            ->where('status', 'ativo')->whereNull('deleted_at')->exists();
        return $authorized ? (string) $unitId : null;
    }

    public function accessibleUnits(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $query = DB::table('law_units as unit')->where('unit.company_id', $companyId)->where('unit.status', 'ativo');
        if (! $this->isCompanyAdmin($request)) {
            $query->join('law_unit_memberships as lum', function ($join): void {
                $join->on('lum.law_unit_id', '=', 'unit.id')->on('lum.company_id', '=', 'unit.company_id');
            })->where('lum.company_membership_id', $request->attributes->get('active_membership')->id)
                ->where('lum.status', 'ativo')->whereNull('lum.deleted_at');
        }
        return $query->orderBy('unit.name')->get(['unit.id', 'unit.name', 'unit.status'])->unique('id')->values();
    }

    public function can(Request $request, string $permission, ?string $unitId = null): bool
    {
        if (! in_array($permission, $this->availablePermissionCodes($request), true)) return false;
        if ($this->isCompanyAdmin($request)) return true;
        $unitId ??= $this->activeUnitId($request);
        if (! $unitId) return false;
        return DB::table('law_unit_memberships as lum')
            ->join('law_access_role_permissions as role_permission', 'role_permission.law_access_role_id', '=', 'lum.law_access_role_id')
            ->join('customer_permissions as permission', 'permission.id', '=', 'role_permission.customer_permission_id')
            ->where('lum.company_id', $request->attributes->get('active_company_id'))
            ->where('lum.law_unit_id', $unitId)
            ->where('lum.company_membership_id', $request->attributes->get('active_membership')->id)
            ->where('lum.status', 'ativo')->whereNull('lum.deleted_at')
            ->where('permission.code', $permission)->exists();
    }

    public function authorize(Request $request, string $permission, ?string $unitId = null): void
    {
        abort_unless($this->can($request, $permission, $unitId), 403, 'Você não tem permissão para esta ação neste setor.');
    }

    public function permissions(Request $request, ?string $unitId = null): array
    {
        $available = $this->availablePermissionCodes($request);
        if ($this->isCompanyAdmin($request)) {
            return $available;
        }
        $unitId ??= $this->activeUnitId($request);
        if (! $unitId) return [];
        return DB::table('law_unit_memberships as lum')
            ->join('law_access_role_permissions as rp', 'rp.law_access_role_id', '=', 'lum.law_access_role_id')
            ->join('customer_permissions as permission', 'permission.id', '=', 'rp.customer_permission_id')
            ->where('lum.company_id', $request->attributes->get('active_company_id'))
            ->where('lum.law_unit_id', $unitId)
            ->where('lum.company_membership_id', $request->attributes->get('active_membership')->id)
            ->where('lum.status', 'ativo')->whereNull('lum.deleted_at')->orderBy('permission.code')
            ->pluck('permission.code')->intersect($available)->values()->all();
    }

    /**
     * Return system permissions and permissions for modules included in an active Fokus Law subscription.
     * This is also used by authorization checks so hiding an option in the UI is not the only control.
     */
    public function availablePermissionCodes(Request $request): array
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        if ($companyId === '') return [];

        $enabledModules = DB::table('subscription_items as item')
            ->join('subscriptions as subscription', 'subscription.id', '=', 'item.subscription_id')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->join('modules as module', 'module.id', '=', 'item.module_id')
            ->where('subscription.company_id', $companyId)
            ->where('subscription.status', 'ativa')
            ->whereIn('product.code', ['law', 'fokus-law'])
            ->where('module.status', 'ativo')
            ->where('module.publication_state', 'publicado')
            ->selectRaw('COALESCE(module.module_code, module.code) as module_code')
            ->pluck('module_code')
            ->filter()
            ->map(fn ($code): string => strtolower((string) $code))
            ->unique()
            ->all();

        return DB::table('customer_permissions')
            ->where('product_code', 'law')
            ->orderBy('code')
            ->get(['code', 'resource'])
            ->filter(function (object $permission) use ($enabledModules): bool {
                $resource = strtolower((string) $permission->resource);
                if (in_array($resource, self::SYSTEM_PERMISSION_RESOURCES, true)) return true;
                $moduleCode = self::MODULE_PERMISSION_RESOURCES[$resource] ?? null;
                return $moduleCode !== null && in_array($moduleCode, $enabledModules, true);
            })
            ->pluck('code')
            ->values()
            ->all();
    }

    public function provisionUnitRoles(string $companyId, string $unitId, ?string $actorId = null): array
    {
        $permissionIds = DB::table('customer_permissions')->where('product_code', 'law')->pluck('id', 'code')->all();
        $roleIds = [];
        foreach (self::DEFAULT_ROLES as $code => [$name, $permissionList]) {
            $permissionList .= ',law.cases.view';
            if ($code !== 'viewer') $permissionList .= ',law.cases.create,law.cases.update,law.cases.archive,law.cases.reopen';
            if (in_array($code, ['unit_admin', 'chief_clerk'], true)) $permissionList .= ',law.cases.access.manage,law.cases.configure';
            $existing = DB::table('law_access_roles')->where('company_id', $companyId)->where('law_unit_id', $unitId)->where('code', $code)->first();
            $id = $existing?->id ?: PrefixedUlid::make('LAR');
            if (! $existing) {
                DB::table('law_access_roles')->insert([
                    'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unitId,
                    'code' => $code, 'name' => $name, 'is_system' => true, 'created_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $codes = array_filter(explode(',', $permissionList));
                foreach ($codes as $permissionCode) {
                    if (! isset($permissionIds[$permissionCode])) continue;
                    DB::table('law_access_role_permissions')->insert([
                        'law_access_role_id' => $id, 'customer_permission_id' => $permissionIds[$permissionCode],
                    ]);
                }
            }
            $roleIds[$code] = $id;
        }
        return $roleIds;
    }

    public function rolePermissions(string $roleId, Request $request): array
    {
        $permissions = DB::table('law_access_role_permissions as rp')
            ->join('customer_permissions as permission', 'permission.id', '=', 'rp.customer_permission_id')
            ->where('rp.law_access_role_id', $roleId)->pluck('permission.code')->all();
        return array_values(array_intersect($permissions, $this->availablePermissionCodes($request)));
    }

    public function actorMayAssignRole(Request $request, object $role, ?string $unitId = null): bool
    {
        if ($this->isCompanyAdmin($request)) return true;
        if ($role->code === 'unit_admin') return false;
        $actorPermissions = $this->permissions($request, $unitId);
        return count(array_diff($this->rolePermissions((string) $role->id, $request), $actorPermissions)) === 0;
    }
}
