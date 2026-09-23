<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionChangeManager
{
    public function __construct(private readonly CatalogManager $catalog)
    {
    }

    public function change(string $subscriptionId, array $data, object $admin): array
    {
        return DB::transaction(function () use ($subscriptionId, $data, $admin): array {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first();
            abort_unless($subscription, 404, 'Assinatura não encontrada.');
            abort_if($subscription->status === 'encerrada', 422, 'Assinatura encerrada não pode ser alterada.');

            $before = $this->snapshot($subscription);
            $action = (string) $data['action'];
            if (in_array($action, ['reativacao', 'upgrade', 'downgrade'], true) && $subscription->status === 'suspensa' && ! $subscription->provider_subscription_id) {
                abort_if(DB::table('voucher_redemptions as redemption')->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
                    ->where('redemption.subscription_id', $subscriptionId)->where('voucher.discount_type', 'trial_free')
                    ->where('redemption.benefit_ends_at', '<=', now())->exists(), 422, 'O benefício gratuito terminou. Inicie uma nova contratação paga.');
            }
            $after = $before;
            $status = 'aplicada';
            $effectiveAt = now();
            $prorationAmount = 0;
            $updates = ['updated_at' => now(), 'version' => DB::raw('version + 1')];

            if ($action === 'suspensao') {
                abort_unless(in_array($subscription->status, ['ativa', 'inadimplente'], true), 422, 'Somente assinaturas ativas podem ser suspensas.');
                $updates['status'] = 'suspensa';
                $after['status'] = 'suspensa';
            } elseif ($action === 'reativacao') {
                abort_unless($subscription->status === 'suspensa', 422, 'Somente assinaturas suspensas podem ser reativadas.');
                $updates['status'] = 'ativa';
                $after['status'] = 'ativa';
            } elseif (in_array($action, ['cancelamento', 'cancelamento_imediato'], true)) {
                if ($action === 'cancelamento_imediato') {
                    $effectiveAt = now();
                    $updates['status'] = 'encerrada';
                    $updates['open_company_product'] = null;
                    $updates['cancel_at'] = $effectiveAt;
                    $after['status'] = 'encerrada';
                    $after['cancel_at'] = $effectiveAt->toISOString();
                } else {
                    $effectiveAt = $subscription->current_period_ends_at ? Carbon::parse($subscription->current_period_ends_at) : now();
                    $updates['status'] = 'cancelamento_agendado';
                    $updates['cancel_at'] = $effectiveAt;
                    $after['status'] = 'cancelamento_agendado';
                    $after['cancel_at'] = $effectiveAt->toISOString();
                }
            } elseif (in_array($action, ['upgrade', 'downgrade'], true)) {
                $target = $this->targetPlanSnapshot($subscription, $data);
                $after = [...$before, ...$target['snapshot']];
                $status = $action === 'upgrade' ? 'aguardando_pagamento' : 'agendada';
                $effectiveAt = $action === 'upgrade'
                    ? now()
                    : ($subscription->current_period_ends_at ? Carbon::parse($subscription->current_period_ends_at) : now());
                $prorationAmount = $action === 'upgrade'
                    ? $this->proration($before, $target['snapshot'], $effectiveAt)
                    : 0;
            } elseif ($action === 'override') {
                abort_unless($admin->hasPermission('platform.commercial.override'), 403, 'Somente o superadministrador pode executar override comercial.');
                $after = $this->overrideSnapshot($before, $data['override'] ?? []);
                if (array_key_exists('items', $data['override'] ?? [])) {
                    $this->replaceItems($subscription, $after['items'], $admin->id);
                }
                $updates = [
                    'billing_cycle' => $after['billing_cycle'],
                    'current_period_starts_at' => $after['current_period_starts_at'],
                    'current_period_ends_at' => $after['current_period_ends_at'],
                    'commercial_snapshot' => json_encode($after),
                    'updated_at' => now(),
                    'version' => DB::raw('version + 1'),
                ];
            } else {
                abort(422, 'Ação de assinatura inválida.');
            }

            if (in_array($action, ['suspensao', 'reativacao', 'cancelamento', 'cancelamento_imediato', 'override'], true)) {
                $after['status'] = $updates['status'] ?? $subscription->status;
                $updates['commercial_snapshot'] = json_encode($after);
                DB::table('subscriptions')->where('id', $subscriptionId)->update($updates);
            }

            $changeId = PrefixedUlid::make('SCH');
            DB::table('subscription_changes')->insert([
                'id' => $changeId,
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscriptionId,
                'type' => $action === 'cancelamento_imediato' ? 'cancelamento' : $action,
                'status' => $status,
                'effective_at' => $effectiveAt,
                'proration_amount' => $prorationAmount,
                'items_snapshot' => json_encode($after['items'] ?? []),
                'before_snapshot' => json_encode($before),
                'after_snapshot' => json_encode($after),
                'reason' => $data['reason'],
                'requested_by_platform_admin_id' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $changeId,
                'status' => $status,
                'effective_at' => $effectiveAt,
                'proration_amount' => $prorationAmount,
                'before' => $before,
                'after' => $after,
            ];
        });
    }

    public function applyApprovedChange(string $changeId, ?string $adminId = null): array
    {
        return DB::transaction(function () use ($changeId, $adminId): array {
            $change = DB::table('subscription_changes')->where('id', $changeId)->lockForUpdate()->first();
            abort_unless($change, 404, 'Alteração de assinatura não encontrada.');
            abort_unless($change->status === 'aguardando_pagamento', 422, 'A alteração não está aguardando pagamento.');

            $subscription = DB::table('subscriptions')->where('id', $change->subscription_id)->lockForUpdate()->first();
            abort_unless($subscription, 404, 'Assinatura não encontrada.');
            $after = json_decode((string) $change->after_snapshot, true) ?: [];
            $before = $this->snapshot($subscription);
            $items = $after['items'] ?? [];

            $this->replaceItems($subscription, $items, $adminId);

            $after['status'] = 'ativa';
            DB::table('subscriptions')->where('id', $subscription->id)->update([
                'status' => 'ativa',
                'billing_cycle' => $after['billing_cycle'] ?? $subscription->billing_cycle,
                'commercial_snapshot' => json_encode($after),
                'updated_at' => now(),
                'version' => DB::raw('version + 1'),
            ]);
            DB::table('subscription_changes')->where('id', $change->id)->update([
                'status' => 'aplicada',
                'approved_by_platform_admin_id' => $adminId,
                'before_snapshot' => json_encode($before),
                'after_snapshot' => json_encode($after),
                'updated_at' => now(),
                'version' => DB::raw('version + 1'),
            ]);

            return ['before' => $before, 'after' => $after];
        });
    }

    public function applyScheduledChange(string $changeId, ?string $adminId = null): ?array
    {
        return DB::transaction(function () use ($changeId, $adminId): ?array {
            $change = DB::table('subscription_changes')->where('id', $changeId)->lockForUpdate()->first();
            if (! $change || $change->status !== 'agendada') {
                return null;
            }

            $subscription = DB::table('subscriptions')->where('id', $change->subscription_id)->lockForUpdate()->first();
            if (! $subscription) {
                DB::table('subscription_changes')->where('id', $change->id)->update(['status' => 'falhou', 'updated_at' => now()]);

                return null;
            }

            $before = $this->snapshot($subscription);
            $after = json_decode((string) $change->after_snapshot, true) ?: $before;
            if ($change->type !== 'cancelamento') {
                $this->replaceItems($subscription, $after['items'] ?? [], $adminId);
            }
            $after['status'] = $change->type === 'cancelamento' ? 'encerrada' : 'ativa';

            DB::table('subscriptions')->where('id', $subscription->id)->update([
                'status' => $after['status'],
                'open_company_product' => $after['status'] === 'encerrada' ? null : $subscription->open_company_product,
                'billing_cycle' => $after['billing_cycle'] ?? $subscription->billing_cycle,
                'commercial_snapshot' => json_encode($after),
                'cancel_at' => $after['status'] === 'encerrada' ? null : ($subscription->cancel_at ?? null),
                'updated_at' => now(),
                'version' => DB::raw('version + 1'),
            ]);
            DB::table('subscription_changes')->where('id', $change->id)->update([
                'status' => 'aplicada',
                'before_snapshot' => json_encode($before),
                'after_snapshot' => json_encode($after),
                'updated_at' => now(),
                'version' => DB::raw('version + 1'),
            ]);

            return ['before' => $before, 'after' => $after];
        });
    }

    public function snapshot(object $subscription): array
    {
        $stored = json_decode((string) ($subscription->commercial_snapshot ?? ''), true);
        if (is_array($stored) && $stored !== []) {
            return [...$this->withPlanMetadata($stored, $subscription), 'status' => $subscription->status, 'cancel_at' => $subscription->cancel_at];
        }

        $product = DB::table('products')->where('id', $subscription->product_id)->first();
        $items = DB::table('subscription_items')->where('subscription_id', $subscription->id)->whereNull('deleted_at')->get();
        $mappedItems = $items->map(function (object $item): array {
            $conditions = json_decode((string) $item->conditions_snapshot, true) ?: [];

            return [
                'module_id' => $item->module_id,
                'name' => $item->name_snapshot,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price_snapshot,
                'conditions' => $conditions,
            ];
        })->values()->all();

        $monthlyAmount = $subscription->billing_cycle === 'annual'
            ? round((float) collect($mappedItems)->sum(fn (array $item): float => $item['unit_price'] * $item['quantity']) / 10, 2)
            : round((float) collect($mappedItems)->sum(fn (array $item): float => $item['unit_price'] * $item['quantity']), 2);

        return $this->withPlanMetadata([
            'subscription_id' => $subscription->id,
            'company_id' => $subscription->company_id,
            'product_id' => $subscription->product_id,
            'product_code' => $product?->code,
            'product_name' => $product?->name,
            'plan_code' => collect($mappedItems)->pluck('conditions.plan_code')->filter()->first(),
            'billing_cycle' => $subscription->billing_cycle,
            'monthly_amount' => $monthlyAmount,
            'amount' => $subscription->billing_cycle === 'annual' ? CatalogPricing::annualFromMonthly($monthlyAmount) : $monthlyAmount,
            'current_period_starts_at' => $subscription->current_period_starts_at,
            'current_period_ends_at' => $subscription->current_period_ends_at,
            'cancel_at' => $subscription->cancel_at,
            'status' => $subscription->status,
            'items' => $mappedItems,
        ], $subscription);
    }

    private function withPlanMetadata(array $snapshot, object $subscription): array
    {
        $plan = null;
        if (! empty($snapshot['plan_id'])) {
            $plan = DB::table('plans')->where('id', $snapshot['plan_id'])->where('product_id', $subscription->product_id)->first(['id', 'code', 'name']);
        }
        if (! $plan && ! empty($snapshot['plan_code'])) {
            $plan = DB::table('plans')->where('product_id', $subscription->product_id)->where('code', $snapshot['plan_code'])->first(['id', 'code', 'name']);
        }

        if ($plan) {
            $snapshot['plan_id'] = $plan->id;
            $snapshot['plan_code'] = $plan->code;
            $snapshot['plan_name'] = $plan->name;
        }

        return $snapshot;
    }

    private function targetPlanSnapshot(object $subscription, array $data): array
    {
        $planId = (string) ($data['target_plan_id'] ?? '');
        $plan = DB::table('plans')->join('products', 'products.id', '=', 'plans.product_id')
            ->where('plans.id', $planId)->where('plans.product_id', $subscription->product_id)
            ->where('plans.status', 'ativo')->where('plans.publication_state', 'publicado')
            ->select('plans.*', 'products.code as product_code', 'products.name as product_name')->first();
        abort_unless($plan, 422, 'O plano publicado informado não está disponível para esta assinatura.');

        $publishedCatalog = $this->catalog->publicCatalog($plan->product_code);
        $publishedPlan = collect($publishedCatalog['plans'] ?? [])->firstWhere('code', $plan->code);
        abort_unless($publishedPlan, 422, 'O plano não está presente na publicação atual do catálogo.');
        $cycle = $data['billing_cycle'] ?? $subscription->billing_cycle ?? 'monthly';
        $publishedModules = collect($publishedCatalog['modules'] ?? [])->keyBy('code');
        $moduleCodes = $publishedPlan['module_codes'] ?? [];
        $items = collect($moduleCodes)->map(function (string $moduleCode) use ($publishedModules, $subscription, $plan, $cycle): array {
            $module = $publishedModules->get($moduleCode);
            abort_unless($module, 422, 'O plano publicado contém uma funcionalidade indisponível.');

            return [
                'module_id' => DB::table('modules')->where('product_id', $subscription->product_id)->where('code', $moduleCode)->value('id'),
                'name' => $module['name'],
                'quantity' => 1,
                'unit_price' => $cycle === 'annual' ? CatalogPricing::annualFromMonthly((float) $module['monthly_amount']) : (float) $module['monthly_amount'],
                'conditions' => [
                    'plan_code' => $plan->code,
                    'module_code' => $module['module_code'] ?? null,
                    'segments' => $module['segments'] ?? [],
                    'context_code' => $module['context_code'] ?? null,
                    'personalizations' => $module['personalizations'] ?? [],
                ],
            ];
        })->values()->all();

        $monthlyAmount = (float) $publishedPlan['monthly_amount'];

        return [
            'monthly_amount' => $monthlyAmount,
            'snapshot' => [
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'plan_name' => $plan->name,
                'publication_versions' => [
                    'product_catalog_version' => (int) ($publishedCatalog['published_version'] ?? 0),
                    'plan_version' => (int) ($publishedPlan['published_version'] ?? 0),
                    'module_versions' => collect($moduleCodes)->mapWithKeys(fn (string $code): array => [$code => (int) ($publishedModules->get($code)['published_version'] ?? 0)])->all(),
                ],
                'billing_cycle' => $cycle,
                'monthly_amount' => $monthlyAmount,
                'amount' => $cycle === 'annual' ? CatalogPricing::annualFromMonthly($monthlyAmount) : round($monthlyAmount, 2),
                'items' => $items,
            ],
        ];
    }

    private function overrideSnapshot(array $before, array $override): array
    {
        $allowed = ['monthly_amount', 'billing_cycle', 'current_period_starts_at', 'current_period_ends_at', 'items'];
        $unknown = array_diff(array_keys($override), $allowed);
        abort_if($unknown !== [], 422, 'Override contém campos não permitidos.');
        abort_if(array_key_exists('billing_cycle', $override) && ! in_array($override['billing_cycle'], ['monthly', 'annual'], true), 422, 'Ciclo de cobrança inválido.');

        $after = [...$before, ...$override];
        if (array_key_exists('monthly_amount', $override)) {
            abort_if(! is_numeric($override['monthly_amount']) || (float) $override['monthly_amount'] < 0, 422, 'Valor mensal inválido.');
            $after['monthly_amount'] = round((float) $override['monthly_amount'], 2);
            $after['amount'] = ($after['billing_cycle'] ?? 'monthly') === 'annual'
                ? CatalogPricing::annualFromMonthly($after['monthly_amount'])
                : $after['monthly_amount'];
        }

        if (array_key_exists('items', $override)) {
            abort_unless(is_array($override['items']) && $override['items'] !== [], 422, 'Override precisa conter ao menos um item.');
            foreach ($override['items'] as $item) {
                abort_unless(isset($item['module_id'], $item['quantity'], $item['unit_price'], $item['name']), 422, 'Item de override incompleto.');
                abort_unless((int) $item['quantity'] > 0 && is_numeric($item['unit_price']) && (float) $item['unit_price'] >= 0, 422, 'Item de override inválido.');
            }
        }

        return $after;
    }

    private function proration(array $before, array $after, Carbon $effectiveAt): float
    {
        $difference = max(0, (float) ($after['monthly_amount'] ?? 0) - (float) ($before['monthly_amount'] ?? 0));
        $start = $before['current_period_starts_at'] ? Carbon::parse($before['current_period_starts_at']) : now();
        $end = $before['current_period_ends_at'] ? Carbon::parse($before['current_period_ends_at']) : now()->addMonth();
        $totalDays = max(1, $start->diffInDays($end));
        $remainingDays = max(0, $effectiveAt->diffInDays($end, false));

        return round($difference * $remainingDays / $totalDays, 2);
    }

    private function replaceItems(object $subscription, array $items, ?string $adminId): void
    {
        DB::table('subscription_items')->where('subscription_id', $subscription->id)->whereNull('deleted_at')->update([
            'deleted_at' => now(),
            'deleted_by' => $adminId,
            'updated_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        foreach ($items as $item) {
            DB::table('subscription_items')->insert([
                'id' => PrefixedUlid::make('ITM'),
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id,
                'module_id' => $item['module_id'] ?? null,
                'name_snapshot' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price_snapshot' => $item['unit_price'],
                'conditions_snapshot' => json_encode($item['conditions'] ?? []),
                'version' => 1,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
