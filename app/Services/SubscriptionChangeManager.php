<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionChangeManager
{
    public function __construct(private readonly CatalogManager $catalog, private readonly VoucherManager $vouchers)
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
                    ->where('redemption.benefit_ends_at', '<=', now())->exists() && $subscription->current_period_ends_at && now()->gte($subscription->current_period_ends_at), 422, 'O benefício gratuito terminou. Inicie uma nova contratação paga.');
            }
            if (! in_array($action, ['upgrade', 'downgrade', 'cancelamento_imediato'], true)) {
                abort_if(DB::table('subscription_changes')->where('subscription_id', $subscriptionId)->whereIn('status', ['agendada', 'aguardando_pagamento'])->exists(),
                    409, 'Existe uma alteração pendente. Cancele-a antes de executar outra ação.');
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
                    abort_unless(in_array($subscription->status, ['ativa', 'inadimplente', 'suspensa'], true), 422, 'Esta assinatura não pode receber um novo cancelamento agendado.');
                    $status = 'agendada';
                    $effectiveAt = $subscription->current_period_ends_at ? Carbon::parse($subscription->current_period_ends_at) : now();
                    $updates['status'] = 'cancelamento_agendado';
                    $updates['cancel_at'] = $effectiveAt;
                    $after['status'] = 'cancelamento_agendado';
                    $after['cancel_at'] = $effectiveAt->toISOString();
                }
            } elseif (in_array($action, ['upgrade', 'downgrade'], true)) {
                abort_if(DB::table('subscription_changes')->where('subscription_id', $subscriptionId)->whereIn('status', ['agendada', 'aguardando_pagamento'])->exists(), 409,
                    'Já existe uma alteração pendente para esta assinatura. Edite ou cancele-a antes de criar outra.');
                abort_unless($subscription->status === 'ativa', 422, 'A assinatura precisa estar ativa para alterar planos ou módulos.');
                $quote = $this->quoteCustomerChange($subscriptionId, $data);
                abort_unless($action === $quote['action'], 422, 'Esta composição corresponde a '.($quote['action'] === 'upgrade' ? 'uma ampliação' : 'uma redução').'. Confira o resumo da alteração.');
                abort_if($quote['requires_new_voucher'], 422, $quote['voucher_message']);
                abort_if(! $quote['free_benefit'] && ! $subscription->provider_subscription_id,
                    422, 'Esta assinatura não tem uma cobrança recorrente ativa. Use um voucher gratuito elegível ou inicie uma nova contratação paga.');
                $after = [...$before, ...$quote['target']];
                $status = $action === 'upgrade' ? 'aguardando_pagamento' : 'agendada';
                $effectiveAt = Carbon::parse($quote['effective_at']);
                $prorationAmount = $quote['charge_now'];
                if (! empty($data['voucher_code'])) {
                    abort_unless($action === 'upgrade', 422, 'Aplique o novo voucher em uma ampliação imediata da assinatura.');
                    $voucher = $this->vouchers->findEligible($data['voucher_code'], $subscription->product_id, $subscription->company_id,
                        array_column(array_column($after['items'], 'conditions'), 'catalog_module_code'), $after['plan_code'] ?? null);
                    $reservation = $this->vouchers->reserve($voucher, $subscription->company_id, 'change-'.PrefixedUlid::make('SCH'), [
                        'code' => $voucher->code, 'name' => $voucher->name, 'discount_type' => $voucher->discount_type,
                        'discount_value' => (float) $voucher->discount_value, 'benefit_duration' => $voucher->benefit_duration,
                        'discount_amount' => $quote['voucher_discount'], 'subscription_id' => $subscriptionId,
                        'product_id' => $subscription->product_id, 'plan_code' => $after['plan_code'] ?? null,
                        'module_codes' => array_column(array_column($after['items'], 'conditions'), 'catalog_module_code'),
                        'application' => $quote['free_benefit'] ? 'subscription_free' : 'upgrade_charge',
                    ], $admin->id, actorType: DB::table('platform_admins')->where('id', $admin->id)->exists() ? 'admin' : 'customer');
                    $this->vouchers->attachSubscription($reservation->id, $subscriptionId);
                    $after['upgrade_voucher_reservation_id'] = $reservation->id;
                }
            } elseif ($action === 'override') {
                abort_unless($admin->hasPermission('platform.commercial.override'), 403, 'Somente o superadministrador pode executar override comercial.');
                $after = $this->overrideSnapshot($before, $data['override'] ?? []);
                $benefit = $this->changeVoucher($subscription, $after, []);
                abort_if($benefit['blocked'], 422, $benefit['message']);
                if ($benefit['free']) $after = [...$after, ...$benefit['snapshot']];
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

            if ($subscription->provider_subscription_id && in_array($action, ['suspensao', 'reativacao', 'cancelamento_imediato', 'override'], true)) {
                $gatewayData = match ($action) {
                    'suspensao' => ['status' => 'paused'], 'reativacao' => ['status' => 'authorized'],
                    'cancelamento_imediato' => ['status' => 'cancelled'],
                    default => ['auto_recurring' => ['frequency' => $after['billing_cycle'] === 'annual' ? 12 : 1,
                        'frequency_type' => 'months', 'transaction_amount' => (float) $after['amount'], 'currency_id' => 'BRL']],
                };
                app(MercadoPagoClient::class)->updatePreapproval((string) $subscription->provider_subscription_id, $gatewayData, 'action-'.$subscriptionId.'-'.$subscription->version.'-'.$action);
                $updates['provider_status'] = $gatewayData['status'] ?? $subscription->provider_status;
            }
            if ($action === 'cancelamento_imediato') $this->cancelPending($subscriptionId, $admin->id, (string) $data['reason']);
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

            if ($action === 'upgrade' && ! empty($after['free_benefit'])) {
                $applied = $this->applyApprovedChange($changeId, $isPlatformAdmin ? $admin->id : null);
                $after = $applied['after'];
                $status = 'aplicada';
            }

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

    public function cancelPending(string $subscriptionId, ?string $actorId = null, string $reason = 'Cancelamento da alteração pendente.'): array
    {
        return DB::transaction(function () use ($subscriptionId, $actorId, $reason): array {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first();
            abort_unless($subscription, 404, 'Assinatura não encontrada.');
            $pending = DB::table('subscription_changes')->where('subscription_id', $subscriptionId)->whereIn('status', ['agendada', 'aguardando_pagamento'])->lockForUpdate()->get();
            $before = $this->snapshot($subscription);
            $isPlatformActor = $actorId && DB::table('platform_admins')->where('id', $actorId)->exists();
            foreach ($pending as $change) {
                $after = json_decode((string) $change->after_snapshot, true) ?: [];
                if (! empty($after['upgrade_voucher_reservation_id'])) $this->vouchers->release($after['upgrade_voucher_reservation_id'], actorId: $actorId, actorType: $isPlatformActor ? 'admin' : ($actorId ? 'customer' : 'system'));
                DB::table('subscription_changes')->where('id', $change->id)->update(['status' => 'cancelada', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
                DB::table('payments')->where('subscription_change_id', $change->id)->where('status', 'aguardando_pagamento')->update(['status' => 'cancelado', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            }
            if ($subscription->status === 'cancelamento_agendado') {
                $original = $pending->firstWhere('type', 'cancelamento');
                $prior = $original ? (json_decode((string) $original->before_snapshot, true) ?: []) : [];
                $restored = in_array($prior['status'] ?? '', ['ativa', 'suspensa', 'inadimplente'], true) ? $prior['status'] : 'ativa';
                DB::table('subscriptions')->where('id', $subscriptionId)->update(['status' => $restored, 'cancel_at' => null,
                    'commercial_snapshot' => json_encode([...$before, 'status' => $restored, 'cancel_at' => null]), 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            }
            $after = $this->snapshot(DB::table('subscriptions')->where('id', $subscriptionId)->first());
            app(AuditRecorder::class)->company($subscription->company_id, $isPlatformActor ? null : $actorId, 'subscription', $subscriptionId, 'update', $before, $after, reason: $reason,
                actorType: $isPlatformActor ? 'admin' : ($actorId ? 'customer' : 'system'));
            return ['before' => $before, 'after' => $after, 'count' => $pending->count()];
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
        $currentBase = (float) ($before['base_amount'] ?? $before['amount'] ?? 0);
        $currentMonthlyBase = (float) ($before['base_monthly_amount'] ?? $before['monthly_amount'] ?? 0);
        $added = array_diff(array_column($target['items'], 'module_id'), array_column($before['items'] ?? [], 'module_id'));
        $isIncrease = (float) $target['amount'] > $currentBase
            || ($added !== [] && (float) $target['amount'] === $currentBase)
            || ((float) $target['amount'] === $currentBase && (float) $target['monthly_amount'] > $currentMonthlyBase);
        $action = $isIncrease ? 'upgrade' : 'downgrade';
        $effectiveAt = $action === 'upgrade' ? now() : ($subscription->current_period_ends_at ? Carbon::parse($subscription->current_period_ends_at) : now());
        $benefit = $this->changeVoucher($subscription, $target, $data);
        if (! $benefit['free'] && ! $benefit['blocked'] && ! $subscription->provider_subscription_id) {
            $benefit['blocked'] = true;
            $benefit['message'] = 'A assinatura não tem cobrança recorrente ativa. Informe um voucher gratuito elegível ou inicie uma nova contratação paga.';
        }
        $target = [...$target, ...$benefit['snapshot']];
        $charge = $action === 'upgrade' ? $this->proration([...$before, 'monthly_amount' => $currentMonthlyBase], $target, $effectiveAt) : 0;
        if ($benefit['free']) $charge = 0;
        $discount = $benefit['voucher'] && ! $benefit['free'] ? $this->vouchers->discount($benefit['voucher'], $charge) : ($benefit['free'] ? (float) $target['base_amount'] : 0);
        return [
            'action' => $action, 'current' => $before, 'target' => $target, 'effective_at' => $effectiveAt,
            'charge_now' => round(max(0, $charge - ($benefit['free'] ? 0 : $discount)), 2),
            'free_benefit' => $benefit['free'], 'requires_new_voucher' => $benefit['blocked'],
            'voucher_message' => $benefit['message'], 'voucher_discount' => $discount,
            'version' => (int) $subscription->version,
        ];
    }

    private function changeVoucher(object $subscription, array $target, array $data): array
    {
        $codes = array_column(array_column($target['items'], 'conditions'), 'catalog_module_code');
        $redemption = DB::table('voucher_redemptions as redemption')->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
            ->where('redemption.subscription_id', $subscription->id)->where('voucher.discount_type', 'trial_free')
            ->orderByDesc('redemption.created_at')->orderByDesc('redemption.id')
            ->select('redemption.*', 'voucher.product_id as eligible_product_id', 'voucher.plan_id as eligible_plan_id', 'voucher.module_codes as eligible_module_codes', 'voucher.code')->first();
        if ($redemption && (! $redemption->benefit_ends_at || now()->gte($redemption->benefit_ends_at) || ($redemption->benefit_starts_at && now()->lt($redemption->benefit_starts_at)))) $redemption = null;
        $voucher = ! empty($data['voucher_code']) ? $this->vouchers->findEligible($data['voucher_code'], $subscription->product_id, $subscription->company_id, $codes, $target['plan_code'] ?? null) : null;
        $free = $voucher ? $voucher->discount_type === 'trial_free' : (bool) $redemption;
        $blocked = false;
        $message = null;
        $start = $redemption?->benefit_starts_at;
        $end = $redemption?->benefit_ends_at;
        if ($voucher && $free) {
            abort_unless($voucher->benefit_duration, 422, 'O voucher gratuito precisa ter uma duração definida.');
            $start = now()->toDateTimeString();
            $end = match ($voucher->benefit_duration) {
                'd7' => now()->addDays(7), 'm1' => now()->addMonth(), 'm3' => now()->addMonths(3),
                'm6' => now()->addMonths(6), 'a1' => now()->addYear(), default => null,
            };
            abort_unless($end, 422, 'Duração do voucher gratuito inválida.');
            $end = $end->toDateTimeString();
            $message = 'Novo voucher gratuito: o prazo começa na confirmação desta alteração.';
        } elseif ($redemption && ! $voucher) {
            $saved = json_decode((string) $redemption->snapshot, true) ?: [];
            $scope = $saved['eligibility'] ?? ['product_id' => $redemption->eligible_product_id, 'plan_id' => $redemption->eligible_plan_id, 'module_codes' => json_decode((string) ($redemption->eligible_module_codes ?? ''), true) ?: []];
            $blocked = (! empty($scope['product_id']) && $scope['product_id'] !== $subscription->product_id)
                || (! empty($scope['plan_id']) && $scope['plan_id'] !== ($target['plan_id'] ?? null))
                || (! empty($scope['module_codes']) && ! array_intersect($scope['module_codes'], $codes));
            $message = $blocked ? 'O voucher atual não permite esta composição. Informe outro voucher gratuito elegível para continuar sem cobrança.' : 'Gratuidade mantida até o vencimento original. O upgrade não renova o prazo.';
        } elseif ($voucher) {
            $message = 'O desconto do novo voucher será aplicado somente à cobrança proporcional desta alteração. Conclua o pagamento em até 30 minutos.';
        }
        $snapshot = ['free_benefit' => $free && ! $blocked, 'upgrade_voucher_reservation_id' => null];
        if ($free && ! $blocked) {
            $snapshot = [...$snapshot, 'base_amount' => (float) $target['amount'], 'base_monthly_amount' => (float) $target['monthly_amount'],
                'discount_amount' => (float) $target['amount'], 'amount' => 0.0, 'monthly_amount' => 0.0,
                'current_period_starts_at' => $start, 'current_period_ends_at' => $end,
                'free_benefit_ends_at' => $end, 'free_voucher_code' => $voucher?->code ?? $redemption?->code];
        } else {
            $snapshot = [...$snapshot, 'base_amount' => (float) $target['amount'], 'base_monthly_amount' => (float) $target['monthly_amount'],
                'discount_amount' => 0.0, 'free_benefit_ends_at' => null, 'free_voucher_code' => null];
        }
        return ['snapshot' => $snapshot, 'free' => $free && ! $blocked, 'blocked' => $blocked, 'message' => $message, 'voucher' => $voucher];
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

            if (! empty($after['free_benefit']) && $subscription->provider_subscription_id) {
                app(MercadoPagoClient::class)->updatePreapproval((string) $subscription->provider_subscription_id, ['status' => 'cancelled'], 'free-change-'.$changeId);
            }
            if (! empty($after['upgrade_voucher_reservation_id'])) {
                $this->vouchers->confirmForSubscription($subscription->id, $adminId, $adminId ? 'admin' : 'system', 'http', reservationId: $after['upgrade_voucher_reservation_id']);
                $reservation = DB::table('voucher_redemption_reservations')->where('id', $after['upgrade_voucher_reservation_id'])->first();
                abort_unless($reservation && $reservation->status === 'confirmed', 422, 'A reserva do voucher expirou. Refaça a alteração.');
                if (! empty($after['free_benefit'])) {
                    $redemption = DB::table('voucher_redemptions')->where('subscription_id', $subscription->id)->where('voucher_id', $reservation->voucher_id)->orderByDesc('created_at')->orderByDesc('id')->first();
                    $after['current_period_starts_at'] = $redemption->benefit_starts_at;
                    $after['current_period_ends_at'] = $redemption->benefit_ends_at;
                    $after['free_benefit_ends_at'] = $redemption->benefit_ends_at;
                }
            }
            $this->replaceItems($subscription, $items, $adminId);

            $after['status'] = 'ativa';
            DB::table('subscriptions')->where('id', $subscription->id)->update([
                'status' => 'ativa',
                'billing_cycle' => $after['billing_cycle'] ?? $subscription->billing_cycle,
                'provider_subscription_id' => ! empty($after['free_benefit']) ? null : $subscription->provider_subscription_id,
                'provider_status' => ! empty($after['free_benefit']) ? 'cancelled' : $subscription->provider_status,
                'current_period_starts_at' => ! empty($after['current_period_starts_at']) ? Carbon::parse($after['current_period_starts_at'])->toDateTimeString() : $subscription->current_period_starts_at,
                'current_period_ends_at' => ! empty($after['current_period_ends_at']) ? Carbon::parse($after['current_period_ends_at'])->toDateTimeString() : $subscription->current_period_ends_at,
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
            $after['status'] = $change->type === 'cancelamento' ? 'encerrada' : (! empty($after['free_benefit']) && ! empty($after['free_benefit_ends_at']) && now()->gte($after['free_benefit_ends_at']) ? 'suspensa' : 'ativa');

            DB::table('subscriptions')->where('id', $subscription->id)->update([
                'status' => $after['status'],
                'open_company_product' => $after['status'] === 'encerrada' ? null : $subscription->open_company_product,
                'provider_status' => $after['status'] === 'encerrada' && $subscription->provider_subscription_id ? 'cancelled' : $subscription->provider_status,
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
            $planCodes = $publishedPlan['module_codes'] ?? [];
            if ($customizations->isEmpty()) {
                // Backoffice plan changes can select a plan without sending module
                // customizations. In that case, use the plan's published defaults.
                $customizations = collect($planCodes)->mapWithKeys(fn (string $code): array => [
                    $code => ['module_code' => $code, 'quantity' => 1, 'personalizations' => []],
                ]);
            } else {
                abort_unless(array_diff($planCodes, $customizations->keys()->all()) === [], 422, 'A composição precisa manter todos os módulos incluídos no plano.');
            }
            $requestedItems = $customizations->map(fn (array $selected, string $code): array => [
                'module_code' => $code, 'quantity' => 1, 'personalizations' => $selected['personalizations'] ?? [],
            ])->values()->all();
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
            if (! $plan || ! in_array($code, $publishedPlan['module_codes'] ?? [], true)) abort_unless((bool) ($publishedModules->get($code)['available_standalone'] ?? false), 422, 'Um dos módulos selecionados só pode ser contratado por meio de um plano publicado.');
        }
        $moduleCatalog = $publishedModules;
        foreach ($requestedItems as $requested) {
            $module = $moduleCatalog->get((string) $requested['module_code']);
            abort_if($plan && in_array((string) $requested['module_code'], $publishedPlan['module_codes'] ?? [], true) && (int) ($requested['quantity'] ?? 1) !== 1, 422, 'Módulos incluídos no plano devem ter quantidade unitária.');
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
                    'catalog_module_code' => $moduleCode,
                    'segments' => $module['segments'] ?? [],
                    'context_code' => $module['context_code'] ?? null,
                    'personalizations' => $personalizations,
                    'personalization_delta' => $delta,
                ],
            ];
        })->values()->all();

        if ($plan) {
            $includedCodes = $publishedPlan['module_codes'] ?? [];
            $includedCount = max(1, count($includedCodes));
            $monthlyAmount = (float) $publishedPlan['monthly_amount'];
            foreach ($items as &$item) {
                $isIncluded = in_array($item['conditions']['catalog_module_code'] ?? '', $includedCodes, true);
                $quantity = (int) $item['quantity'];
                if ($isIncluded) {
                    $delta = (float) ($item['conditions']['personalization_delta'] ?? 0) * $quantity;
                    $monthlyAmount += $delta;
                    $monthlyUnit = ((float) $publishedPlan['monthly_amount'] / $includedCount) + (float) ($item['conditions']['personalization_delta'] ?? 0);
                    $item['unit_price'] = $cycle === 'annual' ? $monthlyUnit * 10 : $monthlyUnit;
                } else {
                    $monthlyAmount += (float) $item['unit_price'] * $quantity / ($cycle === 'annual' ? 10 : 1);
                }
            }
            unset($item);
        } else {
            $monthlyAmount = collect($items)->sum(fn (array $item): float => (float) $item['unit_price'] * $item['quantity'] / ($cycle === 'annual' ? 10 : 1));
        }
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
                    'product_catalog_release_version' => $publishedCatalog['release_version'] ?? null,
                    'plan_release_version' => $publishedPlan['release_version'] ?? null,
                    'module_release_versions' => collect($moduleCodes)->mapWithKeys(fn (string $code): array => [$code => $publishedModules->get($code)['release_version'] ?? null])->all(),
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
