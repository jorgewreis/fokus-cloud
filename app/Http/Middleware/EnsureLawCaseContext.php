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
        $moduleContext = DB::table('subscription_items as item')->join('subscriptions as subscription', 'subscription.id', '=', 'item.subscription_id')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')->join('modules as module', 'module.id', '=', 'item.module_id')
            ->whereNull('item.deleted_at')
            ->where('subscription.company_id', $companyId)->where(fn ($query) => \App\Services\SubscriptionAccess::usable($query))->whereIn('product.code', ['law', 'fokus-law'])
            ->leftJoin('module_segments as segment', 'segment.module_id', '=', 'module.id')
            ->where('module.status', 'ativo')->where('module.publication_state', 'publicado')->where('module.module_code', 'processos')
            ->where(function ($query): void {
                $query->whereIn('module.context_code', ['judiciario', 'vara_criminal'])
                    ->orWhere(fn ($advocacy) => $advocacy->where('module.context_code', 'escritorio')->where('segment.segment_code', 'advocacia'));
            })->orderByRaw("CASE WHEN module.context_code = 'escritorio' THEN 0 ELSE 1 END")->value('module.context_code');
        abort_unless($moduleContext, 403, 'A Gestão de Processos não está habilitada para o segmento contratado.');
        $request->attributes->set('law_case_context_code', $moduleContext);
        return $next($request);
    }
}
