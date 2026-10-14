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
            ->get(['subscription.public_name', 'subscription.commercial_snapshot', 'subscription.status as subscription_status', 'product.name as product_name', 'product.code as product_code']);
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
        $planName = (string) ($snapshot['plan_name'] ?? '');
        $label = (string) ($active->public_name ?: ($planName ?: $active->product_name));

        return response()->json([
            'user' => ['id' => (string) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email],
            'company' => ['id' => (string) $company->id, 'name' => (string) ($company->display_name ?: $company->legal_name), 'legal_name' => (string) $company->legal_name, 'role' => (string) $membership->role],
            'active_company_id' => $companyId,
            'subscription' => ['product_name' => (string) $active->product_name, 'plan_name' => $planName, 'label' => $label, 'status' => (string) $active->subscription_status],
            'companies' => $companies->all(),
        ]);
    }
}
