<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\PlatformAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformUserDirectoryController extends Controller
{
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
