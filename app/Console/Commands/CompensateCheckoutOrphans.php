<?php

namespace App\Console\Commands;

use App\Services\MercadoPagoClient;
use App\Services\PlatformAudit;
use App\Services\PrefixedUlid;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompensateCheckoutOrphans extends Command
{
    protected $signature = 'fokus:compensate-checkout-orphans';
    protected $description = 'Confirma o cancelamento de cobranças criadas por checkouts locais que falharam.';

    public function handle(MercadoPagoClient $client, PlatformAudit $audit): int
    {
        $attempts = DB::table('billing_checkout_attempts')
            ->where('status', 'compensation_pending')->whereNotNull('provider_subscription_id')->get();
        $resolved = 0;

        foreach ($attempts as $attempt) {
            $fingerprint = hash('sha256', 'checkout-orphan|'.$attempt->id);
            try {
                $remote = $client->getPreapproval($attempt->provider_subscription_id);
                if (($remote['status'] ?? '') !== 'cancelled') {
                    $client->updatePreapproval($attempt->provider_subscription_id, ['status' => 'cancelled'], 'compensate-'.$attempt->id);
                    $remote = $client->getPreapproval($attempt->provider_subscription_id);
                }

                if (($remote['status'] ?? '') === 'cancelled') {
                    DB::table('billing_checkout_attempts')->where('id', $attempt->id)->where('status', 'compensation_pending')
                        ->update(['status' => 'failed', 'updated_at' => now()]);
                    DB::table('payment_reconciliation_alerts')->where('fingerprint', $fingerprint)->whereIn('status', ['aberta', 'em_revisao'])
                        ->update(['status' => 'corrigida', 'corrected_at' => now(), 'correction_reason' => 'Cancelamento confirmado no gateway.', 'updated_at' => now()]);
                    $audit->record(null, 'billing.checkout_compensation_confirmed', 'billing_checkout_attempt', $attempt->id, $attempt->company_id,
                        'Cancelamento da cobrança órfã confirmado no gateway.', actorType: 'system', channel: 'scheduler', originContext: 'fokus:compensate-checkout-orphans');
                    $resolved++;
                    continue;
                }

                $remoteStatus = (string) ($remote['status'] ?? 'unknown');
            } catch (\Throwable) {
                $remoteStatus = 'unavailable';
                Log::warning('Compensação de checkout pendente', ['attempt_id' => $attempt->id]);
            }

            if (! DB::table('payment_reconciliation_alerts')->where('fingerprint', $fingerprint)->exists()) {
                $alertId = PrefixedUlid::make('RCA');
                DB::table('payment_reconciliation_alerts')->insert([
                    'id' => $alertId, 'company_id' => $attempt->company_id, 'fingerprint' => $fingerprint,
                    'type' => 'checkout_orphan', 'internal_status' => 'compensation_pending', 'mercado_pago_status' => $remoteStatus,
                    'impact' => 'alto', 'status' => 'aberta', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $audit->record(null, 'billing.reconciliation_alert_opened', 'payment_reconciliation_alert', $alertId, $attempt->company_id,
                    'Cobrança órfã requer revisão.', after: ['type' => 'checkout_orphan', 'status' => 'aberta'],
                    actorType: 'system', channel: 'scheduler', originContext: 'fokus:compensate-checkout-orphans');
            }
        }

        $this->info("Compensações confirmadas: {$resolved}; pendentes: ".($attempts->count() - $resolved).'.');

        return self::SUCCESS;
    }
}
