<?php

namespace App\Console\Commands;

use App\Jobs\SyncLawCaseDjen as SyncLawCaseDjenJob;
use App\Services\SubscriptionAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncLawCaseDjen extends Command
{
    protected $signature = 'law:sync-case-djen {--limit=500 : Maximum number of process lookups to queue}';
    protected $description = 'Queues DJEN publication lookups for active legal cases that are due for refresh';

    public function handle(): int
    {
        if (! Schema::hasTable('law_cases') || ! Schema::hasColumn('law_cases', 'djen_sync_status')) return self::SUCCESS;

        $cases = DB::table('law_cases')->where('operational_status', '!=', 'archived')
            ->where('djen_sync_status', '!=', 'pending')
            ->where(function ($q): void {
                $q->where('djen_sync_status', 'not_requested')->orWhere(function ($daily): void {
                    $daily->whereIn('djen_sync_status', ['synced', 'not_found'])
                        ->where(fn ($due) => $due->whereNull('last_djen_synced_at')->orWhere('last_djen_synced_at', '<=', now()->subDay()));
                })->orWhere(function ($retry): void {
                    $retry->where('djen_sync_status', 'error')->where('djen_retryable', true)
                        ->where(function ($due): void {
                            $due->whereNull('last_djen_checked_at')
                                ->orWhere(function ($first): void { $first->where('djen_retry_count', '<=', 1)->where('last_djen_checked_at', '<=', now()->subHour()); })
                                ->orWhere(function ($second): void { $second->where('djen_retry_count', 2)->where('last_djen_checked_at', '<=', now()->subHours(6)); })
                                ->orWhere(function ($later): void { $later->where('djen_retry_count', '>=', 3)->where('last_djen_checked_at', '<=', now()->subDay()); });
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
                    ->whereColumn('subscription.company_id', 'law_cases.company_id')
                    ->where(fn ($query) => SubscriptionAccess::usable($query))
                    ->whereIn('product.code', ['law', 'fokus-law'])->where('module.status', 'ativo')->where('module.publication_state', 'publicado')
                    ->whereIn('module.context_code', ['judiciario', 'vara_criminal', 'escritorio'])
                    ->whereRaw('COALESCE(module.module_code, module.code) = ?', ['processos']);
            })
            ->orderBy('last_djen_checked_at')->orderBy('id')->limit(max(1, min(5000, (int) $this->option('limit'))))
            ->get(['id', 'company_id']);

        $queued = 0;
        foreach ($cases as $case) {
            $updated = DB::table('law_cases')->where('company_id', $case->company_id)->where('id', $case->id)
                ->where('djen_sync_status', '!=', 'pending')->update(['djen_sync_status' => 'pending']);
            if ($updated !== 1) continue;
            SyncLawCaseDjenJob::dispatch((string) $case->company_id, (string) $case->id)
                ->onConnection('law-datajud')->onQueue('law-datajud');
            $queued++;
        }

        $this->info("DJEN lookups queued: {$queued}.");
        return self::SUCCESS;
    }
}
