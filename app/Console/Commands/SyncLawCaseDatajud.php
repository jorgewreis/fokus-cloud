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
    protected $description = 'Atualiza metadados DataJud e executa novas tentativas escalonadas';

    public function handle(LawCaseManagementService $cases, LawDatajudClient $client): int
    {
        if (! Schema::hasTable('law_cases')) return self::SUCCESS;
        if (! config('services.datajud.api_key')) {
            $this->warn('Datajud não configurado; nenhuma consulta foi executada.');
            return self::SUCCESS;
        }
        $query = DB::table('law_cases')->where('operational_status', '!=', 'archived')
            ->where('datajud_sync_status', '!=', 'pending')
            ->where(function ($q): void {
                $q->where(function ($monthly): void {
                    $monthly->whereIn('datajud_sync_status', ['synced', 'not_found'])
                        ->where(fn ($due) => $due->whereNull('last_datajud_synced_at')->orWhere('last_datajud_synced_at', '<=', now()->subMonthNoOverflow()));
                })->orWhere(function ($retry): void {
                    $retry->where('datajud_sync_status', 'error')->where('datajud_retryable', true)
                        ->where(function ($due): void {
                            $due->whereNull('last_datajud_checked_at')
                                ->orWhere(function ($first): void { $first->where('datajud_retry_count', '<=', 1)->where('last_datajud_checked_at', '<=', now()->subHour()); })
                                ->orWhere(function ($second): void { $second->where('datajud_retry_count', 2)->where('last_datajud_checked_at', '<=', now()->subHours(6)); })
                                ->orWhere(function ($later): void { $later->where('datajud_retry_count', '>=', 3)->where('last_datajud_checked_at', '<=', now()->subDay()); });
                        });
                });
            })
            ->whereExists(function ($q): void {
                $q->selectRaw('1')->from('subscription_items as item')
                    ->join('subscriptions as subscription', 'subscription.id', '=', 'item.subscription_id')
                    ->join('products as product', 'product.id', '=', 'subscription.product_id')
                    ->join('modules as module', 'module.id', '=', 'item.module_id')
                    ->leftJoin('module_segments as segment', 'segment.module_id', '=', 'module.id')
            ->whereNull('item.deleted_at')
                    ->whereColumn('subscription.company_id', 'law_cases.company_id')->where(fn ($query) => \App\Services\SubscriptionAccess::usable($query))
                    ->whereIn('product.code', ['law', 'fokus-law'])->where('module.status', 'ativo')->where('module.publication_state', 'publicado')
                    ->where(fn ($context) => $context->whereIn('module.context_code', ['judiciario', 'vara_criminal'])
                        ->orWhere(fn ($advocacy) => $advocacy->where('module.context_code', 'escritorio')->where('segment.segment_code', 'advocacia')))
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
