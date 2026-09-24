<?php

namespace App\Console\Commands;

use App\Services\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireSupportSessions extends Command
{
    protected $signature = 'fokus:expire-support-sessions';
    protected $description = 'Encerra sessões de suporte que ultrapassaram o prazo máximo.';

    public function handle(PlatformAudit $audit): int
    {
        $cutoff = now()->subMinutes((int) config('security.support_session_minutes'));
        $expired = DB::table('platform_support_sessions')->whereNull('ended_at')->where('started_at', '<=', $cutoff)->get();
        $count = 0;

        foreach ($expired as $support) {
            if (! DB::table('platform_support_sessions')->where('id', $support->id)->whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()])) {
                continue;
            }

            $audit->record(null, 'backoffice.support_access_ended', 'platform_support_session', $support->id, $support->company_id,
                'Acesso de suporte encerrado por expiração.', before: ['status' => 'active'], after: ['status' => 'ended'],
                actorType: 'system', channel: 'scheduler', originContext: 'fokus:expire-support-sessions');
            $count++;
        }

        $this->info("Sessões de suporte expiradas: {$count}.");

        return self::SUCCESS;
    }
}
