<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadShellController extends Controller
{
    public function context(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'ativa' && $user->email_verified_at, 403, 'Conta ativa e e-mail confirmado são necessários.');

        $companyId = (string) $request->attributes->get('active_company_id');
        $membership = $request->attributes->get('active_membership');
        abort_unless($companyId !== '' && $membership, 409, 'Selecione uma empresa ativa para continuar.');
        abort_unless(empty($membership->deleted_at), 403, 'Seu vínculo com a empresa não está ativo.');

        $company = DB::table('companies')->where('id', $companyId)->where('status', 'ativa')->whereNull('deleted_at')->first();
        abort_unless($company, 403, 'A empresa ativa não está disponível.');

        $leadSubscriptions = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $companyId)
            ->where(fn ($query) => SubscriptionAccess::usable($query))
            ->whereIn('product.code', ['lead', 'fokus-lead'])
            ->orderByDesc('subscription.created_at')
            ->get(['subscription.id', 'subscription.public_name', 'subscription.commercial_snapshot', 'subscription.status as subscription_status', 'product.id as product_id', 'product.name as product_name', 'product.code as product_code']);
        abort_unless($leadSubscriptions->isNotEmpty(), 403, 'Esta empresa não possui uma assinatura Fokus Lead utilizável.');

        $companies = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->where('membership.user_id', $user->id)
            ->where('membership.status', 'ativo')->whereNull('membership.deleted_at')
            ->where('company.status', 'ativa')->whereNull('company.deleted_at')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('subscriptions as subscription')
                    ->join('products as product', 'product.id', '=', 'subscription.product_id')
                    ->whereColumn('subscription.company_id', 'company.id')
                    ->where(fn ($usable) => SubscriptionAccess::usable($usable))
                    ->whereIn('product.code', ['lead', 'fokus-lead']);
            })
            ->orderBy('company.legal_name')
            ->get(['company.id', 'company.legal_name', 'company.display_name', 'role.code as role'])
            ->map(fn ($entry) => ['id' => (string) $entry->id, 'name' => (string) ($entry->display_name ?: $entry->legal_name), 'role' => (string) $entry->role])
            ->values();

        $active = $leadSubscriptions->first();
        $snapshot = json_decode((string) ($active->commercial_snapshot ?? ''), true) ?: [];
        $plan = null;
        if (! empty($snapshot['plan_id'])) {
            $plan = DB::table('plans')->where('id', $snapshot['plan_id'])->where('product_id', $active->product_id)->first(['id', 'name', 'segment']);
        } elseif (! empty($snapshot['plan_code'])) {
            $plan = DB::table('plans')->where('product_id', $active->product_id)->where('code', $snapshot['plan_code'])->first(['id', 'name', 'segment']);
        }
        $planName = (string) ($plan->name ?? $snapshot['plan_name'] ?? '');

        $subscriptionIds = $leadSubscriptions->pluck('id')->all();
        $hasSubscriptionItems = DB::table('subscription_items')->whereIn('subscription_id', $subscriptionIds)->whereNull('deleted_at')->exists();
        $modules = collect();
        if ($hasSubscriptionItems) {
            $modules = DB::table('subscription_items as item')
                ->join('modules as module', 'module.id', '=', 'item.module_id')
                ->whereNull('item.deleted_at')
                ->whereIn('item.subscription_id', $subscriptionIds)
                ->where('module.product_id', $active->product_id)
                ->where('module.status', 'ativo')
                ->where('module.publication_state', 'publicado')
                ->orderBy('module.display_order')->orderBy('module.name')
                ->get(['module.id', 'module.code', 'module.module_code', 'module.name', 'module.context_code', 'module.display_order'])
                ->unique(fn (object $module): string => (string) ($module->module_code ?: $module->code))
                ->values();
        } elseif ($plan) {
            $modules = DB::table('plan_modules as plan_module')
                ->join('modules as module', 'module.id', '=', 'plan_module.module_id')
                ->where('plan_module.plan_id', $plan->id)
                ->where('module.product_id', $active->product_id)
                ->where('module.status', 'ativo')->where('module.publication_state', 'publicado')
                ->orderBy('module.display_order')->orderBy('module.name')
                ->get(['module.id', 'module.code', 'module.module_code', 'module.name', 'module.context_code', 'module.display_order'])
                ->unique(fn (object $module): string => (string) ($module->module_code ?: $module->code))
                ->values();
        }
        $snapshotPlanName = trim((string) ($snapshot['plan_name'] ?? ''));
        $fallbackLabel = $snapshotPlanName !== '' ? $snapshotPlanName : ($active->public_name ?: $active->product_name);
        $label = $planName !== '' ? $active->product_name.' · '.$planName : (string) $fallbackLabel;

        return response()->json([
            'user' => ['id' => (string) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email],
            'company' => ['id' => (string) $company->id, 'name' => (string) ($company->display_name ?: $company->legal_name), 'legal_name' => (string) $company->legal_name, 'role' => (string) $membership->role],
            'active_company_id' => $companyId,
            'subscription' => ['product_name' => (string) $active->product_name, 'plan_name' => $planName, 'segment' => $plan->segment ?? $snapshot['segment'] ?? null, 'label' => $label, 'status' => (string) $active->subscription_status],
            'companies' => $companies->all(),
            'modules' => $modules->map(fn (object $module): array => [
                'id' => (string) $module->id,
                'code' => (string) $module->code,
                'family' => (string) ($module->module_code ?: $module->code),
                'context_code' => (string) $module->context_code,
                'name' => (string) $module->name,
            ])->all(),
        ]);
    }
}
