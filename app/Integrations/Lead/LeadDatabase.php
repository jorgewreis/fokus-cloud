<?php

namespace App\Integrations\Lead;

use App\Services\SubscriptionAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Usa a identidade e a autorização do Cloud; não cria autenticação no Lead. */
class LeadDatabase
{
    public function __construct(private readonly LeadConnections $connections) {}

    public function transaction(Request $request, Closure $operation): mixed
    {
        $user = $request->user();
        abort_unless($user, 401, 'Autenticação necessária.');
        $companyId = (string) $request->session()->get('active_company_id', '');
        abort_unless($companyId !== '', 409, 'Selecione uma empresa ativa para continuar.');
        // O suporte atual do Law não registra consentimento específico para Lead.
        abort_if($request->session()->has('support_session_id'), 403, 'O suporte operacional do Lead exige autorização própria da empresa.');

        $membership = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('users as user', 'user.id', '=', 'membership.user_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->where('membership.company_id', $companyId)->where('membership.user_id', $user->id)
            ->where('membership.status', 'ativo')->whereNull('membership.deleted_at')
            ->where('company.status', 'ativa')->whereNull('company.deleted_at')
            ->where('user.status', 'ativa')->whereNotNull('user.email_verified_at')
            ->first(['role.code as role']);
        abort_unless($membership, 403, 'O acesso à empresa não está ativo.');
        $entitled = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $companyId)
            ->whereIn('product.code', ['lead', 'fokus-lead'])
            ->where(fn ($query) => SubscriptionAccess::usable($query))->exists();
        abort_unless($entitled, 403, 'A empresa não possui acesso utilizável ao Fokus Lead.');

        // A permissão de domínio da ação deve ser validada pela Policy chamadora.
        return $this->connections->runtime($companyId, (string) $user->id, in_array($membership->role, ['admin', 'gestor'], true), $operation);
    }
}
