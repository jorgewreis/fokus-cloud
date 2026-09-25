<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class LawUsageMeter
{
    public function contacts(string $companyId): array
    {
        $subscription = $this->subscription($companyId);
        if (! $subscription) {
            return ['available' => false, 'reason' => 'no_subscription', 'used' => null, 'limit' => null, 'percentage' => null, 'over_threshold' => false];
        }

        $entitlement = $this->contactEntitlement($subscription);
        if (! $entitlement) {
            return ['available' => false, 'reason' => 'capacity_not_configured', 'used' => null, 'limit' => null, 'percentage' => null, 'over_threshold' => false];
        }

        $used = $this->countContacts($companyId);
        $limit = $entitlement['limit'];
        $percentage = $limit > 0 ? round(($used / $limit) * 100, 1) : 100.0;
        $overThreshold = $percentage > 70;
        $this->syncThreshold($subscription, $entitlement['module_id'], $entitlement['personalization_code'], $used, $limit, $overThreshold);

        return [
            'available' => true,
            'module_id' => $entitlement['module_id'],
            'module_name' => $entitlement['module_name'],
            'personalization_code' => $entitlement['personalization_code'],
            'label' => $entitlement['label'],
            'used' => $used,
            'limit' => $limit,
            'percentage' => $percentage,
            'over_threshold' => $overThreshold,
        ];
    }

    public function assertContactCapacityAvailable(string $companyId): void
    {
        $subscription = $this->subscription($companyId, lock: true);
        abort_unless($subscription && in_array($subscription->status, ['ativa', 'aguardando_pagamento'], true), 403, 'A empresa não possui uma assinatura ativa do Fokus Law.');
        $entitlement = $this->contactEntitlement($subscription);
        abort_unless($entitlement, 403, 'A assinatura não inclui uma capacidade configurada para contatos.');

        $used = $this->countContacts($companyId, lock: true);
        abort_if($used >= $entitlement['limit'], 422, 'A capacidade contratada para contatos foi atingida. Faça upgrade da capacidade em Configurações > Assinatura.');
    }

    public function countContacts(string $companyId, bool $lock = false): int
    {
        $query = DB::table('law_contacts')->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('merged_into_id');
        if ($lock) $query->lockForUpdate();
        return (int) $query->count();
    }

    private function syncThreshold(object $subscription, string $moduleId, string $code, int $used, int $limit, bool $overThreshold): void
    {
        DB::transaction(function () use ($subscription, $moduleId, $code, $used, $limit, $overThreshold): void {
            // Serialize threshold crossings per subscription so simultaneous contact writes
            // cannot create duplicate notifications for the same crossing.
            DB::table('subscriptions')->where('id', $subscription->id)->lockForUpdate()->first();
            $state = DB::table('law_usage_alert_states')->where('company_id', $subscription->company_id)
                ->where('module_id', $moduleId)->where('personalization_code', $code)->lockForUpdate()->first();
            if (! $state) {
                try {
                    DB::table('law_usage_alert_states')->insert([
                        'company_id' => $subscription->company_id,
                        'module_id' => $moduleId,
                        'personalization_code' => $code,
                        'over_threshold' => false,
                        'updated_at' => now(),
                    ]);
                } catch (QueryException) {
                    // A concurrent request may have inserted the unique state row first.
                }
                $state = DB::table('law_usage_alert_states')->where('company_id', $subscription->company_id)
                    ->where('module_id', $moduleId)->where('personalization_code', $code)->lockForUpdate()->first();
            }

            if (! $overThreshold) {
                if ($state?->over_threshold) {
                    DB::table('law_usage_alert_states')->where('company_id', $subscription->company_id)->where('module_id', $moduleId)->where('personalization_code', $code)->update(['over_threshold' => false, 'updated_at' => now()]);
                    DB::table('law_notifications')->where('company_id', $subscription->company_id)->where('module_id', $moduleId)->where('personalization_code', $code)->whereNull('resolved_at')->update(['resolved_at' => now(), 'updated_at' => now()]);
                }
                return;
            }

            if ($state?->over_threshold) return;

            DB::table('law_usage_alert_states')->where('company_id', $subscription->company_id)->where('module_id', $moduleId)->where('personalization_code', $code)->update(['over_threshold' => true, 'updated_at' => now()]);
            $admins = DB::table('company_memberships as membership')
                ->join('roles as role', 'role.id', '=', 'membership.role_id')
                ->where('membership.company_id', $subscription->company_id)->where('membership.status', 'ativo')->whereNull('membership.deleted_at')
                ->where('role.code', 'admin')->pluck('membership.user_id');
            $moduleName = (string) DB::table('modules')->where('id', $moduleId)->value('name');
            foreach ($admins as $userId) {
                DB::table('law_notifications')->insert([
                    'id' => PrefixedUlid::make('LNO'),
                    'company_id' => $subscription->company_id,
                    'user_id' => $userId,
                    'module_id' => $moduleId,
                    'personalization_code' => $code,
                    'title' => 'Capacidade próxima do limite',
                    'message' => "{$moduleName}: {$used} de {$limit} registros utilizados ({$this->percentage($used, $limit)}%). Avalie aumentar a capacidade.",
                    'payload' => json_encode(['module_code' => 'contatos', 'personalization_code' => $code, 'used' => $used, 'limit' => $limit, 'percentage' => $this->percentage($used, $limit), 'href' => '/portal/fokus-law/assinatura#capacidade-contatos']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function contactEntitlement(object $subscription): ?array
    {
        $snapshot = json_decode((string) ($subscription->commercial_snapshot ?? ''), true) ?: [];
        foreach (($snapshot['items'] ?? []) as $item) {
            $moduleId = (string) ($item['module_id'] ?? '');
            $module = $moduleId !== '' ? DB::table('modules')->where('id', $moduleId)->first(['id', 'code', 'module_code', 'name']) : null;
            if (! $module || (($module->module_code ?: $module->code) !== 'contatos' && $module->code !== 'contatos')) continue;
            foreach (($item['conditions']['personalizations'] ?? []) as $personalization) {
                if (($personalization['type_code'] ?? null) === 'contatos_cadastrados' && (int) ($personalization['value'] ?? 0) > 0) {
                    return ['module_id' => (string) $module->id, 'module_name' => (string) $module->name, 'personalization_code' => 'contatos_cadastrados', 'label' => 'Cadastros de contatos', 'limit' => (int) $personalization['value']];
                }
            }

            // Older contracts may only carry the cap in the item conditions; keep this
            // compatibility path until all active contracts have the full snapshot.
            if (isset($item['conditions']['contatos_cadastrados'])) {
                return ['module_id' => (string) $module->id, 'module_name' => (string) $module->name, 'personalization_code' => 'contatos_cadastrados', 'label' => 'Cadastros de contatos', 'limit' => (int) $item['conditions']['contatos_cadastrados']];
            }
        }
        return null;
    }

    private function subscription(string $companyId, bool $lock = false): ?object
    {
        $query = DB::table('subscriptions as subscription')->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $companyId)->whereIn('product.code', ['law', 'fokus-law'])
            ->whereIn('subscription.status', ['ativa', 'aguardando_pagamento', 'inadimplente', 'suspensa', 'cancelamento_agendado'])
            ->orderByDesc('subscription.created_at')->select('subscription.*');
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }

    private function percentage(int $used, int $limit): float
    {
        return $limit > 0 ? round(($used / $limit) * 100, 1) : 100.0;
    }
}
