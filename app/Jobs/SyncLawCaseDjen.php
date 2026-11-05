<?php

namespace App\Jobs;

use App\Services\LawCaseManagementService;
use App\Services\LawDjenClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncLawCaseDjen implements ShouldQueue
{
    use Queueable;

    // Must remain below the law-datajud queue's retry_after (120 seconds).
    public int $timeout = 100;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public string $companyId, public string $caseId) {}

    public function handle(LawCaseManagementService $cases, LawDjenClient $client): void
    {
        $case = DB::table('law_cases')->where('company_id', $this->companyId)->where('id', $this->caseId)->first();
        if (! $case || $case->djen_sync_status !== 'pending') return;
        $cases->syncDjen($case, $client);
    }

    public function failed(?Throwable $exception): void
    {
        $case = DB::table('law_cases')->where('company_id', $this->companyId)->where('id', $this->caseId)->first();
        if (! $case || $case->djen_sync_status !== 'pending') return;
        DB::table('law_cases')->where('company_id', $this->companyId)->where('id', $this->caseId)
            ->where('djen_sync_status', 'pending')->update([
                'djen_sync_status' => 'error', 'last_djen_checked_at' => now(), 'djen_retry_count' => min(65535, (int) ($case->djen_retry_count ?? 0) + 1), 'djen_retryable' => true,
                'djen_sync_message' => 'A tarefa de consulta não foi concluída; os dados anteriores foram preservados.',
            ]);
    }
}
