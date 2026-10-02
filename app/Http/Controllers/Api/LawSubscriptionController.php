<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CatalogManager;
use App\Services\LawUsageMeter;
use App\Services\MercadoPagoClient;
use App\Services\PrefixedUlid;
use App\Services\SubscriptionChangeManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LawSubscriptionController extends Controller
{
    public function show(Request $request, CatalogManager $catalog, SubscriptionChangeManager $changes, LawUsageMeter $usage)
    {
        $this->authorizeAdmin($request);
        $companyId = (string) $request->attributes->get('active_company_id');
        $product = DB::table('products')->whereIn('code', ['law', 'fokus-law'])->orderByRaw("CASE WHEN code = 'law' THEN 0 ELSE 1 END")->first();
        abort_unless($product, 503, 'Catálogo do Fokus Law indisponível.');
        try { $published = $catalog->publicCatalog($product->code); }
        catch (\Throwable) { $published = ['product' => ['name' => 'Fokus Law'], 'name' => 'Fokus Law', 'modules' => [], 'plans' => []]; }
        $subscription = DB::table('subscriptions')->where('company_id', $companyId)->where('product_id', $product->id)
            ->whereNotIn('status', ['encerrada', 'cancelada'])->orderByDesc('created_at')->first();

        if (! $subscription) {
            return response()->json(['subscription' => null, 'catalog' => $published, 'usage' => null, 'history' => [], 'payments' => [], 'pending_change' => null, 'voucher_benefits' => []]);
        }

        $snapshot = $changes->snapshot($subscription);
        try {
            $moduleItems = collect($snapshot['items'] ?? [])->map(function (array $item): array {
                $module = DB::table('modules')->where('id', $item['module_id'] ?? null)->first();
                $capabilities = $module ? DB::table('module_capabilities')->where('module_id', $module->id)->orderBy('optional')->orderBy('name')->get(['code', 'name', 'optional']) : collect();
                return [
                    ...$item,
                    'module_code' => $module?->code,
                    'family' => $module?->module_code ?: $module?->code,
                    'capabilities' => $capabilities,
                    'personalizations' => $item['conditions']['personalizations'] ?? [],
                ];
            })->values();
        } catch (\Throwable $exception) {
            Log::warning('Fokus Law subscription module details could not be loaded.', ['exception' => $exception::class]);
            $moduleItems = collect($snapshot['items'] ?? [])->map(fn (array $item): array => [
                ...$item,
                'module_code' => $item['conditions']['module_code'] ?? null,
                'family' => $item['conditions']['module_code'] ?? null,
                'capabilities' => [],
                'personalizations' => $item['conditions']['personalizations'] ?? [],
            ])->values();
        }

        $snapshot['items'] = $moduleItems;
        $snapshot['version'] = (int) $subscription->version;
        $snapshot['current_period_starts_at'] = $subscription->current_period_starts_at;
        $snapshot['current_period_ends_at'] = $subscription->current_period_ends_at;
        $snapshot['cancel_at'] = $subscription->cancel_at;
        try {
            $payments = DB::table('payments')->where('company_id', $companyId)->where('subscription_id', $subscription->id)
                ->latest('created_at')->limit(6)->get(['id', 'status', 'amount', 'currency', 'created_at', 'paid_at', 'provider_checkout_url as checkout_url']);
        } catch (\Throwable $exception) {
            Log::warning('Fokus Law subscription payments could not be loaded.', ['exception' => $exception::class]);
            $payments = collect();
        }
        try {
            $history = DB::table('subscription_changes')->where('company_id', $companyId)->where('subscription_id', $subscription->id)
                ->latest('created_at')->limit(20)->get(['id', 'type', 'status', 'effective_at', 'proration_amount', 'reason', 'created_at', 'before_snapshot', 'after_snapshot'])
                ->map(function (object $item): array {
                    $entry = (array) $item;
                    $entry['before_snapshot'] = json_decode((string) $item->before_snapshot, true) ?: [];
                    $entry['after_snapshot'] = json_decode((string) $item->after_snapshot, true) ?: [];
                    return $entry;
                });
            $pending = DB::table('subscription_changes')->where('company_id', $companyId)->where('subscription_id', $subscription->id)
                ->whereIn('status', ['agendada', 'aguardando_pagamento'])->latest('created_at')->first();
            if ($pending) {
                $pending->before_snapshot = json_decode((string) $pending->before_snapshot, true) ?: [];
                $pending->after_snapshot = json_decode((string) $pending->after_snapshot, true) ?: [];
            }
        } catch (\Throwable $exception) {
            Log::warning('Fokus Law subscription change history could not be loaded.', ['exception' => $exception::class]);
            $history = collect();
            $pending = null;
        }

        try {
            $usageData = ['contatos_cadastrados' => $usage->contacts($companyId)];
        } catch (\Throwable $exception) {
            Log::warning('Fokus Law subscription usage could not be loaded.', ['exception' => $exception::class]);
            $usageData = ['contatos_cadastrados' => ['available' => false, 'reason' => 'temporarily_unavailable', 'used' => null, 'limit' => null, 'percentage' => null, 'over_threshold' => false]];
        }

        $voucherBenefits = DB::table('voucher_redemptions as redemption')
            ->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
            ->where('redemption.company_id', $companyId)
            ->where('redemption.subscription_id', $subscription->id)
            ->orderByDesc('redemption.created_at')
            ->limit(5)
            ->get([
                'redemption.id', 'redemption.discount_amount', 'redemption.benefit_starts_at',
                'redemption.benefit_ends_at', 'redemption.snapshot', 'redemption.created_at',
                'voucher.code', 'voucher.name', 'voucher.discount_type', 'voucher.discount_value',
            ])->map(function (object $redemption): array {
                $snapshot = json_decode((string) ($redemption->snapshot ?? ''), true) ?: [];
                return [
                    'code' => $snapshot['code'] ?? $redemption->code,
                    'name' => $snapshot['name'] ?? $redemption->name,
                    'discount_type' => $snapshot['discount_type'] ?? $redemption->discount_type,
                    'discount_value' => (float) ($snapshot['discount_value'] ?? $redemption->discount_value),
                    'discount_amount' => (float) $redemption->discount_amount,
                    'benefit_starts_at' => $redemption->benefit_starts_at,
                    'benefit_ends_at' => $redemption->benefit_ends_at,
                    'created_at' => $redemption->created_at,
                ];
            });

        return response()->json([
            'subscription' => $snapshot,
            'catalog' => $published,
            'usage' => $usageData,
            'history' => $history,
            'payments' => $payments,
            'pending_change' => $pending,
            'voucher_benefits' => $voucherBenefits,
        ]);
    }

    public function quote(Request $request, SubscriptionChangeManager $changes)
    {
        $this->authorizeAdmin($request);
        $data = $this->validateChange($request);
        $subscription = $this->currentSubscription($request);
        abort_if(isset($data['version']) && (int) $data['version'] !== (int) $subscription->version, 409, 'A assinatura mudou desde que a página foi carregada. Atualize antes de continuar.');
        $quote = $changes->quoteCustomerChange($subscription->id, $data);
        return response()->json($quote);
    }

    public function change(Request $request, SubscriptionChangeManager $changes, MercadoPagoClient $mercadoPago)
    {
        $this->authorizeAdmin($request);
        $data = $this->validateChange($request);
        $subscription = $this->currentSubscription($request);
        abort_if(isset($data['version']) && (int) $data['version'] !== (int) $subscription->version, 409, 'A assinatura mudou desde que a página foi carregada. Atualize antes de continuar.');
        $pending = DB::table('subscription_changes')->where('subscription_id', $subscription->id)->whereIn('status', ['agendada', 'aguardando_pagamento'])->exists();
        abort_if($pending, 409, 'Já existe uma alteração pendente. Edite ou cancele-a antes de criar outra.');

        $quote = $changes->quoteCustomerChange($subscription->id, $data);
        abort_if($quote['action'] === 'upgrade' && $quote['charge_now'] <= 0 && ! $quote['free_benefit'] && ! $subscription->provider_subscription_id,
            422, 'Não foi possível confirmar a forma de cobrança desta assinatura. Atualize o pagamento atual antes de solicitar o upgrade.');
        $result = $changes->change($subscription->id, [
            'action' => $quote['action'], 'target_plan_id' => $data['target_plan_id'] ?? null,
            'items' => $data['items'] ?? null, 'billing_cycle' => $data['billing_cycle'] ?? null,
            'expected_version' => $data['version'] ?? null, 'voucher_code' => $data['voucher_code'] ?? null,
            'reason' => $data['reason'] ?? 'Alteração solicitada pelo administrador da empresa.',
        ], $request->user());
        $result = app(\App\Services\SubscriptionBillingManager::class)->checkoutChange($subscription->id, $result, (string) $request->user()->email, '/portal/fokus-law/assinatura');
        app(\App\Services\AuditRecorder::class)->company(
            $subscription->company_id, $request->user()->id, 'subscription', $subscription->id, 'update',
            $result['before'], $result['after'], reason: $data['reason'] ?? 'Alteração comercial solicitada pelo administrador.', request: $request, actorType: 'customer',
        );

        return response()->json([
            'id' => $result['id'], 'status' => $result['status'], 'action' => $quote['action'],
            'effective_at' => $result['effective_at'], 'charge_now' => $result['proration_amount'],
            'checkout_url' => $result['checkout_url'],
            'message' => $result['status'] === 'aplicada' ? 'Alteração aplicada à assinatura.' : ($result['status'] === 'agendada' ? 'Redução agendada para o fim da vigência atual.' : 'Ampliação aguardando confirmação do pagamento.'),
        ], 201);
    }

    public function updatePending(Request $request, SubscriptionChangeManager $changes, MercadoPagoClient $mercadoPago)
    {
        $this->authorizeAdmin($request);
        $data = $this->validateChange($request);
        $subscription = $this->currentSubscription($request);
        $pending = DB::table('subscription_changes')->where('subscription_id', $subscription->id)->whereIn('status', ['agendada', 'aguardando_pagamento'])->latest('created_at')->first();
        abort_unless($pending, 404, 'Não há alteração pendente para editar.');
        $quote = $changes->quoteCustomerChange($subscription->id, $data);
        abort_if($quote['requires_new_voucher'], 422, $quote['voucher_message']);
        abort_if(isset($data['version']) && (int) $data['version'] !== (int) $subscription->version, 409, 'A assinatura mudou. Atualize antes de continuar.');
        $changes->cancelPending($subscription->id, $request->user()->id);
        return $this->change($request, $changes, $mercadoPago);
    }

    public function cancelPending(Request $request)
    {
        $this->authorizeAdmin($request);
        $subscription = $this->currentSubscription($request);
        $pending = DB::table('subscription_changes')->where('subscription_id', $subscription->id)->whereIn('status', ['agendada', 'aguardando_pagamento'])->latest('created_at')->first();
        abort_unless($pending, 404, 'Não há alteração pendente para cancelar.');
        app(SubscriptionChangeManager::class)->cancelPending($subscription->id, $request->user()->id);
        return response()->json(['message' => 'Alteração pendente cancelada.']);
    }

    private function validateChange(Request $request): array
    {
        return $request->validate([
            'target_plan_id' => ['nullable', 'string', 'max:30'],
            'items' => ['required_without:target_plan_id', 'array', 'min:1', 'max:30'],
            'items.*.module_code' => ['required_with:items', 'string', 'max:64'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.personalizations' => ['nullable', 'array'],
            'items.*.personalizations.*.type_code' => ['required', 'string', 'max:64'],
            'items.*.personalizations.*.tier_value' => ['required', 'integer', 'min:1'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])],
            'version' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'voucher_code' => ['nullable', 'string', 'max:64'],
        ]);
    }

    private function currentSubscription(Request $request): object
    {
        $productId = DB::table('products')->whereIn('code', ['law', 'fokus-law'])->orderByRaw("CASE WHEN code = 'law' THEN 0 ELSE 1 END")->value('id');
        $subscription = DB::table('subscriptions')->where('company_id', $request->attributes->get('active_company_id'))->where('product_id', $productId)
            ->whereNotIn('status', ['encerrada', 'cancelada'])->orderByDesc('created_at')->first();
        abort_unless($subscription, 404, 'A empresa não possui uma assinatura aberta do Fokus Law.');
        return $subscription;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->attributes->get('active_membership')->role === 'admin', 403, 'Apenas o administrador da empresa pode gerenciar a assinatura.');
    }
}
