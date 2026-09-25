<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LawShellController extends Controller
{
    public function context(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'ativa' && $user->email_verified_at, 403, 'Confirme o e-mail da sua conta para acessar o Fokus Law.');

        $companyId = (string) $request->attributes->get('active_company_id');
        $membership = $request->attributes->get('active_membership');
        abort_unless($companyId !== '' && $membership, 409, 'Selecione uma empresa ativa para continuar.');

        $company = DB::table('companies')->where('id', $companyId)->where('status', 'ativa')->whereNull('deleted_at')->first();
        abort_unless($company, 403, 'A empresa ativa não está disponível.');

        $subscriptions = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $companyId)
            ->where('subscription.status', 'ativa')
            ->whereIn('product.code', ['law', 'fokus-law'])
            ->orderByDesc('subscription.created_at')
            ->get([
                'subscription.id', 'subscription.public_name', 'subscription.commercial_snapshot',
                'product.name as product_name', 'product.code as product_code',
            ]);

        $activeSubscription = $subscriptions->first();
        $snapshot = json_decode((string) ($activeSubscription->commercial_snapshot ?? ''), true) ?: [];
        $plan = null;
        if (! empty($snapshot['plan_id'])) {
            $plan = DB::table('plans')->where('id', $snapshot['plan_id'])->first(['name', 'segment']);
        } elseif (! empty($snapshot['plan_code'])) {
            $plan = DB::table('plans')->where('product_id', DB::table('products')->where('code', $activeSubscription->product_code ?? 'law')->value('id'))
                ->where('code', $snapshot['plan_code'])->first(['name', 'segment']);
        }

        $modules = collect();
        if ($subscriptions->isNotEmpty()) {
            $subscriptionIds = $subscriptions->pluck('id')->all();
            $modules = DB::table('subscription_items as item')
                ->join('modules as module', 'module.id', '=', 'item.module_id')
                ->whereIn('item.subscription_id', $subscriptionIds)
                ->where('module.status', 'ativo')
                ->where('module.publication_state', 'publicado')
                ->orderBy('module.display_order')
                ->orderBy('module.name')
                ->get(['module.id', 'module.code', 'module.module_code', 'module.name', 'module.display_order'])
                ->unique(fn (object $module): string => (string) $module->module_code)
                ->values();
        }

        $units = DB::table('law_units')->where('company_id', $companyId)->where('status', 'ativo')->orderBy('name')->get(['id', 'name']);
        $activeUnitId = DB::table('law_user_active_units')->where('user_id', $user->id)->where('company_id', $companyId)->value('law_unit_id');
        $activeUnit = $activeUnitId ? $units->first(fn (object $unit): bool => $unit->id === $activeUnitId) : null;
        if (! $activeUnit && $units->count() === 1) {
            $activeUnit = $units->first();
            $activeUnitId = $activeUnit->id;
        }

        $lawNames = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.status', 'ativa')
            ->whereIn('product.code', ['law', 'fokus-law'])
            ->whereNotNull('subscription.public_name')
            ->where('subscription.public_name', '!=', '')
            ->groupBy('subscription.company_id')
            ->select('subscription.company_id', DB::raw('MIN(subscription.public_name) as public_name'));

        $companies = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->leftJoinSub($lawNames, 'law_name', 'law_name.company_id', '=', 'company.id')
            ->where('membership.user_id', $user->id)
            ->where('membership.status', 'ativo')
            ->whereNull('membership.deleted_at')
            ->where('company.status', 'ativa')
            ->whereNull('company.deleted_at')
            ->orderBy('company.legal_name')
            ->get([
                'company.id',
                DB::raw('COALESCE(law_name.public_name, company.legal_name) as name'),
                'role.code as role',
            ])
            ->map(fn (object $entry): array => [
                'id' => (string) $entry->id,
                'name' => (string) $entry->name,
                'role' => (string) $entry->role,
            ]);

        $planName = (string) ($plan->name ?? $snapshot['plan_name'] ?? '');
        $segment = $plan->segment ?? $snapshot['segment'] ?? null;
        $segmentLabel = match ($segment) {
            'advocacia' => 'Advocacia',
            'setor_publico' => 'Setor Público',
            default => 'Jurídico',
        };
        $subscriptionLabel = $planName === ''
            ? $segmentLabel
            : (str_contains(mb_strtolower($planName), mb_strtolower($segmentLabel)) ? $planName : $segmentLabel.' - '.$planName);

        $supportMode = null;
        $supportId = $request->session()->get('support_session_id');
        if ($supportId) {
            $support = DB::table('platform_support_sessions as support')
                ->join('subscriptions as subscription', 'subscription.id', '=', 'support.subscription_id')
                ->join('companies as support_company', 'support_company.id', '=', 'support.company_id')
                ->where('support.id', $supportId)
                ->whereNull('support.ended_at')
                ->select('support.reason', 'subscription.status as subscription_status', 'support_company.legal_name as company_name')
                ->first();
            if ($support) {
                $supportMode = [
                    'company' => (string) $support->company_name,
                    'subscription_status' => (string) $support->subscription_status,
                    'reason' => (string) $support->reason,
                ];
            }
        }

        return response()->json([
            'user' => ['id' => (string) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email],
            'company' => [
                'id' => (string) $company->id,
                'name' => (string) ($activeSubscription?->public_name ?: $company->legal_name),
                'legal_name' => (string) $company->legal_name,
                'role' => (string) $membership->role,
            ],
            'active_company_id' => $companyId,
            'companies' => $companies->values()->all(),
            'units' => $units->map(fn (object $unit): array => ['id' => (string) $unit->id, 'name' => (string) $unit->name, 'status' => 'ativo'])->values()->all(),
            'active_unit_id' => $activeUnit ? (string) $activeUnit->id : null,
            'active_unit' => $activeUnit ? ['id' => (string) $activeUnit->id, 'name' => (string) $activeUnit->name] : null,
            'subscription' => $activeSubscription ? [
                'product_name' => (string) $activeSubscription->product_name,
                'plan_name' => $planName,
                'segment' => $segment,
                'segment_label' => $segmentLabel,
                'label' => trim($subscriptionLabel),
            ] : null,
            'permissions' => [
                'manage_company_users' => $membership->role === 'admin',
                'manage_settings' => $membership->role === 'admin',
            ],
            'support_mode' => $supportMode,
            'modules' => $modules->map(fn (object $module): array => [
                'id' => (string) $module->id,
                'code' => (string) $module->code,
                'family' => (string) ($module->module_code ?: $module->code),
                'name' => (string) $module->name,
            ])->all(),
        ]);
    }
}
