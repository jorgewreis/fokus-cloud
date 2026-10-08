<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureLawAdministrativeProcessContext
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $enabled = DB::table('subscription_items as item')->join('subscriptions as subscription', 'subscription.id', '=', 'item.subscription_id')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')->join('modules as module', 'module.id', '=', 'item.module_id')
            ->whereNull('item.deleted_at')->where('subscription.company_id', $companyId)
            ->where(fn ($query) => \App\Services\SubscriptionAccess::usable($query))->whereIn('product.code', ['law', 'fokus-law'])
            ->where('module.status', 'ativo')->where('module.publication_state', 'publicado')->where('module.module_code', 'processos')
            ->where('module.context_code', 'orgao_publico')->exists();
        abort_unless($enabled, 403, 'Contrate Processos Administrativos para Órgãos Públicos para acessar esta área.');
        return $next($request);
    }
}
