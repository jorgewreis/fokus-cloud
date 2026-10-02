<?php

namespace App\Console\Commands;

use App\Services\LawCaseManagementService;
use App\Services\LawDatajudClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncLawCaseDatajud extends Command
{
    protected $signature = 'law:sync-case-datajud {--limit=500 : Máximo de consultas por execução}';
    protected $description = 'Consulta metadados dos processos não arquivados cuja atualização mensal venceu';

    public function handle(LawCaseManagementService $cases, LawDatajudClient $client): int
    {
        if (! Schema::hasTable('law_cases')) return self::SUCCESS;
        if (! config('services.datajud.api_key')) {
            $this->warn('Datajud não configurado; nenhuma consulta foi executada.');
            return self::SUCCESS;
        }
        $query = DB::table('law_cases')->where('operational_status', '!=', 'archived')
            ->where(fn ($q) => $q->whereNull('last_datajud_checked_at')->orWhere('last_datajud_checked_at', '<=', now()->subMonthNoOverflow()))
            ->whereExists(function ($q): void {
                $q->selectRaw('1')->from('subscription_items as item')
                    ->join('subscriptions as subscription', 'subscription.id', '=', 'item.subscription_id')
                    ->join('products as product', 'product.id', '=', 'subscription.product_id')
                    ->join('modules as module', 'module.id', '=', 'item.module_id')
                    ->whereColumn('subscription.company_id', 'law_cases.company_id')->where('subscription.status', 'ativa')
                    ->whereIn('product.code', ['law', 'fokus-law'])->where('module.status', 'ativo')->where('module.publication_state', 'publicado')
                    ->whereIn('module.context_code', ['judiciario', 'vara_criminal'])
                    ->whereRaw("COALESCE(module.module_code, module.code) = ?", ['processos']);
            })
            ->orderBy('last_datajud_checked_at')->orderBy('id')->limit(max(1, min(5000, (int) $this->option('limit'))));
        $count = 0;
        foreach ($query->get() as $case) {
            $cases->syncDatajud($case, null, $client, 'datajud_monthly_sync');
            $count++;
        }
        $this->info("Consultas realizadas: {$count}.");
        return self::SUCCESS;
    }
}
