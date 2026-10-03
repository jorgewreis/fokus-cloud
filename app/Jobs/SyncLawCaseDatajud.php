<?php

namespace App\Jobs;

use App\Services\LawCaseManagementService;
use App\Services\LawDatajudClient;
use App\Services\PrefixedUlid;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncLawCaseDatajud implements ShouldQueue
{
    use Queueable;

    public int $timeout = 70;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public string $companyId, public string $caseId, public string $actorId) {}

    public function handle(LawCaseManagementService $cases, LawDatajudClient $client): void
    {
        $case = DB::table('law_cases')->where('company_id', $this->companyId)->where('id', $this->caseId)->first();
        if (! $case || $case->datajud_sync_status !== 'pending') return;

        $cases->syncDatajud($case, $this->actorId, $client, 'datajud_initial_sync');
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $case = DB::table('law_cases')->where('company_id', $this->companyId)->where('id', $this->caseId)->lockForUpdate()->first();
            if (! $case || $case->datajud_sync_status !== 'pending') return;

            $now = now();
            DB::table('law_cases')->where('id', $case->id)->where('company_id', $this->companyId)->update([
                'datajud_sync_status' => 'error', 'last_datajud_checked_at' => $now,
                'version' => DB::raw('version + 1'), 'updated_at' => $now,
            ]);
            DB::table('law_case_events')->insert([
                'id' => PrefixedUlid::make('LCE'), 'company_id' => $this->companyId, 'law_case_id' => $case->id,
                'actor_user_id' => $this->actorId, 'event_type' => 'datajud_initial_sync',
                'title' => 'Consulta automática não concluída',
                'reason' => 'Não foi possível concluir a consulta automática. O processo está cadastrado; use Consultar Datajud para tentar novamente.',
                'before_state' => json_encode(['datajud_sync_status' => 'pending']),
                'after_state' => json_encode(['datajud_sync_status' => 'error', 'datajud_result_code' => 'initial_sync_failed']),
                'created_at' => $now,
            ]);
        });
    }
}
