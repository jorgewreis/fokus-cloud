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
            abort_if(isset($data['expected_version']) && (int) $data['expected_version'] !== (int) $subscription->version, 409, 'A assinatura mudou desde que a página foi carregada. Atualize antes de continuar.');
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
                abort_if(DB::table('subscription_changes')->where('subscription_id', $subscriptionId)->whereIn('status', ['agendada', 'aguardando_pagamento'])->exists(), 409,
                    'Já existe uma alteração pendente para esta assinatura. Edite ou cancele-a antes de criar outra.');
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
                    'current_period_starts_at' => $after['current_period_starts_at'] ? Carbon::parse($after['current_period_starts_at'])->toDateTimeString() : null,
                    'current_period_ends_at' => $after['current_period_ends_at'] ? Carbon::parse($after['current_period_ends_at'])->toDateTimeString() : null,
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
            $isPlatformAdmin = DB::table('platform_admins')->where('id', $admin->id)->exists();
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
                'reason' => app(AuditSanitizer::class)->sanitizeText((string) $data['reason']),
                'requested_by_user_id' => $isPlatformAdmin ? null : $admin->id,
                'requested_by_platform_admin_id' => $isPlatformAdmin ? $admin->id : null,
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

    public function quoteCustomerChange(string $subscriptionId, array $data): array
    {
        $subscription = DB::table('subscriptions as subscription')->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.id', $subscriptionId)->select('subscription.*', 'product.code as product_code')->first();
        abort_unless($subscription, 404, 'Assinatura não encontrada.');
        abort_if($subscription->status === 'encerrada', 422, 'Assinatura encerrada não pode ser alterada.');

        $before = $this->snapshot($subscription);
        $target = $this->targetPlanSnapshot($subscription, $data)['snapshot'];
        $isIncrease = (float) ($target['amount'] ?? 0) > (float) ($before['amount'] ?? 0)
            || ((float) ($target['amount'] ?? 0) === (float) ($before['amount'] ?? 0) && (float) ($target['monthly_amount'] ?? 0) > (float) ($before['monthly_amount'] ?? 0));
        $action = $isIncrease ? 'upgrade' : 'downgrade';
        $effectiveAt = $action === 'upgrade'
            ? now()
            : ($subscription->current_period_ends_at ? Carbon::parse($subscription->current_period_ends_at) : now());

        return [
            'action' => $action,
            'current' => $before,
            'target' => $target,
            'effective_at' => $effectiveAt,
            'charge_now' => $action === 'upgrade' ? $this->proration($before, $target, $effectiveAt) : 0,
            'version' => (int) $subscription->version,
        ];
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
        $product = DB::table('products')->where('id', $subscription->product_id)->first();
        abort_unless($product, 422, 'Produto da assinatura indisponível.');
        $publishedCatalog = $this->catalog->publicCatalog($product->code);
        $publishedModules = collect($publishedCatalog['modules'] ?? [])->keyBy('code');
        abort_unless($publishedModules->isNotEmpty(), 422, 'Catálogo publicado indisponível para este produto.');

        $planId = (string) ($data['target_plan_id'] ?? '');
        $plan = null;
        $publishedPlan = null;
        if ($planId !== '') {
            $plan = DB::table('plans')->where('id', $planId)->where('product_id', $subscription->product_id)
                ->where('status', 'ativo')->where('publication_state', 'publicado')->first();
            abort_unless($plan, 422, 'O plano publicado informado não está disponível para esta assinatura.');
            $publishedPlan = collect($publishedCatalog['plans'] ?? [])->firstWhere('code', $plan->code);
            abort_unless($publishedPlan, 422, 'O plano não está presente na publicação atual do catálogo.');
            $customizations = collect($data['items'] ?? [])->keyBy('module_code');
            $requestedItems = collect($publishedPlan['module_codes'] ?? [])->map(function (string $code) use ($customizations): array {
                $selected = $customizations->get($code, []);
                return ['module_code' => $code, 'quantity' => 1, 'personalizations' => $selected['personalizations'] ?? []];
            })->all();
        } else {
            $requestedItems = $data['items'] ?? [];
            abort_unless(is_array($requestedItems) && $requestedItems !== [], 422, 'Selecione ao menos um módulo para a assinatura.');
        }

        $cycle = $data['billing_cycle'] ?? $subscription->billing_cycle ?? 'monthly';
        abort_unless(in_array($cycle, ['monthly', 'annual'], true), 422, 'Ciclo de cobrança inválido.');
        $moduleCodes = array_map(fn (array $item): string => (string) ($item['module_code'] ?? ''), $requestedItems);
        abort_if(in_array('', $moduleCodes, true) || count($moduleCodes) !== count(array_unique($moduleCodes)), 422, 'Cada módulo pode ser selecionado somente uma vez.');
        foreach ($moduleCodes as $code) {
            abort_unless($publishedModules->has($code), 422, 'Há um módulo indisponível no catálogo publicado.');
            if (! $plan) abort_unless((bool) ($publishedModules->get($code)['available_standalone'] ?? false), 422, 'Um dos módulos selecionados só pode ser contratado por meio de um plano publicado.');
        }
        $moduleCatalog = $publishedModules;
        foreach ($requestedItems as $requested) {
            $module = $moduleCatalog->get((string) $requested['module_code']);
            $moduleDependencies = collect($module['dependencies'] ?? [])->pluck('code')->all();
            foreach ($moduleDependencies as $dependency) abort_unless(in_array($dependency, $moduleCodes, true), 422, 'A composição precisa incluir todos os módulos dependentes.');
            $moduleIncompatibilities = collect($module['incompatibilities'] ?? [])->pluck('code')->all();
            foreach ($moduleIncompatibilities as $incompatible) abort_if(in_array($incompatible, $moduleCodes, true), 422, 'A composição inclui módulos incompatíveis.');
            abort_unless((int) ($requested['quantity'] ?? 1) >= 1 && (int) ($requested['quantity'] ?? 1) <= 1000, 422, 'Quantidade de módulo inválida.');
        }

        $defaults = $publishedPlan['personalization_defaults'] ?? [];
        $items = collect($requestedItems)->map(function (array $requested) use ($publishedModules, $subscription, $plan, $cycle, $defaults): array {
            $moduleCode = (string) $requested['module_code'];
            $module = $publishedModules->get($moduleCode);
            abort_unless($module, 422, 'O plano publicado contém uma funcionalidade indisponível.');
            $databaseModule = DB::table('modules')->where('product_id', $subscription->product_id)->where('code', $moduleCode)->first();
            abort_unless($databaseModule, 422, 'Módulo não encontrado no catálogo local.');
            [$personalizations, $delta] = $this->selectedPersonalizations($module, $requested['personalizations'] ?? [], $defaults);
            $baseMonthly = (float) $module['monthly_amount'];
            $unitMonthly = $baseMonthly + array_sum(array_column($personalizations, 'additional_monthly_amount'));
            $unitPrice = $cycle === 'annual' ? $unitMonthly * 10 : $unitMonthly;

            return [
                'module_id' => $databaseModule->id,
                'name' => $module['name'],
                'quantity' => (int) ($requested['quantity'] ?? 1),
                'unit_price' => $unitPrice,
                'conditions' => [
                    'cycle' => $cycle,
                    'selection_mode' => $plan ? 'plan' : 'modules',
                    'plan_code' => $plan->code ?? null,
                    'module_code' => $module['module_code'] ?? null,
                    'segments' => $module['segments'] ?? [],
                    'context_code' => $module['context_code'] ?? null,
                    'personalizations' => $personalizations,
                    'personalization_delta' => $delta,
                ],
            ];
        })->values()->all();

        $monthlyAmount = $plan
            ? (float) $publishedPlan['monthly_amount'] + collect($items)->sum(fn (array $item): float => (float) ($item['conditions']['personalization_delta'] ?? 0))
            : collect($items)->sum(fn (array $item): float => (float) $item['unit_price'] * $item['quantity'] / ($cycle === 'annual' ? 10 : 1));
        $amount = $cycle === 'annual' ? CatalogPricing::annualFromMonthly($monthlyAmount) : round($monthlyAmount, 2);

        return [
            'monthly_amount' => $monthlyAmount,
            'amount' => $amount,
            'snapshot' => [
                'plan_id' => $plan->id ?? null,
                'plan_code' => $plan->code ?? null,
                'plan_name' => $plan->name ?? 'Personalizada',
                'publication_versions' => [
                    'product_catalog_version' => (int) ($publishedCatalog['published_version'] ?? 0),
                    'plan_version' => isset($publishedPlan['published_version']) ? (int) $publishedPlan['published_version'] : null,
                    'module_versions' => collect($moduleCodes)->mapWithKeys(fn (string $code): array => [$code => (int) ($publishedModules->get($code)['published_version'] ?? 0)])->all(),
                ],
                'billing_cycle' => $cycle,
                'monthly_amount' => $monthlyAmount,
                'amount' => $amount,
                'items' => $items,
            ],
        ];
    }

    private function selectedPersonalizations(array $module, array $requested, array $planDefaults): array
    {
        $requestedByType = collect($requested)->keyBy('type_code');
        $defaultsById = collect($planDefaults)->keyBy('personalization_id');
        $selections = [];
        $delta = 0.0;
        foreach ($module['personalizations'] ?? [] as $personalization) {
            if (empty($personalization['active'])) continue;
            $tiers = collect($personalization['tiers'] ?? [])->where('active', true)->sortBy('value')->values();
            if ($tiers->isEmpty()) continue;
            $planDefault = $defaultsById->get($personalization['id']);
            $default = $planDefault ? $tiers->firstWhere('id', $planDefault['tier_id']) : ($personalization['required'] ? $tiers->first() : null);
            $choice = $requestedByType->get($personalization['type_code']);
            $tier = $choice ? $tiers->firstWhere('value', (int) $choice['tier_value']) : $default;
            if ($personalization['required']) abort_unless($tier && $default, 422, 'Personalização obrigatória sem faixa padrão disponível.');
            if (! $tier) continue;
            if ($default) abort_if((int) $tier['value'] < (int) $default['value'], 422, 'A faixa selecionada é inferior à faixa mínima do plano.');
            if ($default) $delta += (float) $tier['additional_monthly_amount'] - (float) $default['additional_monthly_amount'];
            $selections[] = ['personalization_id' => $personalization['id'], 'type_code' => $personalization['type_code'], 'tier_id' => $tier['id'], 'value' => (int) $tier['value'], 'additional_monthly_amount' => (float) $tier['additional_monthly_amount']];
        }
        foreach ($requestedByType as $typeCode => $choice) abort_unless(collect($module['personalizations'] ?? [])->pluck('type_code')->contains($typeCode), 422, 'Personalização inválida para este módulo.');
        return [$selections, $delta];
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
