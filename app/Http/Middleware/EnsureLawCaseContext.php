<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureLawCaseContext
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = $request->attributes->get('active_company_id');
        $membership = $request->attributes->get('active_membership');
        abort_unless($request->user()?->status === 'ativa' && $request->user()?->email_verified_at && $membership && $membership->deleted_at === null, 403, 'Seu acesso ao Fokus Law não está ativo.');
        abort_unless(DB::table('companies')->where('id', $companyId)->where('status', 'ativa')->whereNull('deleted_at')->exists(), 403, 'A empresa não está ativa.');
        $enabled = DB::table('subscription_items as item')->join('subscriptions as subscription', 'subscription.id', '=', 'item.subscription_id')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')->join('modules as module', 'module.id', '=', 'item.module_id')
            ->whereNull('item.deleted_at')
            ->where('subscription.company_id', $companyId)->where(fn ($query) => \App\Services\SubscriptionAccess::usable($query))->whereIn('product.code', ['law', 'fokus-law'])
            ->where('module.status', 'ativo')->where('module.publication_state', 'publicado')->where('module.module_code', 'processos')
            ->whereIn('module.context_code', ['judiciario', 'vara_criminal'])->exists();
        abort_unless($enabled, 403, 'Esta etapa de Processos está disponível para o contexto Judiciário Criminal contratado.');
        return $next($request);
    }
}
