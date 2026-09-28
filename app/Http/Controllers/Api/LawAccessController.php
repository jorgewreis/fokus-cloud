<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LawAuthorizationService;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LawAccessController extends Controller
{
    public function permissions(Request $request, LawAuthorizationService $authorization)
    {
        $unitId = $this->unitId($request, $authorization);
        $authorization->authorize($request, 'law.roles.view', $unitId);
        $granted = $authorization->permissions($request, $unitId);
        $permissions = DB::table('customer_permissions')->where('product_code', 'law')->orderBy('resource')->orderBy('action')->get(['code', 'resource', 'action', 'description']);
        return response()->json(['permissions' => $permissions->map(fn ($permission): array => [
            'code' => (string) $permission->code,
            'resource' => (string) $permission->resource,
            'action' => (string) $permission->action,
            'description' => (string) $permission->description,
            'granted_to_actor' => in_array($permission->code, $granted, true),
        ])->values()]);
    }

    public function roles(Request $request, LawAuthorizationService $authorization)
    {
        $unitId = $this->unitId($request, $authorization);
        $authorization->authorize($request, 'law.roles.view', $unitId);
        $roles = DB::table('law_access_roles')->where('company_id', $request->attributes->get('active_company_id'))->where('law_unit_id', $unitId)->orderByDesc('is_system')->orderBy('name')->get();
        $isAdmin = $authorization->isCompanyAdmin($request);
        return response()->json(['roles' => $roles->map(fn ($role): array => [
            'id' => (string) $role->id, 'code' => (string) $role->code, 'name' => (string) $role->name,
            'is_system' => (bool) $role->is_system, 'version' => (int) $role->version,
            'assignable' => $isAdmin || $authorization->actorMayAssignRole($request, $role, $unitId),
            'permissions' => $authorization->rolePermissions((string) $role->id),
        ])->values()]);
    }

    public function store(Request $request, LawAuthorizationService $authorization)
    {
        $data = $request->validate([
            'law_unit_id' => ['required', 'string', 'size:30'], 'name' => ['required', 'string', 'min:2', 'max:100'],
            'permission_codes' => ['required', 'array', 'min:1'], 'permission_codes.*' => ['required', 'string', 'distinct', Rule::exists('customer_permissions', 'code')->where('product_code', 'law')],
        ]);
        $unitId = $this->authorizedUnit($request, $authorization, $data['law_unit_id'], 'law.roles.manage');
        $permissions = $authorization->permissions($request, $unitId);
        abort_if(count(array_diff($data['permission_codes'], $permissions)) > 0, 403, 'O perfil não pode conceder permissões que você não possui.');
        $companyId = (string) $request->attributes->get('active_company_id');
        $name = trim($data['name']);
        abort_if(DB::table('law_access_roles')->where('company_id', $companyId)->where('law_unit_id', $unitId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists(), 409, 'Já existe um perfil com esse nome neste setor.');
        $id = PrefixedUlid::make('LAR');
        DB::transaction(function () use ($request, $data, $companyId, $unitId, $name, $id): void {
            DB::table('law_access_roles')->insert([
                'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unitId,
                'code' => 'custom-'.$id, 'name' => $name, 'is_system' => false,
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $permissionIds = DB::table('customer_permissions')->whereIn('code', $data['permission_codes'])->pluck('id');
            foreach ($permissionIds as $permissionId) DB::table('law_access_role_permissions')->insert(['law_access_role_id' => $id, 'customer_permission_id' => $permissionId]);
            app(\App\Services\AuditRecorder::class)->company($companyId, $request->user()->id, 'law_access_role', $id, 'create', null, ['name' => $name, 'permissions' => $data['permission_codes'], 'law_unit_id' => $unitId], request: $request);
        });
        return response()->json(['id' => $id, 'name' => $name, 'version' => 1], 201);
    }

    public function update(Request $request, string $roleId, LawAuthorizationService $authorization)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'version' => ['required', 'integer', 'min:1'],
            'permission_codes' => ['sometimes', 'required', 'array', 'min:1'],
            'permission_codes.*' => ['required', 'string', 'distinct', Rule::exists('customer_permissions', 'code')->where('product_code', 'law')],
        ]);
        $role = DB::table('law_access_roles')->where('id', $roleId)->where('company_id', $request->attributes->get('active_company_id'))->first();
        abort_unless($role, 404, 'Perfil não encontrado.');
        $authorization->authorize($request, 'law.roles.manage', (string) $role->law_unit_id);
        abort_if($role->is_system, 422, 'Os perfis padrão não podem ser editados.');
        if (array_key_exists('permission_codes', $data)) {
            abort_if(count(array_diff($data['permission_codes'], $authorization->permissions($request, (string) $role->law_unit_id))) > 0, 403, 'O perfil não pode conceder permissões que você não possui.');
        }
        $newName = trim($data['name'] ?? $role->name);
        if ($newName !== $role->name) abort_if(DB::table('law_access_roles')->where('company_id', $role->company_id)->where('law_unit_id', $role->law_unit_id)->where('id', '!=', $roleId)->whereRaw('LOWER(name) = ?', [mb_strtolower($newName)])->exists(), 409, 'Já existe um perfil com esse nome neste setor.');
        DB::transaction(function () use ($request, $data, $role, $roleId, $newName): void {
            $updated = DB::table('law_access_roles')->where('id', $roleId)->where('version', $data['version'])
                ->update(['name' => $newName, 'version' => $role->version + 1, 'updated_at' => now()]);
            abort_unless($updated, 409, 'Este perfil foi alterado por outra pessoa. Atualize a tela e tente novamente.');
            if (array_key_exists('permission_codes', $data)) {
                DB::table('law_access_role_permissions')->where('law_access_role_id', $roleId)->delete();
                $permissionIds = DB::table('customer_permissions')->whereIn('code', $data['permission_codes'])->pluck('id');
                foreach ($permissionIds as $permissionId) DB::table('law_access_role_permissions')->insert(['law_access_role_id' => $roleId, 'customer_permission_id' => $permissionId]);
            }
            app(\App\Services\AuditRecorder::class)->company((string) $role->company_id, $request->user()->id, 'law_access_role', $roleId, 'update', ['name' => $role->name], ['name' => $newName, 'permissions' => $data['permission_codes'] ?? null], request: $request);
        });
        return response()->json(['message' => 'Perfil atualizado.']);
    }

    private function unitId(Request $request, LawAuthorizationService $authorization): string
    {
        $unitId = (string) $request->query('law_unit_id', $authorization->activeUnitId($request) ?? '');
        return $this->authorizedUnit($request, $authorization, $unitId, 'law.roles.view');
    }

    private function authorizedUnit(Request $request, LawAuthorizationService $authorization, string $unitId, string $permission): string
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        abort_unless(DB::table('law_units')->where('id', $unitId)->where('company_id', $companyId)->where('status', 'ativo')->exists(), 404, 'Setor não encontrado.');
        $authorization->authorize($request, $permission, $unitId);
        return $unitId;
    }
}
