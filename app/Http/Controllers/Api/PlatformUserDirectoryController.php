<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Services\PrefixedUlid;
use App\Support\BrazilianDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformUserDirectoryController extends Controller
{
    public function companiesForCreation()
    {
        $companies = DB::table('companies as company')
            ->join('subscriptions as subscription', 'subscription.company_id', '=', 'company.id')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('company.status', 'ativa')->whereNull('company.deleted_at')
            ->where('subscription.status', 'ativa')
            ->where('product.code', 'law')
            ->orderBy('company.legal_name')
            ->get(['company.id', 'company.legal_name', 'product.name as product_name'])
            ->unique('id')->values()
            ->map(fn (object $company): array => [
                'id' => $company->id,
                'label' => $company->product_name.' - '.$company->legal_name,
            ]);

        return response()->json(['data' => $companies]);
    }

    public function createExternal(Request $request, AuthController $auth, PlatformAudit $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'cpf' => ['required', 'string'],
            'company_id' => ['required', 'string', 'exists:companies,id'],
            'role' => ['required', Rule::in(['gestor', 'usuario'])],
        ]);
        $email = Str::lower(trim($data['email']));
        $cpf = BrazilianDocuments::digits($data['cpf']);
        abort_unless(BrazilianDocuments::cpf($cpf), 422, 'CPF inválido.');
        $admin = $request->user();

        $created = DB::transaction(function () use ($data, $email, $cpf, $admin): array {
            $company = DB::table('companies')->where('id', $data['company_id'])->where('status', 'ativa')->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 422, 'A empresa selecionada não está ativa.');
            $hasLawSubscription = DB::table('subscriptions as subscription')
                ->join('products as product', 'product.id', '=', 'subscription.product_id')
                ->where('subscription.company_id', $company->id)->where('subscription.status', 'ativa')
                ->where('product.code', 'law')->exists();
            abort_unless($hasLawSubscription, 422, 'A empresa não possui uma assinatura ativa do Fokus Law.');

            $byEmail = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            $byCpf = User::where('cpf', $cpf)->lockForUpdate()->first();
            abort_if($byEmail && $byCpf && $byEmail->id !== $byCpf->id, 409, 'O e-mail e o CPF pertencem a contas diferentes.');
            $user = $byEmail ?? $byCpf;
            if ($user) {
                abort_if(Str::lower($user->email) !== $email || $user->cpf !== $cpf, 409, 'Os dados informados não correspondem à mesma conta existente. Confira o e-mail e o CPF cadastrados.');
                abort_unless(in_array($user->status, ['ativa', 'pendente'], true), 422, 'A conta existente está bloqueada, suspensa ou encerrada e não pode receber novos vínculos.');
            } else {
                $user = User::create([
                    'id' => PrefixedUlid::make('USR'), 'name' => trim($data['name']), 'cpf' => $cpf,
                    'email' => $email, 'password' => Str::random(64), 'status' => 'pendente',
                ]);
            }

            $existingMembership = DB::table('company_memberships')->where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->exists();
            abort_if($existingMembership, 409, 'Este usuário já possui ou já possuiu vínculo com a empresa.');
            $role = DB::table('roles')->where('code', $data['role'])->first();
            abort_unless($role, 422, 'Perfil inválido.');
            $membershipId = PrefixedUlid::make('VNC');
            DB::table('company_memberships')->insert([
                'id' => $membershipId, 'company_id' => $company->id, 'user_id' => $user->id,
                'role_id' => $role->id, 'status' => 'pendente', 'version' => 1,
                'created_by' => $admin->id, 'updated_by' => $admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('company_invitations')->insert([
                'id' => PrefixedUlid::make('CNV'), 'company_id' => $company->id,
                'membership_id' => $membershipId, 'created_by' => $admin->id,
                'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['user' => $user, 'membership_id' => $membershipId, 'company' => $company, 'role' => $data['role'], 'needs_password' => ! $user->email_verified_at];
        });

        $auth->sendToken(
            $created['user'],
            $created['needs_password'] ? 'password_creation' : 'membership_acceptance',
            $created['needs_password'] ? '/criar-senha' : '/aceitar-vinculo',
            ['membership_id' => $created['membership_id']],
        );
        $audit->record($admin->id, 'backoffice.external_user_invited', 'company_membership', $created['membership_id'], companyId: $created['company']->id, after: [
            'user_id' => $created['user']->id, 'company_id' => $created['company']->id,
            'role' => $created['role'], 'status' => 'pendente',
        ], request: $request);

        return response()->json(['message' => 'Convite enviado para o e-mail informado. O vínculo ficará pendente até a confirmação do usuário.'], 201);
    }

    public function index(Request $request, PlatformAudit $audit)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = trim((string) ($filters['q'] ?? ''));

        $platform = DB::table('platform_admins as admin')
            ->join('platform_roles as role', 'role.id', '=', 'admin.platform_role_id')
            ->selectRaw("admin.id, admin.name, admin.email, 'plataforma' as account_type, role.name as role_name, CASE WHEN admin.deactivated_at IS NOT NULL THEN 'desativado' WHEN admin.manual_blocked_at IS NOT NULL THEN 'bloqueado' WHEN admin.locked_until > CURRENT_TIMESTAMP THEN 'bloqueio_temporario' ELSE admin.status END as status");
        $company = DB::table('users as account')
            ->selectRaw("account.id, account.name, account.email, 'empresa' as account_type, NULL as role_name, account.status");
        $directory = DB::query()->fromSub($platform->unionAll($company), 'directory')
            ->when($term !== '', fn ($builder) => $builder->where(fn ($search) => $search->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')->orderBy('account_type')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        $companyUserIds = collect($directory->items())->where('account_type', 'empresa')->pluck('id')->all();
        $memberships = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->whereIn('membership.user_id', $companyUserIds)
            ->whereNull('membership.deleted_at')->whereNull('company.deleted_at')
            ->orderBy('company.legal_name')
            ->get(['membership.user_id', 'company.id as company_id', 'company.legal_name as company_name', 'role.name as role_name', 'membership.status as membership_status'])
            ->groupBy('user_id');

        $audit->record($request->user()->id, 'backoffice.user_directory_viewed', request: $request);

        return response()->json([
            'data' => collect($directory->items())->map(function (object $row) use ($memberships): array {
                $accountMemberships = $row->account_type === 'empresa' ? $memberships->get($row->id, collect()) : collect();

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'type' => $row->account_type,
                    'role' => $row->role_name,
                    'profile' => $row->account_type === 'empresa'
                        ? $accountMemberships->pluck('role_name')->unique()->implode(' / ')
                        : $row->role_name,
                    'company_names' => $accountMemberships->pluck('company_name')->unique()->values(),
                    'status' => $row->status,
                    'company_count' => $accountMemberships->count(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $directory->currentPage(),
                'last_page' => $directory->lastPage(),
                'per_page' => $directory->perPage(),
                'total' => $directory->total(),
            ],
        ]);
    }

    public function show(Request $request, string $type, string $id, PlatformAudit $audit)
    {
        abort_unless(in_array($type, ['plataforma', 'empresa'], true), 404);
        $admin = $request->user();
        $isSuperadmin = $admin->role?->code === 'superadministrador';

        if ($type === 'plataforma') {
            $record = PlatformAdmin::with('role')->find($id);
            abort_unless($record, 404, 'Conta interna não encontrada.');
            $status = $record->deactivated_at ? 'desativado' : ($record->manual_blocked_at ? 'bloqueado' : ($record->locked_until?->isFuture() ? 'bloqueio_temporario' : $record->status));
            $result = [
                'id' => $record->id,
                'name' => $record->name,
                'email' => $record->email,
                'type' => 'plataforma',
                'role' => $record->role?->name,
                'status' => $status,
                'last_login_at' => $record->last_login_at?->toIso8601String(),
                'pending_email' => DB::table('platform_admin_email_changes')->where('platform_admin_id', $record->id)->whereNull('used_at')->whereNull('superseded_at')->where('expires_at', '>', now())->latest('created_at')->value('new_email'),
            ];
            $audit->record($admin->id, 'backoffice.user_detail_viewed', 'platform_admin', $record->id, metadata: ['account_type' => $type], request: $request);
            return response()->json($result);
        }

        $record = DB::table('users')->where('id', $id)->first();
        abort_unless($record, 404, 'Usuário não encontrado.');
        $membershipRows = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->where('membership.user_id', $record->id)->whereNull('membership.deleted_at')->whereNull('company.deleted_at')
            ->orderBy('company.legal_name')
            ->get(['membership.company_id', 'company.legal_name as company_name', 'role.name as role_name', 'membership.status as membership_status']);
        $companyIds = $membershipRows->pluck('company_id')->unique()->values()->all();
        $subscriptions = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->whereIn('subscription.company_id', $companyIds)->whereNotNull('subscription.open_company_product')
            ->orderBy('product.name')->get([
                'subscription.company_id', 'subscription.status', 'subscription.billing_cycle',
                'subscription.commercial_snapshot', 'product.name as product_name',
            ])->groupBy('company_id');

        $result = [
            'id' => $record->id,
            'name' => $record->name,
            'email' => $record->email,
            'type' => 'empresa',
            'status' => $record->status,
            'memberships' => $membershipRows->map(function (object $membership) use ($subscriptions): array {
                $plans = ($subscriptions->get($membership->company_id) ?? collect())->map(function (object $subscription): array {
                    $snapshot = json_decode((string) $subscription->commercial_snapshot, true) ?: [];
                    return [
                        'product_name' => $subscription->product_name,
                        'plan_name' => $snapshot['plan_name'] ?? 'Plano não identificado',
                        'status' => $subscription->status,
                        'billing_cycle' => $subscription->billing_cycle,
                    ];
                })->values()->all();
                return [
                    'company_id' => $membership->company_id,
                    'company_name' => $membership->company_name,
                    'role' => $membership->role_name,
                    'status' => $membership->membership_status,
                    'subscriptions' => $plans,
                ];
            })->values(),
        ];
        if ($isSuperadmin) {
            $result['cpf'] = $record->cpf;
        }
        $audit->record($admin->id, 'backoffice.user_detail_viewed', 'user', $record->id, metadata: ['account_type' => $type, 'company_ids' => $companyIds], request: $request);
        return response()->json($result);
    }
}
