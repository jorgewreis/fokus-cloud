<?php

namespace App\Console\Commands;

use App\Services\PlatformAudit;
use App\Services\SubscriptionChangeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyScheduledSubscriptionChanges extends Command
{
    protected $signature = 'fokus:apply-subscription-changes';
    protected $description = 'Aplica cancelamentos e downgrades cuja vigência chegou ao fim.';

    public function handle(SubscriptionChangeManager $manager, PlatformAudit $audit): int
    {
        $scheduled = DB::table('subscription_changes')->where('status', 'agendada')->where('effective_at', '<=', now())->orderBy('effective_at')->get();
        $applied = 0;
        foreach ($scheduled as $change) {
            $result = $manager->applyScheduledChange($change->id);
            if ($result) {
                $applied++;
                $subscription = DB::table('subscriptions')->where('id', $change->subscription_id)->first();
                $audit->record(null, 'subscription_change_applied', 'subscription', $change->subscription_id, $subscription?->company_id, 'Aplicação automática da alteração agendada', metadata: ['change_id' => $change->id, 'type' => $change->type], before: $result['before'], after: $result['after'], actorType: 'system', channel: 'scheduler', originContext: 'fokus:apply-subscription-changes');
            }
        }
        $this->info("Alterações aplicadas: {$applied}");
        return self::SUCCESS;
    }
}
