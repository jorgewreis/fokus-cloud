<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class VoucherManager
{
    public const RESERVATION_MINUTES = 30;

    public function expireReservations(): int
    {
        return DB::table('voucher_redemption_reservations')
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'released_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function findEligible(string $code, string $productId, string $companyId, array $moduleCodes = [], ?string $planCode = null): object
    {
        $this->expireReservations();

        $voucher = DB::table('vouchers')->where('code', strtoupper(trim($code)))->where('status', 'ativa')->first();
        abort_unless($voucher, 422, 'Voucher inválido ou indisponível.');
        abort_if(($voucher->starts_at && now()->lt($voucher->starts_at)) || ($voucher->ends_at && now()->gt($voucher->ends_at)), 422, 'Voucher fora do período de validade.');
        abort_if($voucher->product_id && $voucher->product_id !== $productId, 422, 'Voucher não é elegível para este produto.');

        if ($voucher->plan_id) {
            $planMatches = DB::table('plans')->where('id', $voucher->plan_id)->where('product_id', $productId)->where('code', $planCode)->exists();
            abort_unless($planMatches, 422, 'Voucher não é elegível para este plano.');
        }

        $eligibleModules = $voucher->module_codes ? json_decode($voucher->module_codes, true) : [];
        abort_if($eligibleModules && ! array_intersect($eligibleModules, $moduleCodes), 422, 'Voucher não é elegível para os módulos selecionados.');

        $total = DB::table('voucher_redemptions')->where('voucher_id', $voucher->id)->count()
            + DB::table('voucher_redemption_reservations')->where('voucher_id', $voucher->id)->where('status', 'pending')->count();
        $companyTotal = DB::table('voucher_redemptions')->where('voucher_id', $voucher->id)->where('company_id', $companyId)->count()
            + DB::table('voucher_redemption_reservations')->where('voucher_id', $voucher->id)->where('company_id', $companyId)->where('status', 'pending')->count();
        abort_if($voucher->redemption_limit && $total >= $voucher->redemption_limit, 422, 'Voucher atingiu o limite de uso.');
        abort_if($voucher->redemption_limit_per_company && $companyTotal >= $voucher->redemption_limit_per_company, 422, 'Voucher já atingiu o limite para esta empresa.');

        return $voucher;
    }

    public function discount(object $voucher, float $amount): float
    {
        return match ($voucher->discount_type) {
            'trial_free', 'commercial_credit' => round(min($amount, (float) ($voucher->discount_type === 'trial_free' ? $amount : $voucher->discount_value)), 2),
            'percentage' => round(min($amount, $amount * ((float) $voucher->discount_value / 100)), 2),
            default => round(min($amount, (float) $voucher->discount_value), 2),
        };
    }

    public function reserve(object $voucher, string $companyId, string $requestKey, array $snapshot, ?string $actorId = null, ?Request $request = null, string $actorType = 'customer'): object
    {
        return DB::transaction(function () use ($voucher, $companyId, $requestKey, $snapshot, $actorId, $request, $actorType): object {
            $existing = DB::table('voucher_redemption_reservations')->where('request_key', $requestKey)->first();
            if ($existing) {
                return $existing;
            }

            $lockedVoucher = DB::table('vouchers')->where('id', $voucher->id)->lockForUpdate()->first();
            abort_unless($lockedVoucher && $lockedVoucher->status === 'ativa', 422, 'Voucher inválido ou indisponível.');

            $total = DB::table('voucher_redemptions')->where('voucher_id', $lockedVoucher->id)->count()
                + DB::table('voucher_redemption_reservations')->where('voucher_id', $lockedVoucher->id)->where('status', 'pending')->where('expires_at', '>', now())->count();
            $companyTotal = DB::table('voucher_redemptions')->where('voucher_id', $lockedVoucher->id)->where('company_id', $companyId)->count()
                + DB::table('voucher_redemption_reservations')->where('voucher_id', $lockedVoucher->id)->where('company_id', $companyId)->where('status', 'pending')->where('expires_at', '>', now())->count();
            abort_if($lockedVoucher->redemption_limit && $total >= $lockedVoucher->redemption_limit, 422, 'Voucher atingiu o limite de uso.');
            abort_if($lockedVoucher->redemption_limit_per_company && $companyTotal >= $lockedVoucher->redemption_limit_per_company, 422, 'Voucher já atingiu o limite para esta empresa.');

            $id = PrefixedUlid::make('VRS');
            DB::table('voucher_redemption_reservations')->insert([
                'id' => $id,
                'voucher_id' => $lockedVoucher->id,
                'company_id' => $companyId,
                'request_key' => Str::limit($requestKey, 128, ''),
                'status' => 'pending',
                'snapshot' => json_encode($snapshot),
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes(self::RESERVATION_MINUTES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reservation = DB::table('voucher_redemption_reservations')->where('id', $id)->first();
            $after = ['status' => 'pending', 'voucher_id' => $voucher->id, 'subscription_id' => $snapshot['subscription_id'] ?? null, 'discount_amount' => $snapshot['discount_amount'] ?? null, 'expires_at' => $reservation->expires_at];
            if ($actorType === 'admin') {
                app(AuditRecorder::class)->platform($actorId, 'billing.voucher_redemption_reserved', 'voucher_redemption_reservation', $id, $companyId,
                    'Voucher reservado para ativação de assinatura.', after: $after, request: $request, actorType: 'admin');
            } else {
                app(AuditRecorder::class)->company($companyId, $actorId, 'voucher_redemption_reservation', $id, 'create', null, $after,
                    reason: 'Voucher reservado durante o checkout.', request: $request, actorType: $actorType);
            }
            return $reservation;
        });
    }

    public function attachSubscription(string $reservationId, string $subscriptionId): void
    {
        DB::table('voucher_redemption_reservations')->where('id', $reservationId)->where('status', 'pending')->update([
            'subscription_id' => $subscriptionId,
            'updated_at' => now(),
        ]);
    }

    public function release(string $reservationId, string $status = 'released', ?string $actorId = null, ?Request $request = null, string $actorType = 'system', string $channel = 'http'): void
    {
        $reservation = DB::table('voucher_redemption_reservations')->where('id', $reservationId)->where('status', 'pending')->first();
        if (! $reservation) return;
        DB::table('voucher_redemption_reservations')->where('id', $reservationId)->where('status', 'pending')->update([
            'status' => $status,
            'released_at' => now(),
            'updated_at' => now(),
        ]);
        $after = ['status' => $status, 'voucher_id' => $reservation->voucher_id, 'subscription_id' => $reservation->subscription_id];
        if ($actorType === 'admin' || $actorType === 'system') {
            app(AuditRecorder::class)->platform($actorId, 'billing.voucher_redemption_released', 'voucher_redemption_reservation', $reservation->id, $reservation->company_id,
                'Reserva de voucher liberada após falha ou cancelamento.', before: ['status' => 'pending'], after: $after, request: $request, actorType: $actorType, channel: $channel);
        }
        app(AuditRecorder::class)->company($reservation->company_id, $actorType === 'customer' ? $actorId : null, 'voucher_redemption_reservation', $reservation->id, 'update',
            ['status' => 'pending'], $after, reason: 'Reserva de voucher liberada após falha ou cancelamento.', request: $request, actorType: $actorType, channel: $channel);
    }

    public function confirmForSubscription(string $subscriptionId, ?string $actorId = null, string $actorType = 'gateway', string $channel = 'webhook', ?string $correlationId = null, ?Request $request = null): void
    {
        DB::transaction(function () use ($subscriptionId, $actorId, $actorType, $channel, $correlationId, $request): void {
            $reservation = DB::table('voucher_redemption_reservations')->where('subscription_id', $subscriptionId)->where('status', 'pending')->lockForUpdate()->first();
            if (! $reservation || ($reservation->expires_at && now()->gt($reservation->expires_at))) {
                if ($reservation) {
                    DB::table('voucher_redemption_reservations')->where('id', $reservation->id)->update(['status' => 'expired', 'released_at' => now(), 'updated_at' => now()]);
                }
                return;
            }

            $snapshot = json_decode($reservation->snapshot, true) ?: [];
            $startsAt = now();
            $endsAt = match ($snapshot['benefit_duration'] ?? null) {
                'd7' => $startsAt->copy()->addDays(7),
                'm1' => $startsAt->copy()->addMonth(),
                'm3' => $startsAt->copy()->addMonths(3),
                'm6' => $startsAt->copy()->addMonths(6),
                'a1' => $startsAt->copy()->addYear(),
                default => null,
            };
            $snapshot['benefit_starts_at'] = $startsAt->toISOString();
            $snapshot['benefit_ends_at'] = $endsAt?->toISOString();
            $redemptionId = PrefixedUlid::make('VRD');
            DB::table('voucher_redemptions')->insert([
                'id' => $redemptionId,
                'voucher_id' => $reservation->voucher_id,
                'company_id' => $reservation->company_id,
                'subscription_id' => $subscriptionId,
                'discount_amount' => (float) ($snapshot['discount_amount'] ?? 0),
                'benefit_starts_at' => $startsAt,
                'benefit_ends_at' => $endsAt,
                'snapshot' => json_encode([...$snapshot, 'redemption_id' => $redemptionId]),
                'created_at' => now(),
            ]);
            DB::table('voucher_redemption_reservations')->where('id', $reservation->id)->update(['status' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now()]);
            $after = ['status' => 'confirmed', 'voucher_id' => $reservation->voucher_id, 'subscription_id' => $subscriptionId, 'discount_amount' => (float) ($snapshot['discount_amount'] ?? 0), 'benefit_ends_at' => $endsAt?->toISOString()];
            if ($actorType === 'admin' || $actorType === 'gateway' || $actorType === 'system') {
                app(AuditRecorder::class)->platform($actorId, 'billing.voucher_redemption_confirmed', 'voucher_redemption', $redemptionId, $reservation->company_id,
                    'Resgate de voucher confirmado na assinatura.', metadata: ['reservation_id' => $reservation->id], after: $after, request: $request, actorType: $actorType,
                    channel: $channel, originContext: $channel === 'webhook' ? '/api/webhooks/mercado-pago' : null, correlationId: $correlationId);
            }
            app(AuditRecorder::class)->company($reservation->company_id, $actorType === 'customer' ? $actorId : null, 'voucher_redemption', $redemptionId, 'create', null, $after,
                reason: 'Resgate de voucher confirmado na assinatura.', request: $request, actorType: $actorType, channel: $channel,
                originContext: $channel === 'webhook' ? '/api/webhooks/mercado-pago' : null, correlationId: $correlationId);
        });
    }

    public function releaseForSubscription(string $subscriptionId, ?string $correlationId = null): void
    {
        DB::transaction(function () use ($subscriptionId, $correlationId): void {
            $reservations = DB::table('voucher_redemption_reservations')->where('subscription_id', $subscriptionId)->where('status', 'pending')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                DB::table('voucher_redemption_reservations')->where('id', $reservation->id)->where('status', 'pending')->update([
                    'status' => 'released', 'released_at' => now(), 'updated_at' => now(),
                ]);
                $after = ['status' => 'released', 'voucher_id' => $reservation->voucher_id, 'subscription_id' => $subscriptionId];
                app(AuditRecorder::class)->company($reservation->company_id, null, 'voucher_redemption_reservation', $reservation->id, 'update', ['status' => 'pending'], $after,
                    reason: 'Reserva liberada após falha, recusa ou cancelamento da cobrança.', actorType: 'gateway', channel: 'webhook', originContext: '/api/webhooks/mercado-pago', correlationId: $correlationId);
                app(AuditRecorder::class)->platform(null, 'billing.voucher_redemption_released', 'voucher_redemption_reservation', $reservation->id, $reservation->company_id,
                    'Reserva liberada após falha, recusa ou cancelamento da cobrança.', before: ['status' => 'pending'], after: $after, actorType: 'gateway', channel: 'webhook',
                    originContext: '/api/webhooks/mercado-pago', correlationId: $correlationId);
            }
        });
    }
}
