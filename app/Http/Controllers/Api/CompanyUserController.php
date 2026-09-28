<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LawAuthorizationService;
use App\Services\PrefixedUlid;
use App\Support\BrazilianDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyUserController extends Controller
{
    public function index(Request $request, LawAuthorizationService $authorization)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $unitId = $authorization->activeUnitId($request);
        if (! $authorization->isCompanyAdmin($request)) {
            if (! $unitId) {
                abort_unless($authorization->accessibleUnits($request)->isNotEmpty(), 403, 'Você não possui acesso a setores Law.');
                abort(409, 'Selecione um setor ao qual você tenha acesso.');
            }
            $authorization->authorize($request, 'law.users.view', $unitId);
        }
        $rows = DB::table('company_memberships as membership')
            ->join('users', 'users.id', '=', 'membership.user_id')
            ->join('roles as legacy_role', 'legacy_role.id', '=', 'membership.role_id')
            ->where('membership.company_id', $companyId)->whereNull('membership.deleted_at')
            ->when(! $authorization->isCompanyAdmin($request), fn ($query) => $query->join('law_unit_memberships as scoped', function ($join) use ($request, $unitId): void {
                $join->on('scoped.company_id', '=', 'membership.company_id')->on('scoped.company_membership_id', '=', 'membership.id')
                    ->where('scoped.law_unit_id', '=', $unitId);
            }))
            ->select('membership.id', 'membership.status', 'membership.version', 'users.name', 'users.email', 'users.email_verified_at', 'legacy_role.code as legacy_role')
            ->orderBy('users.name')->distinct()->get();

        $membershipIds = $rows->pluck('id')->all();
        $lawMemberships = DB::table('law_unit_memberships as lum')
            ->join('law_units as unit', function ($join): void { $join->on('unit.id', '=', 'lum.law_unit_id')->on('unit.company_id', '=', 'lum.company_id'); })
            ->join('law_access_roles as role', 'role.id', '=', 'lum.law_access_role_id')
            ->where('lum.company_id', $companyId)->whereIn('lum.company_membership_id', $membershipIds)
            ->when(! $authorization->isCompanyAdmin($request), fn ($query) => $query->where('lum.law_unit_id', $unitId))
            ->get([
                'lum.company_membership_id', 'lum.id as law_membership_id', 'lum.law_unit_id', 'lum.status as law_status',
                'lum.version as law_version', 'role.id as role_id', 'role.code as role_code', 'role.name as role_name', 'unit.name as unit_name',
            ])->groupBy('company_membership_id');

        return response()->json($rows->map(fn ($row): array => [
            'id' => (string) $row->id, 'status' => (string) $row->status, 'version' => (int) $row->version,
            'name' => (string) $row->name, 'email' => (string) $row->email,
            'email_verified_at' => $row->email_verified_at, 'role' => (string) $row->legacy_role,
            'law_memberships' => ($lawMemberships[$row->id] ?? collect())->map(fn ($law): array => [
                'id' => (string) $law->law_membership_id, 'unit_id' => (string) $law->law_unit_id,
                'unit_name' => (string) $law->unit_name, 'status' => (string) $law->law_status,
                'version' => (int) $law->law_version, 'role_id' => (string) $law->role_id,
                'role_code' => (string) $law->role_code, 'role_name' => (string) $law->role_name,
            ])->values()->all(),
        ])->values());
    }

    public function store(Request $request, AuthController $auth, LawAuthorizationService $authorization)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'cpf' => ['required', 'string'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'law_assignments' => ['required', 'array', 'min:1'],
            'law_assignments.*.unit_id' => ['required', 'string', 'size:30', 'distinct'],
            'law_assignments.*.role_id' => ['required', 'string', 'size:30'],
        ]);
        $cpf = BrazilianDocuments::digits($data['cpf']);
        abort_unless(BrazilianDocuments::cpf($cpf), 422, 'CPF inválido.');
        $companyId = (string) $request->attributes->get('active_company_id');
        $actor = $request->user();
        $assignments = $data['law_assignments'];
        foreach ($assignments as $assignment) {
            $unit = DB::table('law_units')->where('id', $assignment['unit_id'])->where('company_id', $companyId)->where('status', 'ativo')->first();
            abort_unless($unit, 422, 'Um dos setores informados não está ativo nesta empresa.');
            $authorization->authorize($request, 'law.users.manage', (string) $unit->id);
            $role = DB::table('law_access_roles')->where('id', $assignment['role_id'])->where('company_id', $companyId)->where('law_unit_id', $unit->id)->first();
            abort_unless($role && $authorization->actorMayAssignRole($request, $role, (string) $unit->id), 403, 'Você não pode atribuir esse perfil neste setor.');
        }

        $membershipId = DB::transaction(function () use ($data, $cpf, $companyId, $actor, $auth, $assignments): string {
            $user = User::where('cpf', $cpf)->first();
            $existing = (bool) $user;
            if (! $user) {
                abort_if(User::where('email', Str::lower($data['email']))->exists(), 422, 'Este e-mail já está vinculado a outra conta.');
                $user = User::create([
                    'id' => PrefixedUlid::make('USR'), 'name' => $data['name'], 'cpf' => $cpf,
                    'email' => Str::lower($data['email']), 'password' => Str::random(48), 'status' => 'pendente',
                ]);
            }
            $legacyRoleId = DB::table('roles')->where('code', 'usuario')->value('id');
            abort_unless($legacyRoleId, 422, 'Perfil de usuário indisponível.');
            abort_if(DB::table('company_memberships')->where('company_id', $companyId)->where('user_id', $user->id)->exists(), 409, 'Esta pessoa já possui vínculo com a empresa.');
            $id = PrefixedUlid::make('VNC');
            DB::table('company_memberships')->insert([
                'id' => $id, 'company_id' => $companyId, 'user_id' => $user->id, 'role_id' => $legacyRoleId,
                'status' => 'pendente', 'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($assignments as $assignment) {
                DB::table('law_unit_memberships')->insert([
                    'id' => PrefixedUlid::make('LUM'), 'company_id' => $companyId, 'law_unit_id' => $assignment['unit_id'],
                    'company_membership_id' => $id, 'law_access_role_id' => $assignment['role_id'], 'status' => 'pendente',
                    'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('company_invitations')->insert([
                'id' => PrefixedUlid::make('CNV'), 'company_id' => $companyId, 'membership_id' => $id,
                'created_by' => $actor->id, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $auth->sendToken($user, $existing ? 'membership_acceptance' : 'password_creation', $existing ? '/aceitar-vinculo' : '/criar-senha', ['membership_id' => $id]);
            $this->audit($companyId, $actor->id, 'company_membership', $id, 'create', null, [
                'status' => 'pendente', 'law_assignments' => array_map(fn ($assignment): array => ['unit_id' => $assignment['unit_id'], 'role_id' => $assignment['role_id']], $assignments),
            ]);
            return $id;
        });
        return response()->json(['message' => 'Convite enviado para o e-mail cadastrado.', 'membership_id' => $membershipId], 201);
    }

    public function update(Request $request, string $membership)
    {
        $this->adminOnly($request);
        $data = $request->validate([
            'role' => ['nullable', Rule::in(['gestor', 'usuario'])], 'status' => ['nullable', Rule::in(['suspenso', 'removido'])],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        abort_if(empty($data['role']) && empty($data['status']), 422, 'Informe uma alteração.');
        $companyId = $request->attributes->get('active_company_id');
        $current = DB::table('company_memberships')->where('id', $membership)->where('company_id', $companyId)->first();
        abort_unless($current, 404, 'Vínculo não encontrado.');
        abort_if($current->active_admin_company_id, 422, 'Use a transferência formal para alterar o administrador.');
        $changes = ['updated_by' => $request->user()->id, 'updated_at' => now(), 'version' => $current->version + 1];
        if (! empty($data['role'])) $changes['role_id'] = DB::table('roles')->where('code', $data['role'])->value('id');
        if (! empty($data['status'])) {
            $changes['status'] = $data['status'];
            if ($data['status'] === 'removido') { $changes['deleted_at'] = now(); $changes['deleted_by'] = $request->user()->id; }
        }
        $updated = DB::table('company_memberships')->where('id', $membership)->where('company_id', $companyId)->where('version', $data['version'])->update($changes);
        abort_unless($updated, 409, 'Este vínculo foi alterado por outra pessoa. Atualize a tela e tente novamente.');
        $this->audit($companyId, $request->user()->id, 'company_membership', $membership, 'update', ['status' => $current->status], ['role' => $data['role'] ?? null, 'status' => $data['status'] ?? $current->status]);
        return response()->json(['message' => 'Vínculo atualizado.']);
    }

    public function updateLawAccess(Request $request, string $membership, LawAuthorizationService $authorization)
    {
        $data = $request->validate([
            'law_unit_id' => ['required', 'string', 'size:30'], 'law_access_role_id' => ['nullable', 'string', 'size:30'],
            'status' => ['nullable', Rule::in(['ativo', 'suspenso', 'removido'])], 'version' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(empty($data['law_access_role_id']) && empty($data['status']), 422, 'Informe uma alteração.');
        $companyId = (string) $request->attributes->get('active_company_id');
        $authorization->authorize($request, 'law.users.manage', $data['law_unit_id']);
        $companyMembership = DB::table('company_memberships')->where('id', $membership)->where('company_id', $companyId)->first();
        abort_unless($companyMembership, 404, 'Vínculo não encontrado.');
        abort_if($companyMembership->status === 'pendente' && ($data['status'] ?? null) === 'ativo', 422, 'O convite precisa ser aceito antes de ativar o acesso ao setor.');
        $lawMembership = DB::table('law_unit_memberships')->where('company_id', $companyId)->where('law_unit_id', $data['law_unit_id'])->where('company_membership_id', $membership)->first();
        if (! $lawMembership) {
            abort_unless(! empty($data['law_access_role_id']) && empty($data['status']), 422, 'Selecione um perfil para conceder acesso ao setor.');
            abort_unless(in_array($companyMembership->status, ['ativo', 'pendente'], true), 422, 'Ative o vínculo com a empresa antes de conceder acesso ao setor.');
            $role = DB::table('law_access_roles')->where('id', $data['law_access_role_id'])->where('company_id', $companyId)->where('law_unit_id', $data['law_unit_id'])->first();
            abort_unless($role && $authorization->actorMayAssignRole($request, $role, $data['law_unit_id']), 403, 'Você não pode atribuir esse perfil neste setor.');
            $id = PrefixedUlid::make('LUM');
            DB::table('law_unit_memberships')->insert([
                'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $data['law_unit_id'],
                'company_membership_id' => $membership, 'law_access_role_id' => $role->id,
                'status' => $companyMembership->status === 'pendente' ? 'pendente' : 'ativo', 'version' => 1,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($companyId, $request->user()->id, 'law_unit_membership', $id, 'create', null, ['role_id' => $role->id, 'status' => $companyMembership->status === 'pendente' ? 'pendente' : 'ativo', 'law_unit_id' => $data['law_unit_id']]);
            return response()->json(['message' => 'Acesso ao setor concedido.'], 201);
        }
        abort_unless(isset($data['version']), 422, 'Informe a versão atual do acesso.');
        if (! empty($data['law_access_role_id'])) {
            $role = DB::table('law_access_roles')->where('id', $data['law_access_role_id'])->where('company_id', $companyId)->where('law_unit_id', $data['law_unit_id'])->first();
            abort_unless($role && $authorization->actorMayAssignRole($request, $role, $data['law_unit_id']), 403, 'Você não pode atribuir esse perfil neste setor.');
            if ($role->code !== 'unit_admin') $this->assertNotLastUnitAdmin($lawMembership, $data['law_unit_id'], $companyId);
        } elseif (($data['status'] ?? null) !== 'ativo') {
            $this->assertNotLastUnitAdmin($lawMembership, $data['law_unit_id'], $companyId);
        }
        $changes = ['updated_by' => $request->user()->id, 'updated_at' => now(), 'version' => $lawMembership->version + 1];
        if (! empty($data['law_access_role_id'])) $changes['law_access_role_id'] = $data['law_access_role_id'];
        if (! empty($data['status'])) {
            $changes['status'] = $data['status'];
            $changes['deleted_at'] = $data['status'] === 'removido' ? now() : null;
        }
        $updated = DB::table('law_unit_memberships')->where('id', $lawMembership->id)->where('version', $data['version'])->update($changes);
        abort_unless($updated, 409, 'O acesso deste setor foi alterado por outra pessoa. Atualize a tela e tente novamente.');
        $this->audit($companyId, $request->user()->id, 'law_unit_membership', $lawMembership->id, 'update', ['role_id' => $lawMembership->law_access_role_id, 'status' => $lawMembership->status], ['role_id' => $data['law_access_role_id'] ?? $lawMembership->law_access_role_id, 'status' => $data['status'] ?? $lawMembership->status, 'law_unit_id' => $data['law_unit_id']]);
        return response()->json(['message' => 'Acesso ao setor atualizado.']);
    }

    public function restore(Request $request, string $membership)
    {
        $this->adminOnly($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $companyId = $request->attributes->get('active_company_id');
        $current = DB::table('company_memberships')->where('id', $membership)->where('company_id', $companyId)->where('status', 'removido')->first();
        abort_unless($current && $current->deleted_at && now()->diffInDays($current->deleted_at) <= 30, 422, 'Este vínculo não pode mais ser restaurado.');
        $updated = DB::table('company_memberships')->where('id', $membership)->where('version', $data['version'])->update(['status' => 'ativo', 'deleted_at' => null, 'deleted_by' => null, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'version' => $current->version + 1]);
        abort_unless($updated, 409, 'Este vínculo foi alterado por outra pessoa. Atualize a tela e tente novamente.');
        $this->audit($companyId, $request->user()->id, 'company_membership', $membership, 'restore', ['status' => 'removido'], ['status' => 'ativo']);
        return response()->json(['message' => 'Vínculo restaurado.']);
    }

    public function restoreLawAccess(Request $request, string $membership, LawAuthorizationService $authorization)
    {
        $data = $request->validate(['law_unit_id' => ['required', 'string', 'size:30'], 'version' => ['required', 'integer', 'min:1']]);
        $authorization->authorize($request, 'law.users.manage', $data['law_unit_id']);
        $companyId = (string) $request->attributes->get('active_company_id');
        $current = DB::table('law_unit_memberships')->where('company_id', $companyId)->where('law_unit_id', $data['law_unit_id'])->where('company_membership_id', $membership)->where('status', 'removido')->first();
        abort_unless($current && $current->deleted_at && now()->diffInDays($current->deleted_at) <= 30, 422, 'Este acesso não pode mais ser restaurado.');
        $companyMembership = DB::table('company_memberships')->where('id', $membership)->where('company_id', $companyId)->first();
        abort_unless($companyMembership && $companyMembership->status === 'ativo' && ! $companyMembership->deleted_at, 422, 'Ative o vínculo com a empresa antes de restaurar o acesso ao setor.');
        $role = DB::table('law_access_roles')->where('id', $current->law_access_role_id)->first();
        abort_unless($role && $authorization->actorMayAssignRole($request, $role, $data['law_unit_id']), 403, 'Você não pode restaurar esse perfil neste setor.');
        $updated = DB::table('law_unit_memberships')->where('id', $current->id)->where('version', $data['version'])->update(['status' => 'ativo', 'deleted_at' => null, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'version' => $current->version + 1]);
        abort_unless($updated, 409, 'Este acesso foi alterado por outra pessoa. Atualize a tela e tente novamente.');
        $this->audit($companyId, $request->user()->id, 'law_unit_membership', $current->id, 'restore', ['status' => 'removido'], ['status' => 'ativo', 'law_unit_id' => $data['law_unit_id']]);
        return response()->json(['message' => 'Acesso ao setor restaurado.']);
    }

    public function transferAdmin(Request $request, AuthController $auth)
    {
        $this->adminOnly($request);
        $data = $request->validate(['membership_id' => ['required', 'string', 'size:30'], 'password' => ['required', 'string'], 'keep_previous_access' => ['required', 'boolean']]);
        abort_unless(Hash::check($data['password'], $request->user()->password), 422, 'Senha atual inválida.');
        $companyId = $request->attributes->get('active_company_id');
        $target = DB::table('company_memberships as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.id', $data['membership_id'])->where('membership.company_id', $companyId)->where('membership.status', 'ativo')
            ->whereNotNull('users.email_verified_at')->select('membership.*', 'users.id as user_id')->first();
        abort_unless($target, 422, 'O novo administrador deve estar ativo, vinculado e ter e-mail confirmado.');
        abort_if($target->active_admin_company_id, 422, 'Esta pessoa já é administradora.');
        $auth->sendToken(User::findOrFail($target->user_id), 'admin_transfer', '/aceitar-transferencia', [
            'company_id' => $companyId, 'from_membership_id' => $request->attributes->get('active_membership')->id,
            'to_membership_id' => $target->id, 'keep_previous_access' => $data['keep_previous_access'],
        ]);
        $this->audit((string) $companyId, (string) $request->user()->id, 'company_membership', (string) $target->id, 'admin_transfer_requested', null, [
            'from_membership_id' => (string) $request->attributes->get('active_membership')->id,
            'keep_previous_access' => (bool) $data['keep_previous_access'], 'status' => 'pendente',
        ]);
        Mail::raw('Uma transferência de administração foi iniciada e aguarda o aceite do novo administrador.', fn ($mail) => $mail->to($request->user()->email)->subject('Fokus Cloud: transferência de administração iniciada'));
        return response()->json(['message' => 'Enviamos o aceite de transferência ao novo administrador.']);
    }

    public function auditHistory(Request $request, LawAuthorizationService $authorization)
    {
        $unitId = $authorization->activeUnitId($request);
        if (! $authorization->isCompanyAdmin($request)) $authorization->authorize($request, 'law.users.view', $unitId);
        $entityIds = null;
        if (! $authorization->isCompanyAdmin($request)) {
            $entityIds = DB::table('law_unit_memberships')->where('company_id', $request->attributes->get('active_company_id'))->where('law_unit_id', $unitId)->pluck('id')
                ->merge(DB::table('law_unit_memberships')->where('company_id', $request->attributes->get('active_company_id'))->where('law_unit_id', $unitId)->pluck('company_membership_id'))
                ->merge(DB::table('law_access_roles')->where('company_id', $request->attributes->get('active_company_id'))->where('law_unit_id', $unitId)->pluck('id'));
        }
        return response()->json(DB::table('audit_events')->where('company_id', $request->attributes->get('active_company_id'))
            ->whereIn('entity_type', ['company_membership', 'law_unit_membership', 'law_access_role'])
            ->when($entityIds !== null, fn ($query) => $query->whereIn('entity_id', $entityIds))
            ->orderByDesc('created_at')->select('id', 'operation', 'before_masked', 'after_masked', 'created_at')->limit(100)->get());
    }

    private function assertNotLastUnitAdmin(object $membership, string $unitId, string $companyId): void
    {
        $roleCode = DB::table('law_access_roles')->where('id', $membership->law_access_role_id)->value('code');
        if ($roleCode !== 'unit_admin') return;
        $remaining = DB::table('law_unit_memberships as lum')->join('law_access_roles as role', 'role.id', '=', 'lum.law_access_role_id')
            ->where('lum.company_id', $companyId)->where('lum.law_unit_id', $unitId)->where('lum.status', 'ativo')
            ->whereNull('lum.deleted_at')->where('role.code', 'unit_admin')->where('lum.id', '!=', $membership->id)->exists();
        abort_unless($remaining, 422, 'O setor precisa manter ao menos um administrador ativo.');
    }

    private function adminOnly(Request $request): void
    {
        abort_unless($request->attributes->get('active_membership')->role === 'admin', 403, 'Apenas o administrador da empresa pode realizar esta ação.');
    }

    private function audit(string $companyId, string $actorId, string $entityType, string $entityId, string $operation, ?array $before, ?array $after): void
    {
        app(\App\Services\AuditRecorder::class)->company($companyId, $actorId, $entityType, $entityId, $operation, $before, $after, request: request());
    }
}
