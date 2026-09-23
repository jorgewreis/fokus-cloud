<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrefixedUlid;
use App\Services\CatalogManager;
use App\Services\CatalogPricing;
use App\Services\VoucherManager;
use App\Services\SubscriptionChangeManager;
use App\Services\MercadoPagoClient;
use App\Services\BillingWebhookProcessor;
use App\Services\PlatformAudit;
use App\Services\PendingSubscriptionVoucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function catalog(Request $request, string $productCode, CatalogManager $catalog)
    {
        return $this->publicCatalog($request, $productCode, $catalog);
    }

    public function publicCatalog(Request $request, string $productCode, CatalogManager $catalog)
    {
        return response()->json($catalog->publicCatalog($productCode));
    }

    public function index(Request $request)
    {
        $companyId = $request->attributes->get('active_company_id');
        $subscriptions = DB::table('subscriptions as subscription')->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $companyId)
            ->select('subscription.id', 'subscription.status', 'subscription.created_at', 'subscription.billing_cycle', 'subscription.current_period_ends_at', 'subscription.cancel_at', 'product.code as product_code', 'product.name as product_name')
            ->orderByDesc('subscription.created_at')->get();
        foreach ($subscriptions as $subscription) {
            $subscription->items = DB::table('subscription_items')->where('company_id', $companyId)->where('subscription_id', $subscription->id)
                ->select('name_snapshot as name', 'quantity', 'unit_price_snapshot as unit_price', 'conditions_snapshot')->get();
            $subscription->items->transform(function (object $item): object {
                $item->conditions = json_decode((string) $item->conditions_snapshot, true) ?: [];
                unset($item->conditions_snapshot);
                return $item;
            });
            $subscription->payment = DB::table('payments')->where('company_id', $companyId)->where('subscription_id', $subscription->id)
                ->latest('created_at')->select('status', 'amount', 'currency', 'created_at')->first();
        }
        return response()->json($subscriptions);
    }

    public function checkout(Request $request, CatalogManager $catalog, VoucherManager $vouchers, SubscriptionChangeManager $subscriptionChanges, MercadoPagoClient $mercadoPago)
    {
        abort_unless($request->user()->email_verified_at, 403, 'Confirme o e-mail antes de assinar.');
        $data = $request->validate([
            'product_code' => ['required', Rule::in(['law', 'lead'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.module_code' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.personalizations' => ['nullable', 'array'],
            'items.*.personalizations.*.type_code' => ['required', 'string', 'max:64'],
            'items.*.personalizations.*.tier_value' => ['required', 'integer', 'min:1'],
            'cycle' => ['required', Rule::in(['monthly', 'annual'])],
            'selection_mode' => ['required', Rule::in(['modules', 'plan'])],
            'plan_code' => ['nullable', 'string', 'max:64'],
            'voucher_code' => ['nullable', 'string', 'max:64'],
        ]);
        $companyId = $request->attributes->get('active_company_id');
        return $this->executeCheckout($request, $data, $companyId, $request->user()->id, $request->user()->email, $catalog, $vouchers, $subscriptionChanges, $mercadoPago);
    }

    public function assistedCheckoutOptions(Request $request, CatalogManager $catalog)
    {
        $query = trim((string) $request->query('q', ''));
        $companies = DB::table('companies as company')
            ->join('company_memberships as membership', 'membership.company_id', '=', 'company.id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->join('users as user', 'user.id', '=', 'membership.user_id')
            ->whereNull('company.deleted_at')->whereNull('membership.deleted_at')
            ->where('company.status', 'ativa')->where('membership.status', 'ativo')
            ->where('role.code', 'admin')->where('user.status', 'ativa')
            ->whereNotNull('user.email_verified_at')
            ->when($query, fn ($builder) => $builder->where('company.legal_name', 'like', '%'.$query.'%'))
            ->select('company.id', 'company.legal_name')->distinct()->orderBy('company.legal_name')->limit(20)->get();
        $products = [];
        $catalogUnavailable = false;
        $productCodes = DB::table('products')->where('active', true)->where('status', 'ativo')->orderBy('name')->pluck('code');
        foreach ($productCodes as $code) {
            try {
                $published = $catalog->publicCatalog($code, allowPendingPublication: true);
                $plans = collect($published['plans'] ?? [])->filter(fn (array $plan): bool => ! empty($plan['module_codes']))->map(fn (array $plan): array => [
                    'code' => $plan['code'], 'name' => $plan['name'], 'monthly_amount' => $plan['monthly_amount'], 'annual_amount' => $plan['annual_amount'],
                ])->values()->all();
                if ($plans) $products[] = ['code' => $code, 'name' => $published['name'] ?? $code, 'plans' => $plans];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                // Products without a current published catalog cannot be offered for checkout.
                $catalogUnavailable = true;
            }
        }
        $catalogMessage = $products
            ? null
            : ($catalogUnavailable
                ? 'Nenhuma oferta está disponível porque falta uma publicação vigente do catálogo. Publique o catálogo do produto para liberar planos no checkout.'
                : 'Nenhuma oferta publicada está disponível para contratação no momento.');
        return response()->json([
            'companies' => $companies,
            'products' => $products,
            'catalog_message' => $catalogMessage,
        ]);
    }

    public function assistedCheckout(Request $request, CatalogManager $catalog, VoucherManager $vouchers, SubscriptionChangeManager $subscriptionChanges, MercadoPagoClient $mercadoPago, PlatformAudit $audit)
    {
        if (! $request->header('Idempotency-Key')) $request->headers->set('Idempotency-Key', (string) Str::uuid());
        $input = $request->validate([
            'company_id' => ['required', 'string', 'max:64'],
            'product_code' => ['required', 'string', 'max:64', Rule::exists('products', 'code')->where('active', true)->where('status', 'ativo')],
            'plan_code' => ['required', 'string', 'max:64'],
            'cycle' => ['required', Rule::in(['monthly', 'annual'])],
        ]);
        $company = DB::table('companies')->where('id', $input['company_id'])->whereNull('deleted_at')->where('status', 'ativa')->first();
        abort_unless($company, 422, 'Empresa indisponível para contratação.');
        $customer = DB::table('company_memberships as membership')->join('roles as role', 'role.id', '=', 'membership.role_id')->join('users as user', 'user.id', '=', 'membership.user_id')
            ->where('membership.company_id', $company->id)->whereNull('membership.deleted_at')->where('membership.status', 'ativo')
            ->where('role.code', 'admin')->where('user.status', 'ativa')->whereNotNull('user.email_verified_at')
            ->select('user.id', 'user.email')->orderBy('membership.created_at')->first();
        abort_unless($customer, 422, 'A empresa precisa de um administrador ativo com e-mail confirmado.');
        $published = $catalog->publicCatalog($input['product_code'], allowPendingPublication: true);
        $plan = collect($published['plans'] ?? [])->firstWhere('code', $input['plan_code']);
        abort_unless($plan && ! empty($plan['module_codes']), 422, 'Plano publicado indisponível.');
        $data = [
            'product_code' => $input['product_code'], 'selection_mode' => 'plan', 'plan_code' => $input['plan_code'], 'cycle' => $input['cycle'],
            'items' => collect($plan['module_codes'])->map(fn (string $code): array => ['module_code' => $code, 'quantity' => 1])->all(),
        ];
        $response = $this->executeCheckout($request, $data, $company->id, $customer->id, $customer->email, $catalog, $vouchers, $subscriptionChanges, $mercadoPago, allowPendingPublication: true);
        if ($response->getStatusCode() === 201) {
            $payload = $response->getData(true);
            $audit->record($request->user()->id, 'backoffice.subscription_checkout_created', 'subscription', $payload['subscription_id'], $company->id, after: ['product_code' => $data['product_code'], 'plan_code' => $data['plan_code'], 'cycle' => $data['cycle'], 'amount' => $payload['amount']], request: $request);
        }
        return $response;
    }

    public function activateWithFreeVoucher(Request $request, string $subscription, PendingSubscriptionVoucher $pendingVoucher, PlatformAudit $audit)
    {
        $data = $request->validate(['voucher_code' => ['required', 'string', 'max:64']]);
        $result = $pendingVoucher->activate($subscription, $data['voucher_code']);
        $companyId = DB::table('subscriptions')->where('id', $subscription)->value('company_id');
        $audit->record($request->user()->id, 'backoffice.subscription_free_voucher_activated', 'subscription', $subscription, $companyId,
            metadata: ['voucher_redemption_id' => $result['voucher_redemption_id'], 'benefit_ends_at' => $result['benefit_ends_at']], request: $request);
        return response()->json(['message' => 'Assinatura ativada pelo voucher gratuito.', ...$result]);
    }

    private function executeCheckout(Request $request, array $data, string $companyId, string $customerUserId, string $customerEmail, CatalogManager $catalog, VoucherManager $vouchers, SubscriptionChangeManager $subscriptionChanges, MercadoPagoClient $mercadoPago, bool $allowPendingPublication = false)
    {
        $product = DB::table('products')->where('code', $data['product_code'])->where('active', true)->first();
        abort_unless($product, 404, 'Produto não encontrado.');
        $quoted = $this->quote($product, $data, $catalog, $allowPendingPublication);
        $payerEmail = $mercadoPago->payerEmail((string) $customerEmail);
        $requestKey = (string) ($request->header('Idempotency-Key') ?: hash('sha256', implode('|', [
            $customerUserId, $companyId, json_encode($data), $payerEmail,
        ])));
        $previousAttempt = DB::table('billing_checkout_attempts')->where('company_id', $companyId)->where('request_key', $requestKey)->first();
        if (! $request->header('Idempotency-Key') && $previousAttempt?->status === 'completed') {
            $previousSubscription = DB::table('subscriptions')->where('id', $previousAttempt->subscription_id)->first();
            if ($previousSubscription && ($previousSubscription->status === 'encerrada' || $this->isExpiredFreeTrial($previousSubscription))) {
                $requestKey = (string) Str::uuid();
                $previousAttempt = null;
            }
        }
        if ($previousAttempt?->status === 'completed') {
            return response()->json(json_decode((string) $previousAttempt->response_snapshot_sanitized, true) ?: [], 201);
        }
        if ($previousAttempt?->status === 'started') {
            return response()->json(['message' => 'Este checkout já está em processamento.'], 409);
        }
        if ($previousAttempt?->status === 'failed') {
            return response()->json(['message' => 'Esta tentativa de checkout já falhou. Gere uma nova chave de idempotência para tentar novamente.'], 502);
        }
        $openSubscription = DB::table('subscriptions')->where('company_id', $companyId)->where('product_id', $product->id)->where('status', '!=', 'encerrada')->first();
        abort_if($openSubscription && ! $this->isExpiredFreeTrial($openSubscription), 409, 'Já existe uma assinatura não encerrada para este produto.');
        $attemptId = $previousAttempt?->id ?: PrefixedUlid::make('BCA');
        if (! $previousAttempt) {
            DB::table('billing_checkout_attempts')->insert([
                'id' => $attemptId,
                'company_id' => $companyId,
                'user_id' => $customerUserId,
                'request_key' => $requestKey,
                'status' => 'started',
                'request_snapshot_sanitized' => json_encode(['product_code' => $data['product_code'], 'cycle' => $data['cycle'], 'selection_mode' => $data['selection_mode'], 'amount' => $quoted['amount']]),
                'expires_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $voucher = ! empty($data['voucher_code'])
            ? $vouchers->findEligible($data['voucher_code'], $product->id, $companyId, array_column($data['items'], 'module_code'), $data['plan_code'] ?? null)
            : null;
        if ($voucher) {
            $quoted['discount_amount'] = $vouchers->discount($voucher, $quoted['amount']);
            $quoted['amount'] = round($quoted['amount'] - $quoted['discount_amount'], 2);
        }
        $subscriptionId = PrefixedUlid::make('ASS');
        $paymentId = PrefixedUlid::make('PAG');
        $reservation = null;

        if ($voucher) {
            $plan = $data['plan_code'] ? DB::table('plans')->where('product_id', $product->id)->where('code', $data['plan_code'])->first() : null;
            $reservation = $vouchers->reserve($voucher, $companyId, $requestKey, [
                'voucher_id' => $voucher->id,
                'code' => $voucher->code,
                'name' => $voucher->name,
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'plan_id' => $plan?->id,
                'plan_code' => $plan?->code,
                'plan_name' => $plan?->name,
                'discount_type' => $voucher->discount_type,
                'discount_value' => (float) $voucher->discount_value,
                'base_amount' => (float) ($voucher->base_amount ?? $quoted['amount']),
                'discount_amount' => $quoted['discount_amount'],
                'final_amount' => $quoted['amount'],
                'billing_cycle' => $data['cycle'],
                'benefit_duration' => $voucher->benefit_duration,
                'benefit_starts_at' => null,
                'benefit_ends_at' => null,
                'company_id' => $companyId,
                'subscription_id' => $subscriptionId,
                'selection_mode' => $data['selection_mode'],
                'module_codes' => array_column($data['items'], 'module_code'),
            ]);
        }

        try {
            $response = $mercadoPago->createPreapproval([
                'external_reference' => $paymentId,
                'reason' => "Assinatura {$product->name}",
                'payer_email' => $payerEmail,
                'auto_recurring' => ['frequency' => $data['cycle'] === 'annual' ? 12 : 1, 'frequency_type' => 'months', 'transaction_amount' => $quoted['amount'], 'currency_id' => 'BRL'],
                'status' => 'pending',
                'back_url' => rtrim(config('app.url'), '/').'/portal/assinaturas',
                'notification_url' => rtrim(config('app.url'), '/').'/api/webhooks/mercado-pago',
            ], $requestKey);
        } catch (\Throwable $exception) {
            if ($reservation) $vouchers->release($reservation->id);
            DB::table('billing_checkout_attempts')->where('id', $attemptId)->update(['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 1000), 'updated_at' => now()]);
            return response()->json(['message' => 'Não foi possível iniciar o checkout. Nenhuma assinatura foi criada; tente novamente.'], 502);
        }

        try {
            DB::transaction(function () use ($companyId, $product, $quoted, $customerUserId, $subscriptionId, $paymentId, $response, $data) {
            $existing = DB::table('subscriptions')->where('company_id', $companyId)->where('product_id', $product->id)
                ->where('status', '!=', 'encerrada')->lockForUpdate()->first();
            abort_if($existing && ! $this->isExpiredFreeTrial($existing), 409, 'Já existe uma assinatura não encerrada para este produto.');
            if ($existing) {
                DB::table('subscriptions')->where('id', $existing->id)->update(['status' => 'encerrada', 'open_company_product' => null, 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            }
            DB::table('subscriptions')->insert([
                'id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'aguardando_pagamento',
                'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => $data['cycle'],
                'current_period_starts_at' => now(), 'current_period_ends_at' => $data['cycle'] === 'annual' ? now()->addYear() : now()->addMonth(),
                'provider_subscription_id' => $response['id'] ?? null,
                'created_by' => $customerUserId, 'updated_by' => $customerUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($quoted['items'] as $item) {
                DB::table('subscription_items')->insert([
                    'id' => PrefixedUlid::make('ITM'), 'company_id' => $companyId, 'subscription_id' => $subscriptionId,
                    'module_id' => $item['module']->id, 'name_snapshot' => $item['module']->name, 'quantity' => $item['quantity'],
                    'unit_price_snapshot' => $item['unit_price'], 'conditions_snapshot' => json_encode($item['conditions']),
                    'created_by' => $customerUserId, 'updated_by' => $customerUserId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('payments')->insert([
                'id' => $paymentId, 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'amount' => $quoted['amount'],
                'currency' => 'BRL', 'status' => 'aguardando_pagamento', 'provider_subscription_id' => $response['id'] ?? null,
                'provider_payload_sanitized' => json_encode(['preapproval_id' => $response['id'] ?? null]),
                'created_by' => $customerUserId, 'updated_by' => $customerUserId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            });
            if ($reservation) $vouchers->attachSubscription($reservation->id, $subscriptionId);
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first();
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'commercial_snapshot' => json_encode($subscriptionChanges->snapshot($subscription)),
                'updated_at' => now(),
            ]);
            DB::table('billing_checkout_attempts')->where('id', $attemptId)->update([
                'status' => 'completed', 'payment_id' => $paymentId, 'subscription_id' => $subscriptionId,
                'provider_subscription_id' => $response['id'] ?? null,
                'response_snapshot_sanitized' => json_encode(['checkout_url' => $response['init_point'] ?? null, 'subscription_id' => $subscriptionId, 'amount' => $quoted['amount']]),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            if ($reservation) $vouchers->release($reservation->id);
            if (! empty($response['id'])) {
                try { $mercadoPago->updatePreapproval((string) $response['id'], ['status' => 'cancelled'], 'compensate-'.$attemptId); } catch (\Throwable) { /* retry/reconciliation will handle the external orphan */ }
            }
            DB::table('billing_checkout_attempts')->where('id', $attemptId)->update(['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 1000), 'updated_at' => now()]);
            throw $exception;
        }

        return response()->json(['checkout_url' => $response['init_point'] ?? null, 'subscription_id' => $subscriptionId, 'amount' => $quoted['amount']], 201);
    }

    private function isExpiredFreeTrial(object $subscription): bool
    {
        return $subscription->status === 'suspensa' && ! $subscription->provider_subscription_id
            && DB::table('voucher_redemptions as redemption')->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
                ->where('redemption.subscription_id', $subscription->id)->where('voucher.discount_type', 'trial_free')
                ->where('redemption.benefit_ends_at', '<=', now())->exists();
    }

    public function change(Request $request, string $subscription, SubscriptionChangeManager $subscriptionChanges)
    {
        $membership = $request->attributes->get('active_membership');
        abort_unless($membership->role === 'admin', 403, 'Apenas o administrador pode alterar a assinatura.');
        $data = $request->validate([
            'type' => ['required', Rule::in(['upgrade', 'downgrade', 'cancelamento', 'cancelamento_imediato'])],
            'items' => ['nullable', 'array'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $companyId = $request->attributes->get('active_company_id');
        $current = DB::table('subscriptions')->where('id', $subscription)->where('company_id', $companyId)->first();
        abort_unless($current && $current->status !== 'encerrada', 404, 'Assinatura não encontrada ou já encerrada.');
        $latestPayment = DB::table('payments')->where('company_id', $companyId)->where('subscription_id', $current->id)->latest('created_at')->first();
        $cancelImmediately = $data['type'] === 'cancelamento_imediato'
            || ($data['type'] === 'cancelamento' && $latestPayment?->status !== 'aprovado');

        if (in_array($data['type'], ['upgrade', 'downgrade'], true) || ($data['type'] === 'cancelamento' && ! $cancelImmediately)) {
            abort_unless(in_array($current->status, ['ativa', 'suspensa'], true), 422, 'Esta alteração só pode ser feita em assinaturas ativas ou suspensas.');
        }

        if ($cancelImmediately) {
            // No cancelamento imediato, não é necessário consultar o catálogo:
            // o snapshot persistido já contém o estado comercial da assinatura.
            $before = json_decode((string) ($current->commercial_snapshot ?? ''), true);
            $before = is_array($before) ? $before : [
                'subscription_id' => $current->id,
                'company_id' => $current->company_id,
                'product_id' => $current->product_id,
                'items' => [],
            ];
            $before['status'] = $current->status;
            $before['cancel_at'] = $current->cancel_at;
            $effectiveAt = now();
            $after = [...$before, 'status' => 'encerrada', 'cancel_at' => $effectiveAt->toISOString()];
            try {
                try {
                    DB::table('subscriptions')->where('id', $subscription)->update([
                        'status' => 'encerrada',
                        'cancel_at' => $effectiveAt,
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $legacyStatusException) {
                    // Compatibilidade com instalações que ainda possuem o
                    // estado legado "cancelada" no ENUM de subscriptions.
                    DB::table('subscriptions')->where('id', $subscription)->update([
                        'status' => 'cancelada',
                        'cancel_at' => $effectiveAt,
                        'updated_at' => now(),
                    ]);
                    $after['status'] = 'cancelada';
                }
            } catch (\Throwable $exception) {
                Log::error('Falha ao cancelar assinatura localmente.', [
                    'subscription_id' => $subscription,
                    'company_id' => $companyId,
                    'exception' => $exception,
                ]);
                throw $exception;
            }

            try {
                DB::table('subscriptions')->where('id', $subscription)->update([
                    'open_company_product' => null,
                    'commercial_snapshot' => json_encode($after, JSON_INVALID_UTF8_SUBSTITUTE),
                    'updated_at' => now(),
                ]);
                DB::table('subscription_changes')->insert([
                    'id' => PrefixedUlid::make('SCH'),
                    'company_id' => $current->company_id,
                    'subscription_id' => $subscription,
                    'type' => 'cancelamento',
                    'status' => 'aplicada',
                    'effective_at' => $effectiveAt,
                    'proration_amount' => 0,
                    'items_snapshot' => json_encode($after['items'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE),
                    'before_snapshot' => json_encode($before, JSON_INVALID_UTF8_SUBSTITUTE),
                    'after_snapshot' => json_encode($after, JSON_INVALID_UTF8_SUBSTITUTE),
                    'reason' => $data['reason'] ?? 'Cancelamento imediato solicitado pelo administrador da empresa.',
                    'requested_by_user_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Cancelamento local concluído, mas dados auxiliares não foram persistidos.', [
                    'subscription_id' => $subscription,
                    'company_id' => $companyId,
                    'exception' => $exception,
                ]);
                throw $exception;
            }

            $providerCancellationPending = false;
            if ($current->provider_subscription_id) {
                try {
                    app(MercadoPagoClient::class)->updatePreapproval(
                        (string) $current->provider_subscription_id,
                        ['status' => 'cancelled'],
                        'user-immediate-cancel-'.$current->id,
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                    $providerCancellationPending = true;
                }
            }

            return response()->json([
                'message' => $data['type'] === 'cancelamento_imediato' ? 'Assinatura cancelada imediatamente.' : 'Assinatura não paga cancelada imediatamente.',
                'effective_at' => $effectiveAt,
                'provider_sync_pending' => $providerCancellationPending,
            ]);
        }

        $effectiveAt = $data['type'] === 'upgrade' ? now() : ($current->current_period_ends_at ?: now());
        $status = $data['type'] === 'upgrade' ? 'aguardando_pagamento' : 'agendada';
        DB::table('subscription_changes')->insert([
            'id' => PrefixedUlid::make('SCH'), 'company_id' => $companyId, 'subscription_id' => $current->id,
            'type' => $data['type'], 'status' => $status, 'effective_at' => $effectiveAt,
            'items_snapshot' => isset($data['items']) ? json_encode($data['items']) : null, 'reason' => $data['reason'] ?? null,
            'requested_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($data['type'] === 'cancelamento') {
            DB::table('subscriptions')->where('id', $current->id)->update(['cancel_at' => $effectiveAt, 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            return response()->json(['message' => 'Assinatura cancelada ao final do período vigente.', 'effective_at' => $effectiveAt]);
        }
        return response()->json(['message' => $data['type'] === 'upgrade' ? 'Upgrade criado e aguardando a cobrança proporcional.' : 'Alteração programada para o fim do ciclo.', 'effective_at' => $effectiveAt]);
    }

    public function destroy(Request $request, string $subscription)
    {
        $membership = $request->attributes->get('active_membership');
        abort_unless($membership->role === 'admin', 403, 'Apenas o administrador pode excluir a assinatura.');
        $companyId = $request->attributes->get('active_company_id');
        $current = DB::table('subscriptions')->where('id', $subscription)->where('company_id', $companyId)->first();
        abort_unless($current && in_array($current->status, ['encerrada', 'cancelada'], true), 422, 'Somente assinaturas canceladas ou encerradas podem ser excluídas.');

        DB::transaction(function () use ($subscription, $current): void {
            $paymentIds = DB::table('payments')->where('subscription_id', $subscription)->pluck('id')->all();
            $changeIds = DB::table('subscription_changes')->where('subscription_id', $subscription)->pluck('id')->all();
            $refundIds = DB::table('refund_requests')->where('subscription_id', $subscription)->pluck('id')->all();
            $historyEntityIds = array_values(array_unique(array_merge([$subscription], $paymentIds, $changeIds, $refundIds)));

            foreach (['audit_events', 'platform_audit_events'] as $auditTable) {
                if (DB::getSchemaBuilder()->hasTable($auditTable)) {
                    DB::table($auditTable)->whereIn('entity_id', $historyEntityIds)->delete();
                }
            }
            if ($current->provider_subscription_id && DB::getSchemaBuilder()->hasTable('billing_provider_events')) {
                DB::table('billing_provider_events')->where('resource_id', $current->provider_subscription_id)->delete();
            }
            foreach (['voucher_redemption_reservations', 'voucher_redemptions', 'subscription_changes', 'refund_requests', 'payment_reconciliation_alerts', 'billing_checkout_attempts', 'subscription_items', 'payments'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) DB::table($table)->where('subscription_id', $subscription)->delete();
            }
            DB::table('subscriptions')->where('id', $subscription)->delete();
        });

        return response()->json(['message' => 'Assinatura excluída definitivamente.']);
    }

    public function webhook(Request $request, BillingWebhookProcessor $processor)
    {
        $this->assertWebhookSignature($request);
        $processor->process($request);
        return response()->json(['received' => true]);
    }

    private function quote(object $product, array $data, CatalogManager $catalog, bool $allowPendingPublication = false): array
    {
        $codes = array_column($data['items'], 'module_code');
        abort_if(count($codes) !== count(array_unique($codes)), 422, 'Um módulo só pode ser informado uma vez.');
        $publishedModules = $catalog->publishedModuleMap($product->code, $allowPendingPublication);
        $publishedPlans = $catalog->publishedPlanMap($product->code, $allowPendingPublication);
        abort_unless($publishedModules->isNotEmpty(), 422, 'Catálogo publicado indisponível para este produto.');

        $publishedPlan = null;
        if ($data['selection_mode'] === 'plan') {
            $publishedPlan = $publishedPlans[$data['plan_code'] ?? ''] ?? null;
            abort_unless($publishedPlan, 422, 'Plano não encontrado ou indisponível.');
            abort_unless($this->sameModules($codes, $publishedPlan['module_codes'] ?? []), 422, 'Os módulos não correspondem ao plano informado.');
        }
        foreach ($codes as $code) {
            abort_unless($publishedModules->has($code), 422, 'Módulo inválido para este produto.');
        }

        $modules = DB::table('modules')->where('product_id', $product->id)->whereIn('code', $codes)->get()->keyBy('code');
        abort_unless($modules->count() === count($codes), 422, 'Módulo inválido para este produto.');
        $monthly = 0.0;
        $items = [];
        foreach ($data['items'] as $requested) {
            $module = $modules[$requested['module_code']];
            $publishedModule = $publishedModules[$requested['module_code']];
            [$customizationAmount, $selections, $personalizationDelta] = $this->quotePersonalizations($publishedModule, $requested['personalizations'] ?? [], $publishedPlan['personalization_defaults'] ?? []);
            $unit = (float) $publishedModule['monthly_amount'] + $customizationAmount;
            $monthly += $unit * $requested['quantity'];
            $items[] = ['module' => $module, 'quantity' => $requested['quantity'], 'unit_price' => $data['cycle'] === 'annual' ? $unit * 10 : $unit, 'conditions' => ['cycle' => $data['cycle'], 'personalizations' => $selections, 'personalization_delta' => $personalizationDelta, 'selection_mode' => $data['selection_mode'], 'plan_code' => $data['plan_code'] ?? null, 'module_code' => $module->module_code, 'context_code' => $module->context_code]];
        }
        if ($data['selection_mode'] === 'plan') {
            $planMonthly = (float) $publishedPlan['monthly_amount'];
            $monthly = $planMonthly + collect($items)->sum(fn (array $item): float => (float) ($item['conditions']['personalization_delta'] ?? 0));
            foreach ($items as &$item) {
                $item['unit_price'] *= 0.9;
            }
        }
        $amount = $data['cycle'] === 'annual' ? CatalogPricing::annualFromMonthly($monthly) : round($monthly, 2);
        return ['items' => $items, 'amount' => $amount];
    }

    private function quotePersonalizations(array $module, array $requested, array $planDefaults): array
    {
        $requestedByType = collect($requested)->keyBy('type_code');
        $defaultsById = collect($planDefaults)->keyBy('personalization_id');
        $total = 0.0;
        $delta = 0.0;
        $selections = [];
        foreach ($module['personalizations'] ?? [] as $personalization) {
            $activeTiers = collect($personalization['tiers'] ?? [])->where('active', true)->sortBy('value')->values();
            if ($activeTiers->isEmpty() || ! $personalization['active']) continue;
            $planDefault = $defaultsById->get($personalization['id']);
            $defaultTier = $planDefault ? $activeTiers->firstWhere('id', $planDefault['tier_id']) : ($personalization['required'] ? $activeTiers->first() : null);
            $requestedTier = $requestedByType->get($personalization['type_code']);
            if ($personalization['required']) abort_unless($defaultTier, 422, 'Personalização obrigatória sem faixa padrão disponível.');
            if ($requestedTier) {
                $tier = $activeTiers->firstWhere('value', (int) $requestedTier['tier_value']);
                abort_unless($tier, 422, 'Faixa de personalização inválida.');
                if ($defaultTier) abort_if((int) $tier['value'] < (int) $defaultTier['value'], 422, 'A faixa selecionada não pode ser inferior à faixa padrão do plano.');
                $total += (float) $tier['additional_monthly_amount'];
                if ($defaultTier) $delta += (float) $tier['additional_monthly_amount'] - (float) $defaultTier['additional_monthly_amount'];
                $selections[] = ['personalization_id' => $personalization['id'], 'type_code' => $personalization['type_code'], 'tier_id' => $tier['id'], 'value' => (int) $tier['value'], 'additional_monthly_amount' => (float) $tier['additional_monthly_amount']];
            } elseif ($personalization['required']) {
                $total += (float) $defaultTier['additional_monthly_amount'];
                $selections[] = ['personalization_id' => $personalization['id'], 'type_code' => $personalization['type_code'], 'tier_id' => $defaultTier['id'], 'value' => (int) $defaultTier['value'], 'additional_monthly_amount' => (float) $defaultTier['additional_monthly_amount']];
            }
        }
        foreach ($requestedByType as $typeCode => $selection) abort_unless(collect($module['personalizations'] ?? [])->pluck('type_code')->contains($typeCode), 422, 'Personalização inválida para este módulo.');
        return [$total, $selections, $delta];
    }

    private function voucherFor(string $code, string $productId, string $companyId, array $moduleCodes): ?object
    {
        $voucher = DB::table('vouchers')->where('code', strtoupper($code))->where('status', 'ativa')->first();
        abort_unless($voucher, 422, 'Voucher inválido ou indisponível.');
        abort_if(($voucher->starts_at && now()->lt($voucher->starts_at)) || ($voucher->ends_at && now()->gt($voucher->ends_at)), 422, 'Voucher fora do período de validade.');
        abort_if($voucher->product_id && $voucher->product_id !== $productId, 422, 'Voucher não é elegível para este produto.');
        $eligibleModules = $voucher->module_codes ? json_decode($voucher->module_codes, true) : [];
        abort_if($eligibleModules && ! array_intersect($eligibleModules, $moduleCodes), 422, 'Voucher não é elegível para os módulos selecionados.');
        $total = DB::table('voucher_redemptions')->where('voucher_id', $voucher->id)->count();
        $companyTotal = DB::table('voucher_redemptions')->where('voucher_id', $voucher->id)->where('company_id', $companyId)->count();
        abort_if($voucher->redemption_limit && $total >= $voucher->redemption_limit, 422, 'Voucher atingiu o limite de uso.');
        abort_if($voucher->redemption_limit_per_company && $companyTotal >= $voucher->redemption_limit_per_company, 422, 'Voucher já atingiu o limite para esta empresa.');
        return $voucher;
    }

    private function sameModules(array $actual, array $expected): bool
    {
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private function jsonArray(?string $value): array
    {
        $decoded = $value ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function assertWebhookSignature(Request $request): void
    {
        $secret = config('services.mercado_pago.webhook_secret');
        abort_unless($secret, 503, 'Assinatura do webhook não configurada.');
        $signature = (string) $request->header('x-signature');
        $requestId = (string) $request->header('x-request-id');
        preg_match('/(?:^|,)\s*ts=([^,]+)/', $signature, $timestamp);
        preg_match('/(?:^|,)\s*v1=([^,]+)/', $signature, $digest);
        $dataId = (string) ($request->query('data_id') ?: $request->query('data.id'));
        if ($dataId === '') {
            $dataId = (string) data_get($request->all(), 'data.id');
        }
        abort_unless($requestId !== '', 401, 'Assinatura de webhook inválida.');
        $ts = (int) ($timestamp[1] ?? 0);
        abort_unless($ts > 0 && abs(now()->timestamp - $ts) <= (int) config('services.mercado_pago.webhook_tolerance', 300), 401, 'Assinatura de webhook expirada.');
        abort_unless($dataId && ! empty($timestamp[1]) && ! empty($digest[1]), 401, 'Assinatura de webhook inválida.');
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$timestamp[1]};";
        $expected = hash_hmac('sha256', $manifest, $secret);
        abort_unless(hash_equals($expected, $digest[1]), 401, 'Assinatura de webhook inválida.');
    }
}
