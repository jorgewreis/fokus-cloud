<?php

namespace App\Console\Commands;

use App\Services\PlatformAudit;
use App\Services\SubscriptionChangeManager;
use App\Services\MercadoPagoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyScheduledSubscriptionChanges extends Command
{
    protected $signature = 'fokus:apply-subscription-changes';
    protected $description = 'Aplica cancelamentos e downgrades cuja vigência chegou ao fim.';

    public function handle(SubscriptionChangeManager $manager, PlatformAudit $audit, MercadoPagoClient $mercadoPago): int
    {
        $scheduled = DB::table('subscription_changes')->where('status', 'agendada')->where('effective_at', '<=', now())->orderBy('effective_at')->get();
        $applied = 0;
        foreach ($scheduled as $change) {
            $subscription = DB::table('subscriptions')->where('id', $change->subscription_id)->first();
            if ($change->type === 'downgrade' && $subscription?->provider_subscription_id) {
                $target = json_decode((string) $change->after_snapshot, true) ?: [];
                try {
                    $cycle = $target['billing_cycle'] ?? $subscription->billing_cycle ?? 'monthly';
                    $mercadoPago->updatePreapproval((string) $subscription->provider_subscription_id, [
                        'auto_recurring' => [
                            'frequency' => $cycle === 'annual' ? 12 : 1,
                            'frequency_type' => 'months',
                            'transaction_amount' => (float) ($target['amount'] ?? 0),
                            'currency_id' => 'BRL',
                        ],
                    ], 'law-scheduled-change-'.$change->id);
                } catch (\Throwable $exception) {
                    report($exception);
                    $this->error('Não foi possível atualizar a recorrência no Mercado Pago para '.$change->id.'. A alteração será tentada novamente.');
                    continue;
                }
            }
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
