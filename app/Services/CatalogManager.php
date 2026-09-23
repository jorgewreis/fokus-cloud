<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogManager
{
    public const CONTRACT_VERSION = '0.1.0';

    public function adminCatalog(): array
    {
        $products = DB::table('products')->orderBy('display_order')->orderBy('name')->get();
        $plans = $this->managementPlans();
        $modules = DB::table('modules')->orderBy('display_order')->orderBy('name')->get();

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'options' => $this->catalogOptions(),
            'products' => $products->map(fn (object $product): array => [
                ...$this->productPayload($product),
                'published_catalog_version' => (int) $product->published_catalog_version,
                'publication_pending' => (bool) $product->publication_pending,
                'modules' => $modules->where('product_id', $product->id)->values()->map(fn (object $module): array => $this->modulePayload($module))->all(),
                'plans' => $plans->where('product_id', $product->id)->values()->all(),
            ])->values()->all(),
            'publications' => $this->adminPublications(),
        ];
    }

    public function catalogOptions(?string $productCode = null): array
    {
        $products = DB::table('products')->when($productCode, fn ($query) => $query->where('code', $productCode))->get(['id', 'code', 'name']);
        $families = [];
        $capabilities = [];
        foreach ($products as $product) {
            $catalogProductCode = $this->catalogProductCode($product->code);
            $families[$product->code] = collect(config('catalog.families.'.$catalogProductCode, []))->map(fn (array $item): array => ['code' => $item['code'], 'label' => $item['label'], 'custom' => false])->values()->all();
            $customFamilies = DB::table('catalog_custom_module_families')->where('product_id', $product->id)->orderBy('name')->get(['code', 'name']);
            $families[$product->code] = [...$families[$product->code], ...$customFamilies->map(fn (object $item): array => ['code' => $item->code, 'label' => $item->name, 'custom' => true])->all()];
            $capabilities[$product->code] = [];
            foreach (config('catalog.families.'.$catalogProductCode, []) as $familyItem) {
                $family = $familyItem['code'];
                $capabilities[$product->code][$family] = collect(config('catalog.capabilities.'.$family, []))->map(fn (array $item): array => ['code' => $item['code'], 'label' => $item['label'], 'optional' => (bool) ($item['optional'] ?? false), 'custom' => false])->values()->all();
            }
            $customCapabilities = DB::table('catalog_custom_capabilities')->where('product_id', $product->id)->orderBy('name')->get(['module_code', 'code', 'name']);
            foreach ($customCapabilities->groupBy('module_code') as $family => $items) {
                $capabilities[$product->code][$family] = [...($capabilities[$product->code][$family] ?? []), ...$items->map(fn (object $item): array => ['code' => $item->code, 'label' => $item->name, 'optional' => false, 'custom' => true])->all()];
            }
        }
        $segments = config('catalog.segments');
        $contexts = config('catalog.contexts');
        foreach ($products as $product) {
            $catalogProductCode = $this->catalogProductCode($product->code);
            if ($catalogProductCode !== $product->code) {
                $segments[$product->code] = $segments[$catalogProductCode] ?? [];
                $contexts[$product->code] = $contexts[$catalogProductCode] ?? [];
            }
        }

        return [
            'products' => $products->map(fn (object $product): array => ['id' => $product->id, 'code' => $product->code, 'name' => $product->name])->values()->all(),
            'segments' => $segments,
            'contexts' => $contexts,
            'families' => $families,
            'capabilities' => $capabilities,
            'personalization_types' => config('catalog.personalization_types', []),
        ];
    }

    public function managementPlans(): Collection
    {
        $plans = DB::table('plans as plan')
            ->join('products as product', 'product.id', '=', 'plan.product_id')
            ->leftJoin('plan_modules as plan_module', 'plan_module.plan_id', '=', 'plan.id')
            ->leftJoin('modules as module', 'module.id', '=', 'plan_module.module_id')
            ->groupBy(
                'plan.id',
                'plan.product_id',
                'plan.code',
                'plan.name',
                'plan.technical_description',
                'plan.commercial_content',
                'plan.monthly_amount',
                'plan.segment',
                'plan.status',
                'plan.publication_state',
                'plan.published_version',
                'plan.display_order',
                'plan.featured',
                'product.name',
                'product.code',
                'product.status',
                'product.published_catalog_version',
            )
            ->select(
                'plan.id',
                'plan.product_id',
                'plan.code',
                'plan.name',
                'plan.technical_description',
                'plan.commercial_content',
                'plan.monthly_amount as configured_monthly_amount',
                'plan.segment',
                'plan.status',
                'plan.publication_state',
                'plan.published_version',
                'plan.display_order',
                'plan.featured',
                'product.name as product_name',
                'product.code as product_code',
                'product.status as product_status',
                'product.published_catalog_version as product_publication_version',
                DB::raw('round(coalesce(sum(module.monthly_price), 0), 2) as module_monthly_amount'),
                DB::raw('count(module.id) as modules_count'),
            )
            ->orderBy('plan.product_id')
            ->orderBy('plan.display_order')
            ->orderBy('plan.name')
            ->get();

        $planModules = DB::table('plan_modules as plan_module')
            ->join('modules as module', 'module.id', '=', 'plan_module.module_id')
            ->orderBy('module.display_order')
            ->orderBy('module.name')
            ->get([
                'plan_module.plan_id',
                'module.id',
                'module.code',
                'module.module_code',
                'module.name',
                'module.monthly_price',
                'module.status',
                'module.publication_state',
                'module.price_is_estimate',
            ])
            ->groupBy('plan_id');

        $subscriptionRows = DB::table('subscriptions')->get(['company_id', 'status', 'commercial_snapshot']);
        $voucherCounts = DB::table('vouchers')->select('plan_id', DB::raw('count(*) as aggregate'))->whereNotNull('plan_id')->groupBy('plan_id')->pluck('aggregate', 'plan_id');

        return $plans->map(function (object $plan) use ($planModules, $subscriptionRows, $voucherCounts): array {
            $monthlyAmount = $this->planMonthlyAmount($plan);
            $lineName = $this->lineName($plan->product_name, $plan->segment);
            $personalizationDefaults = $this->planPersonalizationDefaults($plan->id);
            $linkedSubscriptions = $subscriptionRows->filter(function (object $subscription) use ($plan): bool {
                $snapshot = json_decode((string) $subscription->commercial_snapshot, true) ?: [];
                return (string) ($snapshot['plan_id'] ?? '') === (string) $plan->id;
            });

            return [
                'id' => $plan->id,
                'product_id' => $plan->product_id,
                'product_name' => $plan->product_name,
                'product_code' => $plan->product_code,
                'product_status' => $plan->product_status,
                'product_publication_version' => (int) ($plan->product_publication_version ?? 0),
                'published_version' => (int) $plan->published_version,
                'code' => $plan->code,
                'name' => $plan->name,
                'base_name' => $plan->name,
                'system' => $lineName,
                'full_name' => $lineName.' - '.$plan->name,
                'technical_description' => $plan->technical_description,
                'commercial_content' => $plan->commercial_content,
                'segment' => $plan->segment,
                'status' => $plan->status,
                'publication_state' => $plan->publication_state,
                'display_order' => (int) $plan->display_order,
                'featured' => (bool) $plan->featured,
                'monthly_amount' => $monthlyAmount,
                'annual_amount' => CatalogPricing::annualFromMonthly($monthlyAmount),
                'modules_count' => (int) $plan->modules_count,
                'subscription_count' => $linkedSubscriptions->count(),
                'active_subscription_count' => $linkedSubscriptions->whereIn('status', ['ativa', 'aguardando_pagamento', 'inadimplente', 'suspensa', 'cancelamento_agendado'])->count(),
                'closed_subscription_count' => $linkedSubscriptions->where('status', 'encerrada')->count(),
                'company_count' => $linkedSubscriptions->pluck('company_id')->unique()->count(),
                'voucher_count' => (int) ($voucherCounts[$plan->id] ?? 0),
                'modules' => ($planModules[$plan->id] ?? collect())->map(fn (object $module): array => [
                    ...$this->modulePayload($module),
                    'monthly_amount' => (float) $module->monthly_price,
                    'price_is_estimate' => (bool) $module->price_is_estimate,
                ])->values()->all(),
                'personalization_defaults' => $personalizationDefaults,
            ];
        });
    }

    public function createProduct(array $data): string
    {
        $id = PrefixedUlid::make('PRD');
        $displayOrder = $this->nextProductDisplayOrder();

        DB::transaction(function () use ($data, $id, $displayOrder): void {
            DB::table('products')->insert($this->productWritePayload([...$data, 'display_order' => $displayOrder], [
                'id' => $id,
                'code' => Str::slug($data['code']),
                'status' => 'pausado',
                'active' => false,
                'publication_pending' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->normalizeProductDisplayOrders();
        });

        return $id;
    }

    public function updateProduct(string $productId, array $data): void
    {
        $current = DB::table('products')->where('id', $productId)->first();
        abort_unless($current, 404, 'Produto não encontrado.');
        abort_unless(in_array($current->status, ['pausado', 'inativo'], true), 422, 'Pause o produto antes de editá-lo.');
        $extra = ['updated_at' => now(), 'publication_pending' => true];

        DB::transaction(function () use ($productId, $data, $extra): void {
            $payload = $this->productWritePayload($data, $extra);

            if (array_key_exists('display_order', $data)) {
                $currentOrder = (int) DB::table('products')->where('id', $productId)->value('display_order');
                $nextOrder = (int) $data['display_order'];

                if ($currentOrder < $nextOrder) {
                    DB::table('products')->where('id', $productId)->update(['display_order' => $this->temporaryProductDisplayOrder(), 'updated_at' => now()]);
                    DB::table('products')
                        ->whereBetween('display_order', [$currentOrder + 1, $nextOrder])
                        ->orderBy('display_order')
                        ->get(['id', 'display_order'])
                        ->each(fn (object $item) => DB::table('products')->where('id', $item->id)->update(['display_order' => (int) $item->display_order - 1, 'updated_at' => now()]));
                } elseif ($currentOrder > $nextOrder) {
                    DB::table('products')->where('id', $productId)->update(['display_order' => $this->temporaryProductDisplayOrder(), 'updated_at' => now()]);
                    DB::table('products')
                        ->whereBetween('display_order', [$nextOrder, $currentOrder - 1])
                        ->orderByDesc('display_order')
                        ->get(['id', 'display_order'])
                        ->each(fn (object $item) => DB::table('products')->where('id', $item->id)->update(['display_order' => (int) $item->display_order + 1, 'updated_at' => now()]));
                }
            }

            DB::table('products')->where('id', $productId)->update($payload);
            $this->normalizeProductDisplayOrders();
        });
    }

    public function activateProduct(string $productId): array
    {
        $current = DB::table('products')->where('id', $productId)->first();
        abort_unless($current, 404, 'Produto não encontrado.');

        DB::table('products')->where('id', $productId)->update([
            'status' => 'ativo',
            'active' => true,
            'updated_at' => now(),
        ]);

        return [(array) $current, (array) DB::table('products')->where('id', $productId)->first()];
    }

    public function pauseProduct(string $productId): array
    {
        $current = DB::table('products')->where('id', $productId)->first();
        abort_unless($current, 404, 'Produto não encontrado.');

        DB::table('products')->where('id', $productId)->update([
            'status' => 'pausado',
            'active' => false,
            'publication_pending' => true,
            'updated_at' => now(),
        ]);

        return [(array) $current, (array) DB::table('products')->where('id', $productId)->first()];
    }

    public function deleteProduct(string $productId): array
    {
        $current = DB::table('products')->where('id', $productId)->first();
        abort_unless($current, 404, 'Produto não encontrado.');
        abort_if($current->status !== 'pausado', 422, 'Somente produtos pausados podem ser excluídos.');

        $dependencies = collect([
            DB::table('modules')->where('product_id', $productId)->exists() ? 'módulos' : null,
            DB::table('plans')->where('product_id', $productId)->exists() ? 'planos' : null,
            DB::table('catalog_publications')->where('product_id', $productId)->exists() ? 'publicações' : null,
            DB::table('subscriptions')->where('product_id', $productId)->exists() ? 'assinaturas' : null,
            DB::table('vouchers')->where('product_id', $productId)->exists() ? 'vouchers' : null,
        ])->filter()->values();
        abort_if($dependencies->isNotEmpty(), 422, 'Não é possível excluir este produto porque existem vínculos: '.$dependencies->implode(', ').'.');

        DB::table('products')->where('id', $productId)->delete();

        return (array) $current;
    }

    public function createModule(array $data): string
    {
        $id = PrefixedUlid::make('MOD');
        $product = DB::table('products')->where('id', $data['product_id'] ?? null)->first();
        abort_unless($product, 422, 'Produto inválido.');
        $familyCode = $this->resolveFamilyCode($product, $data);
        $code = $this->generateModuleCode($product, $familyCode, $data['context_code'] ?? null);
        $displayOrder = ((int) DB::table('modules')->where('product_id', $product->id)->max('display_order')) + 1;

        DB::transaction(function () use ($id, $data, $product, $familyCode, $code, $displayOrder): void {
            DB::table('modules')->insert($this->moduleWritePayload($data, [
                'id' => $id,
                'product_id' => $product->id,
                'code' => $code,
                'module_code' => $familyCode,
                'status' => 'inativo',
                'publication_state' => 'pausado',
                'display_order' => $displayOrder,
                'featured' => false,
                'available_standalone' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->syncModuleRelations($id, $data, $product);
        });

        return $id;
    }

    public function updateModule(string $moduleId, array $data): array
    {
        $current = DB::table('modules')->where('id', $moduleId)->first();
        abort_unless($current, 404, 'Funcionalidade não encontrada.');
        abort_unless(in_array($current->status, ['pausado', 'inativo'], true), 422, 'Pause o módulo antes de editá-lo.');
        $product = DB::table('products')->where('id', $current->product_id)->first();
        abort_unless($product, 422, 'Produto inválido.');
        abort_if(array_key_exists('code', $data), 422, 'O código público é imutável.');
        $familyCode = array_key_exists('module_code', $data) ? $this->resolveFamilyCode($product, $data) : $current->module_code;
        $payload = $this->moduleWritePayload($data, ['updated_at' => now(), 'module_code' => $familyCode]);
        unset($payload['product_id'], $payload['code'], $payload['status'], $payload['publication_state']);
        DB::transaction(function () use ($moduleId, $data, $product, $payload, $current): void {
            if (array_key_exists('display_order', $data)) {
                $currentOrder = (int) $current->display_order;
                $nextOrder = (int) $data['display_order'];

                if ($currentOrder < $nextOrder) {
                    DB::table('modules')->where('id', $moduleId)->update(['display_order' => $this->temporaryModuleDisplayOrder($product->id), 'updated_at' => now()]);
                    DB::table('modules')
                        ->where('product_id', $product->id)
                        ->whereBetween('display_order', [$currentOrder + 1, $nextOrder])
                        ->orderBy('display_order')
                        ->get(['id', 'display_order'])
                        ->each(fn (object $item) => DB::table('modules')->where('id', $item->id)->update(['display_order' => (int) $item->display_order - 1, 'updated_at' => now()]));
                } elseif ($currentOrder > $nextOrder) {
                    DB::table('modules')->where('id', $moduleId)->update(['display_order' => $this->temporaryModuleDisplayOrder($product->id), 'updated_at' => now()]);
                    DB::table('modules')
                        ->where('product_id', $product->id)
                        ->whereBetween('display_order', [$nextOrder, $currentOrder - 1])
                        ->orderByDesc('display_order')
                        ->get(['id', 'display_order'])
                        ->each(fn (object $item) => DB::table('modules')->where('id', $item->id)->update(['display_order' => (int) $item->display_order + 1, 'updated_at' => now()]));
                }
            }

            DB::table('modules')->where('id', $moduleId)->update($payload);
            if (array_key_exists('module_code', $data) || array_key_exists('capability_codes', $data) || array_key_exists('segments', $data) || array_key_exists('dependency_ids', $data) || array_key_exists('incompatibility_ids', $data) || array_key_exists('personalizations', $data)) {
                $this->syncModuleRelations($moduleId, $data, $product);
            }
            DB::table('products')->where('id', $product->id)->update(['publication_pending' => true, 'updated_at' => now()]);
        });
        return [(array) $current, (array) DB::table('modules')->where('id', $moduleId)->first()];
    }

    public function createPlan(array $data): string
    {
        $id = PrefixedUlid::make('PLN');
        DB::table('plans')->insert($this->planWritePayload($data, [
            'id' => $id,
            'code' => Str::slug($data['code']),
            'publication_state' => 'rascunho',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if (! empty($data['module_ids'])) {
            $this->syncPlanModules($id, $data['module_ids'], $data['personalization_defaults'] ?? []);
        }

        return $id;
    }

    public function updatePlan(string $planId, array $data): void
    {
        $current = DB::table('plans')->where('id', $planId)->first();
        abort_unless($current, 404, 'Plano não encontrado.');
        abort_unless(in_array($current->status, ['pausado', 'inativo'], true), 422, 'Pause o plano antes de editá-lo.');
        unset($data['status'], $data['publication_state']);
        DB::transaction(function () use ($planId, $data, $current): void {
            DB::table('plans')->where('id', $planId)->update($this->planWritePayload($data, ['updated_at' => now()]));
            if (array_key_exists('module_ids', $data)) {
                $this->syncPlanModules($planId, $data['module_ids'] ?? [], $data['personalization_defaults'] ?? []);
            }
            DB::table('products')->whereIn('id', array_unique([$current->product_id, $data['product_id'] ?? $current->product_id]))->update(['publication_pending' => true, 'updated_at' => now()]);
        });
    }

    public function syncPlanModules(string $planId, array $moduleIds, array $personalizationDefaults = []): void
    {
        $plan = DB::table('plans')->where('id', $planId)->first();
        abort_unless($plan, 404, 'Plano não encontrado.');

        $moduleIds = array_values(array_unique(array_filter($moduleIds)));
        abort_if($moduleIds === [], 422, 'Informe ao menos uma funcionalidade para o plano.');

        $modules = DB::table('modules')->whereIn('id', $moduleIds)->get();
        abort_unless($modules->count() === count($moduleIds), 422, 'Funcionalidade inválida.');
        abort_if($modules->pluck('product_id')->unique()->count() !== 1 || $modules->first()->product_id !== $plan->product_id, 422, 'Todas as funcionalidades devem pertencer ao sistema do plano.');

        $defaultRows = $this->validatedPlanPersonalizationDefaults($plan, $modules, $personalizationDefaults);
        DB::transaction(function () use ($planId, $moduleIds, $defaultRows): void {
            DB::table('plan_modules')->where('plan_id', $planId)->delete();
            DB::table('plan_personalization_defaults')->where('plan_id', $planId)->delete();
            foreach ($moduleIds as $moduleId) {
                DB::table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($defaultRows as $row) {
                DB::table('plan_personalization_defaults')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function publish(string $productId, ?string $adminId, string $reason): array
    {
        $snapshot = $this->buildPublicationSnapshot($productId);
        $version = ((int) DB::table('catalog_publications')->where('product_id', $productId)->max('version')) + 1;
        $publicationId = PrefixedUlid::make('CPB');

        DB::transaction(function () use ($productId, $adminId, $reason, $snapshot, $version, $publicationId): void {
            DB::table('catalog_publications')->insert([
                'id' => $publicationId,
                'product_id' => $productId,
                'version' => $version,
                'snapshot' => json_encode([...$snapshot, 'published_version' => $version]),
                'published_by_platform_admin_id' => $adminId,
                'reason' => $reason,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('products')->where('id', $productId)->update(['published_catalog_version' => $version, 'publication_pending' => false, 'updated_at' => now()]);
            DB::table('modules')->where('product_id', $productId)->where('status', 'ativo')->update(['publication_state' => 'publicado', 'updated_at' => now()]);
            DB::table('plans')->where('product_id', $productId)->where('status', 'ativo')->where('publication_state', '!=', 'publicado')->increment('published_version');
            DB::table('plans')->where('product_id', $productId)->where('status', 'ativo')->update(['publication_state' => 'publicado', 'updated_at' => now()]);
        });

        return ['id' => $publicationId, 'version' => $version, 'snapshot' => [...$snapshot, 'published_version' => $version]];
    }

    public function publicCatalog(string $productCode, bool $allowPendingPublication = false): array
    {
        $product = DB::table('products')->where('code', $productCode)->first();
        abort_unless($product, 404, 'Produto não encontrado.');
        abort_unless($product->status === 'ativo' && $product->active, 422, 'Produto indisponível para novas contratações.');
        abort_if($product->publication_pending && ! $allowPendingPublication, 422, 'Catálogo pendente de republicação para novas contratações.');

        $publication = DB::table('catalog_publications')
            ->where('product_id', $product->id)
            ->orderByDesc('version')
            ->first();
        abort_unless($publication, 503, 'Catálogo indisponível.');

        $snapshot = json_decode($publication->snapshot, true) ?: [];

        return [
            ...$snapshot,
            'published_version' => (int) $publication->version,
            'published_at' => $publication->published_at,
        ];
    }

    public function pauseOrArchive(string $type, string $id, string $state): array
    {
        $table = match ($type) {
            'modules' => 'modules',
            'plans' => 'plans',
            default => abort(404, 'Item de catálogo não encontrado.'),
        };

        $current = DB::table($table)->where('id', $id)->first();
        abort_unless($current, 404, 'Item de catálogo não encontrado.');

        $status = $table === 'plans'
            ? 'inativo'
            : ($state === 'arquivado' ? 'arquivado' : 'inativo');
        DB::transaction(function () use ($table, $id, $status, $state, $current): void {
            DB::table($table)->where('id', $id)->update([
                'status' => $status,
                'publication_state' => $state,
                'updated_at' => now(),
            ]);
            DB::table('products')->where('id', $current->product_id)->update(['publication_pending' => true, 'updated_at' => now()]);
        });

        return [(array) $current, (array) DB::table($table)->where('id', $id)->first()];
    }

    public function activateModule(string $id): array
    {
        $current = DB::table('modules')->where('id', $id)->first();
        abort_unless($current, 404, 'Módulo não encontrado.');
        DB::table('modules')->where('id', $id)->update([
            'status' => 'ativo',
            'updated_at' => now(),
        ]);

        return [(array) $current, (array) DB::table('modules')->where('id', $id)->first()];
    }

    public function activatePlan(string $id): array
    {
        $current = DB::table('plans')->where('id', $id)->first();
        abort_unless($current, 404, 'Plano não encontrado.');
        abort_if($current->publication_state === 'arquivado', 422, 'Plano arquivado não pode ser ativado.');

        DB::table('plans')->where('id', $id)->update([
            'status' => 'ativo',
            'updated_at' => now(),
        ]);

        return [(array) $current, (array) DB::table('plans')->where('id', $id)->first()];
    }

    public function publishPlan(string $id): array
    {
        $current = DB::table('plans')->where('id', $id)->first();
        abort_unless($current, 404, 'Plano não encontrado.');
        abort_unless($current->status === 'ativo', 422, 'Somente planos ativos podem ser publicados.');
        abort_if($current->publication_state === 'arquivado', 422, 'Plano arquivado não pode ser publicado.');

        DB::transaction(function () use ($id, $current): void {
            DB::table('plans')->where('id', $id)->update([
                'publication_state' => 'publicado',
                'published_version' => $current->publication_state === 'publicado' ? $current->published_version : $current->published_version + 1,
                'updated_at' => now(),
            ]);
            DB::table('products')->where('id', $current->product_id)->update(['publication_pending' => true, 'updated_at' => now()]);
        });

        return [(array) $current, (array) DB::table('plans')->where('id', $id)->first()];
    }

    public function publishModule(string $id): array
    {
        $current = DB::table('modules')->where('id', $id)->first();
        abort_unless($current, 404, 'Módulo não encontrado.');
        abort_unless($current->status === 'ativo', 422, 'Somente módulos ativos podem ser publicados.');
        DB::transaction(function () use ($id, $current): void {
            DB::table('modules')->where('id', $id)->update([
                'publication_state' => 'publicado',
                'updated_at' => now(),
            ]);
            DB::table('products')->where('id', $current->product_id)->update(['publication_pending' => true, 'updated_at' => now()]);
        });

        return [(array) $current, (array) DB::table('modules')->where('id', $id)->first()];
    }

    public function deleteCatalogItem(string $type, string $id): array
    {
        $table = match ($type) {
            'module' => 'modules',
            'plan' => 'plans',
            default => abort(404, 'Item de catálogo não encontrado.'),
        };
        $current = DB::table($table)->where('id', $id)->first();
        abort_unless($current, 404, 'Item de catálogo não encontrado.');

        $dependencies = $type === 'module'
            ? $this->moduleDeletionDependencies($id)
            : $this->planDeletionDependencies($id, $current->code);
        abort_if($dependencies !== [], 422, 'Não é possível excluir este item porque existem vínculos: '.implode(', ', $dependencies).'. Arquive-o para preservar o histórico.');

        DB::transaction(function () use ($table, $id): void {
            DB::table($table)->where('id', $id)->delete();
        });

        return (array) $current;
    }

    public function deletePublication(string $publicationId): array
    {
        $publication = DB::table('catalog_publications')->where('id', $publicationId)->first();
        abort_unless($publication, 404, 'Publicação não encontrada.');
        $latestVersion = (int) DB::table('catalog_publications')->where('product_id', $publication->product_id)->max('version');
        abort_unless((int) $publication->version === $latestVersion, 422, 'Somente a publicação atualmente ativa pode ser removida.');

        DB::transaction(function () use ($publication, $publicationId, $latestVersion): void {
            DB::table('catalog_publications')->where('id', $publicationId)->delete();

            $latestVersion = (int) DB::table('catalog_publications')
                ->where('product_id', $publication->product_id)
                ->max('version');

            DB::table('products')->where('id', $publication->product_id)->update([
                'published_catalog_version' => $latestVersion,
                'updated_at' => now(),
            ]);
            if ($latestVersion === 0) {
                DB::table('modules')->where('product_id', $publication->product_id)->where('publication_state', 'publicado')->update(['publication_state' => 'rascunho', 'updated_at' => now()]);
                DB::table('plans')->where('product_id', $publication->product_id)->where('publication_state', 'publicado')->update(['publication_state' => 'rascunho', 'updated_at' => now()]);
            }
        });

        return (array) $publication;
    }

    private function moduleDeletionDependencies(string $moduleId): array
    {
        $dependencies = [];
        if (DB::table('plan_modules')->where('module_id', $moduleId)->exists()) $dependencies[] = 'composição de plano';
        if (DB::table('subscription_items')->where('module_id', $moduleId)->exists()) $dependencies[] = 'itens de assinatura';
        if ($this->catalogSnapshotsContain($moduleId)) $dependencies[] = 'publicações do catálogo';

        return $dependencies;
    }

    private function planDeletionDependencies(string $planId, string $planCode): array
    {
        $dependencies = [];
        if (DB::table('vouchers')->where('plan_id', $planId)->exists()) $dependencies[] = 'vouchers';
        if (DB::table('voucher_redemptions')->where('snapshot', 'like', '%'.$planId.'%')->orWhere('snapshot', 'like', '%'.$planCode.'%')->exists()) $dependencies[] = 'resgates de voucher';
        if (DB::table('subscription_items')->where('conditions_snapshot', 'like', '%'.$planCode.'%')->exists()) $dependencies[] = 'itens de assinatura';
        if ($this->catalogSnapshotsContain($planId) || $this->catalogSnapshotsContain($planCode)) $dependencies[] = 'publicações do catálogo';

        return $dependencies;
    }

    private function catalogSnapshotsContain(string $needle): bool
    {
        return DB::table('catalog_publications')->pluck('snapshot')->contains(function ($snapshot) use ($needle): bool {
            return str_contains((string) $snapshot, $needle);
        });
    }

    public function publishedModuleMap(string $productCode, bool $allowPendingPublication = false): Collection
    {
        return collect($this->publicCatalog($productCode, $allowPendingPublication)['modules'] ?? [])->keyBy('code');
    }

    public function publishedPlanMap(string $productCode, bool $allowPendingPublication = false): Collection
    {
        return collect($this->publicCatalog($productCode, $allowPendingPublication)['plans'] ?? [])->keyBy('code');
    }

    private function buildPublicationSnapshot(string $productId): array
    {
        $product = DB::table('products')->where('id', $productId)->first();
        abort_unless($product, 404, 'Produto não encontrado.');
        abort_if($product->status !== 'ativo' || ! $product->active, 422, 'O produto precisa estar ativo para gerar a versão técnica do catálogo.');

        $modules = DB::table('modules')
            ->where('product_id', $productId)
            ->where('status', 'ativo')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        abort_if($modules->isEmpty(), 422, 'Cadastre ao menos uma funcionalidade ativa antes de publicar.');
        $modules->each(function (object $module): void {
            abort_if(trim((string) $module->name) === '' || trim((string) $module->code) === '', 422, 'Toda funcionalidade publicada precisa de nome e código.');
            abort_if((float) $module->monthly_price < 0, 422, 'Funcionalidade publicada não pode ter preço negativo.');
            abort_if($module->publication_state === 'arquivado', 422, 'Funcionalidade arquivada não pode ser publicada.');
        });

        $plans = $this->managementPlans()->where('product_id', $productId)->where('status', 'ativo')->values();
        abort_if($plans->isEmpty(), 422, 'Cadastre ao menos um plano ativo antes de publicar.');
        $moduleByCode = $modules->keyBy('code');

        $plans->each(function (array $plan) use ($moduleByCode): void {
            abort_if($plan['modules_count'] < 1, 422, 'Todo plano publicado precisa ter ao menos uma funcionalidade.');
            abort_if((float) $plan['monthly_amount'] < 0, 422, 'Plano publicado não pode ter preço negativo.');

            $moduleCodes = collect($plan['modules'])->pluck('code')->all();
            foreach ($moduleCodes as $moduleCode) {
                abort_unless($moduleByCode->has($moduleCode), 422, 'Plano publicado contém funcionalidade indisponível.');
            }

            foreach ($plan['modules'] as $module) {
                foreach (collect($module['dependencies'] ?? [])->pluck('code')->all() as $dependency) {
                    abort_unless(in_array($dependency, $moduleCodes, true), 422, 'Plano publicado não atende dependências de funcionalidade.');
                }
                foreach (collect($module['incompatibilities'] ?? [])->pluck('code')->all() as $incompatibility) {
                    abort_if(in_array($incompatibility, $moduleCodes, true), 422, 'Plano publicado contém funcionalidades incompatíveis.');
                }
            }
        });

        $catalogName = $product->code === 'lead' ? 'Fokus Cloud Lead' : $product->name;

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'product' => $this->productPayload($product),
            'name' => $catalogName,
            'back' => $product->code === 'lead' ? '/produtos/fokus-lead' : '/produtos/fokus-law',
            'modules' => $modules->map(fn (object $module): array => [
                ...$this->modulePayload($module),
                'description' => (string) ($module->commercial_content ?: $module->technical_description),
                'monthly_amount' => (float) $module->monthly_price,
                'personalizations' => $this->modulePayload($module)['personalizations'],
            ])->values()->all(),
            'plans' => $plans->map(fn (array $plan): array => [
                'id' => $plan['id'],
                'code' => $plan['code'],
                'name' => $plan['full_name'],
                'base_name' => $plan['base_name'],
                'segment' => $plan['segment'],
                'featured' => (bool) $plan['featured'],
                'display_order' => (int) $plan['display_order'],
                'monthly_amount' => (float) $plan['monthly_amount'],
                'annual_amount' => (float) $plan['annual_amount'],
                'module_codes' => collect($plan['modules'])->pluck('code')->values()->all(),
                'personalization_defaults' => $plan['personalization_defaults'] ?? [],
            ])->values()->all(),
        ];
    }

    private function adminPublications(): array
    {
        return DB::table('catalog_publications as publication')
            ->join('products as product', 'product.id', '=', 'publication.product_id')
            ->leftJoin('platform_admins as admin', 'admin.id', '=', 'publication.published_by_platform_admin_id')
            ->orderByDesc('publication.published_at')
            ->orderByDesc('publication.version')
            ->get([
                'publication.id',
                'publication.product_id',
                'product.name as product_name',
                'publication.version',
                'publication.reason',
                'publication.published_at',
                'admin.name as published_by',
            ])
            ->map(fn (object $publication): array => [
                'id' => $publication->id,
                'product_id' => $publication->product_id,
                'product_name' => $publication->product_name,
                'version' => (int) $publication->version,
                'reason' => $publication->reason,
                'published_at' => $publication->published_at,
                'published_by' => $publication->published_by,
            ])
            ->all();
    }

    private function productPayload(object $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'technical_description' => $product->technical_description ?? null,
            'commercial_content' => $product->commercial_content ?? null,
            'status' => $product->status ?? (($product->active ?? true) ? 'ativo' : 'pausado'),
            'display_order' => (int) ($product->display_order ?? 0),
            'active' => (bool) ($product->active ?? true),
        ];
    }

    private function modulePayload(object $module): array
    {
        $moduleId = $module->id;
        $segments = DB::table('module_segments')->where('module_id', $moduleId)->orderBy('segment_code')->pluck('segment_code')->values()->all();
        $capabilityItems = DB::table('module_capabilities')->where('module_id', $moduleId)->orderBy('optional')->orderBy('name')->get()->map(fn (object $capability): array => [
            'code' => $capability->code,
            'name' => $capability->name,
            'optional' => (bool) $capability->optional,
        ])->values()->all();
        $dependencies = DB::table('module_dependencies as relation')->join('modules as dependency', 'dependency.id', '=', 'relation.dependency_module_id')->where('relation.module_id', $moduleId)->orderBy('dependency.name')->get(['dependency.id', 'dependency.code', 'dependency.name'])->map(fn (object $item): array => (array) $item)->values()->all();
        $incompatibilities = DB::table('module_incompatibilities as relation')->join('modules as incompatible', 'incompatible.id', '=', 'relation.incompatible_module_id')->where('relation.module_id', $moduleId)->orderBy('incompatible.name')->get(['incompatible.id', 'incompatible.code', 'incompatible.name'])->map(fn (object $item): array => (array) $item)->values()->all();
        $personalizations = DB::table('module_personalizations')->where('module_id', $moduleId)->orderBy('display_order')->orderBy('type_code')->get()->map(function (object $personalization): array {
            $type = collect(config('catalog.personalization_types', []))->firstWhere('code', $personalization->type_code) ?: [];
            return [
                'id' => $personalization->id,
                'type_code' => $personalization->type_code,
                'type_label' => $type['label'] ?? $personalization->type_code,
                'unit' => $personalization->unit,
                'required' => (bool) $personalization->required,
                'active' => (bool) $personalization->active,
                'display_order' => (int) $personalization->display_order,
                'tiers' => DB::table('module_personalization_tiers')->where('personalization_id', $personalization->id)->orderBy('display_order')->orderBy('value')->get()->map(fn (object $tier): array => [
                    'id' => $tier->id,
                    'value' => (int) $tier->value,
                    'additional_monthly_amount' => (float) $tier->additional_monthly_amount,
                    'active' => (bool) $tier->active,
                    'display_order' => (int) $tier->display_order,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'id' => $module->id,
            'product_id' => $module->product_id ?? null,
            'code' => $module->code,
            'module_code' => $module->module_code ?? $module->code,
            'name' => $module->name,
            'technical_description' => $module->technical_description ?? null,
            'commercial_content' => $module->commercial_content ?? null,
            'monthly_price' => (float) ($module->monthly_price ?? 0),
            'segments' => $segments,
            'segment_codes' => $segments,
            'context_code' => $module->context_code ?? null,
            'capabilities' => collect($capabilityItems)->pluck('name')->values()->all(),
            'capability_codes' => collect($capabilityItems)->pluck('code')->values()->all(),
            'capability_items' => $capabilityItems,
            'dependencies' => $dependencies,
            'dependency_ids' => collect($dependencies)->pluck('id')->values()->all(),
            'incompatibilities' => $incompatibilities,
            'incompatibility_ids' => collect($incompatibilities)->pluck('id')->values()->all(),
            'personalizations' => $personalizations,
            'status' => $module->status ?? 'rascunho',
            'publication_state' => $module->publication_state ?? 'rascunho',
            'display_order' => (int) ($module->display_order ?? 0),
            'featured' => (bool) ($module->featured ?? false),
            'available_standalone' => (bool) ($module->available_standalone ?? false),
            'price_is_estimate' => (bool) ($module->price_is_estimate ?? false),
        ];
    }

    private function productWritePayload(array $data, array $extra): array
    {
        return [
            ...$extra,
            ...array_filter([
                'code' => isset($data['code']) ? Str::slug($data['code']) : null,
                'name' => isset($data['name']) ? trim((string) $data['name']) : null,
                'technical_description' => $data['technical_description'] ?? null,
                'commercial_content' => $data['commercial_content'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : null,
            ], fn ($value): bool => $value !== null),
        ];
    }

    private function nextProductDisplayOrder(): int
    {
        return ((int) DB::table('products')->max('display_order')) + 1;
    }

    private function temporaryProductDisplayOrder(): int
    {
        return $this->nextProductDisplayOrder() + 1000;
    }

    private function normalizeProductDisplayOrders(): void
    {
        $products = DB::table('products')->orderBy('display_order')->orderBy('id')->get(['id']);
        $temporaryBase = $this->nextProductDisplayOrder() + $products->count() + 1000;

        $products->each(fn (object $product, int $index) => DB::table('products')->where('id', $product->id)->update([
            'display_order' => $temporaryBase + $index,
            'updated_at' => now(),
        ]));

        $products->each(fn (object $product, int $index) => DB::table('products')->where('id', $product->id)->update([
            'display_order' => $index + 1,
            'updated_at' => now(),
        ]));
    }

    private function shiftProductOrdersFrom(int $displayOrder, ?string $exceptProductId = null): void
    {
        $query = DB::table('products')->where('display_order', '>=', $displayOrder);
        if ($exceptProductId) {
            $query->where('id', '!=', $exceptProductId);
        }

        $query->orderByDesc('display_order')->get(['id', 'display_order'])->each(function (object $product): void {
            DB::table('products')->where('id', $product->id)->update([
                'display_order' => ((int) $product->display_order) + 1,
                'updated_at' => now(),
            ]);
        });
    }

    private function moduleWritePayload(array $data, array $extra): array
    {
        return [
            ...$extra,
            ...array_filter([
                'product_id' => $data['product_id'] ?? null,
                'code' => isset($data['code']) ? Str::slug($data['code']) : null,
                'module_code' => isset($data['module_code']) ? Str::slug($data['module_code']) : null,
                'name' => isset($data['name']) ? trim((string) $data['name']) : null,
                'technical_description' => $data['technical_description'] ?? null,
                'commercial_content' => $data['commercial_content'] ?? null,
                'monthly_price' => array_key_exists('monthly_price', $data) ? (float) $data['monthly_price'] : null,
                'context_code' => $data['context_code'] ?? null,
                'status' => $data['status'] ?? null,
                'publication_state' => $data['publication_state'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : null,
                'featured' => array_key_exists('featured', $data) ? (bool) $data['featured'] : null,
                'available_standalone' => array_key_exists('available_standalone', $data) ? (bool) $data['available_standalone'] : null,
                'price_is_estimate' => array_key_exists('price_is_estimate', $data) ? (bool) $data['price_is_estimate'] : null,
            ], fn ($value): bool => $value !== null),
        ];
    }

    private function temporaryModuleDisplayOrder(string $productId): int
    {
        return ((int) DB::table('modules')->where('product_id', $productId)->max('display_order')) + 1000;
    }

    private function catalogProductCode(string $productCode): string
    {
        return match ($productCode) {
            'fokus-law' => 'law',
            'fokus-lead' => 'lead',
            default => $productCode,
        };
    }

    private function resolveFamilyCode(object $product, array $data): string
    {
        $requested = Str::slug((string) ($data['module_code'] ?? ''));
        if ($requested === 'outro') {
            $name = trim((string) ($data['module_code_custom_name'] ?? ''));
            abort_if($name === '', 422, 'Informe o nome da nova família técnica.');
            $requested = Str::slug($name);
            abort_if($requested === '', 422, 'Nome de família técnica inválido.');
            $existing = DB::table('catalog_custom_module_families')->where('product_id', $product->id)->where('code', $requested)->first();
            if (! $existing) {
                DB::table('catalog_custom_module_families')->insert([
                    'id' => PrefixedUlid::make('FAM'),
                    'product_id' => $product->id,
                    'code' => $requested,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        $allowed = collect(config('catalog.families.'.$this->catalogProductCode($product->code), []))->pluck('code')->all();
        $allowed = [...$allowed, ...DB::table('catalog_custom_module_families')->where('product_id', $product->id)->pluck('code')->all()];
        abort_unless(in_array($requested, $allowed, true), 422, 'Família técnica inválida para este produto.');
        return $requested;
    }

    private function generateModuleCode(object $product, string $familyCode, ?string $contextCode): string
    {
        $base = Str::slug($product->code.'-'.$familyCode.'-'.($contextCode ?: 'geral'));
        $code = $base;
        if (DB::table('modules')->where('code', $code)->exists()) {
            do {
                $code = $base.'-'.Str::lower(Str::random(6));
            } while (DB::table('modules')->where('code', $code)->exists());
        }
        return $code;
    }

    private function syncModuleRelations(string $moduleId, array $data, object $product): void
    {
        $module = DB::table('modules')->where('id', $moduleId)->first();
        abort_unless($module, 404, 'Módulo não encontrado.');
        $existingSegments = DB::table('module_segments')->where('module_id', $moduleId)->pluck('segment_code')->all();
        $segments = array_values(array_unique(array_filter(array_key_exists('segments', $data) ? ($data['segments'] ?? []) : $existingSegments)));
        $catalogProductCode = $this->catalogProductCode($product->code);
        $allowedSegments = collect(config('catalog.segments.'.$catalogProductCode, []))->pluck('code')->all();
        abort_if(array_diff($segments, $allowedSegments) !== [], 422, 'Segmento inválido para este produto.');
        if (array_key_exists('context_code', $data) && $data['context_code']) {
            $contexts = collect($segments)->flatMap(fn (string $segment): array => config('catalog.contexts.'.$catalogProductCode.'.'.$segment, []))->pluck('code')->all();
            abort_if(! in_array($data['context_code'], $contexts, true), 422, 'Contexto inválido para o segmento selecionado.');
        }

        $existingCapabilities = DB::table('module_capabilities')->where('module_id', $moduleId)->pluck('code')->all();
        $capabilityCodes = array_values(array_unique(array_filter(array_key_exists('capability_codes', $data) ? ($data['capability_codes'] ?? []) : $existingCapabilities)));
        $family = $module->module_code;
        $allowedCapabilities = collect(config('catalog.capabilities.'.$family, []))->keyBy('code');
        $customCapabilities = DB::table('catalog_custom_capabilities')->where('product_id', $product->id)->where('module_code', $family)->get(['code', 'name'])->keyBy('code');
        foreach ($capabilityCodes as $customCode) {
            if (! Str::startsWith($customCode, 'custom_')) continue;
            if (! $customCapabilities->has($customCode)) {
                $customName = Str::headline(Str::after($customCode, 'custom_'));
                DB::table('catalog_custom_capabilities')->insert(['id' => PrefixedUlid::make('CAP'), 'product_id' => $product->id, 'module_code' => $family, 'code' => $customCode, 'name' => $customName, 'created_at' => now(), 'updated_at' => now()]);
                $customCapabilities->put($customCode, (object) ['code' => $customCode, 'name' => $customName]);
            }
        }
        foreach (($data['capability_custom_names'] ?? []) as $customName) {
            $customName = trim((string) $customName);
            if ($customName === '') continue;
            $customCode = Str::slug($customName);
            if (! $customCapabilities->has($customCode)) {
                DB::table('catalog_custom_capabilities')->insert(['id' => PrefixedUlid::make('CAP'), 'product_id' => $product->id, 'module_code' => $family, 'code' => $customCode, 'name' => $customName, 'created_at' => now(), 'updated_at' => now()]);
            }
            $capabilityCodes[] = $customCode;
        }
        $capabilityCodes = array_values(array_unique($capabilityCodes));
        foreach ($capabilityCodes as $code) abort_if(! $allowedCapabilities->has($code) && ! $customCapabilities->has($code) && ! DB::table('catalog_custom_capabilities')->where('product_id', $product->id)->where('module_code', $family)->where('code', $code)->exists(), 422, 'Funcionalidade inválida para a família técnica.');

        $existingDependencies = DB::table('module_dependencies')->where('module_id', $moduleId)->pluck('dependency_module_id')->all();
        $existingIncompatibilities = DB::table('module_incompatibilities')->where('module_id', $moduleId)->pluck('incompatible_module_id')->all();
        $dependencyIds = array_values(array_unique(array_filter(array_key_exists('dependency_ids', $data) ? ($data['dependency_ids'] ?? []) : $existingDependencies)));
        $incompatibilityIds = array_values(array_unique(array_filter(array_key_exists('incompatibility_ids', $data) ? ($data['incompatibility_ids'] ?? []) : $existingIncompatibilities)));
        $related = DB::table('modules')->whereIn('id', [...$dependencyIds, ...$incompatibilityIds])->get(['id', 'product_id']);
        abort_if($related->count() !== count(array_unique([...$dependencyIds, ...$incompatibilityIds])), 422, 'Dependência ou incompatibilidade inválida.');
        abort_if($related->contains(fn (object $item): bool => $item->product_id !== $product->id), 422, 'Os vínculos devem pertencer ao mesmo produto.');
        abort_if(in_array($moduleId, [...$dependencyIds, ...$incompatibilityIds], true), 422, 'Um módulo não pode apontar para si mesmo.');
        abort_if(array_intersect($dependencyIds, $incompatibilityIds) !== [], 422, 'Dependência e incompatibilidade não podem apontar para o mesmo módulo.');
        $this->assertDependencyGraph($moduleId, $dependencyIds);

        DB::table('module_segments')->where('module_id', $moduleId)->delete();
        DB::table('module_capabilities')->where('module_id', $moduleId)->delete();
        DB::table('module_dependencies')->where('module_id', $moduleId)->delete();
        DB::table('module_incompatibilities')->where('module_id', $moduleId)->delete();
        foreach ($segments as $segment) DB::table('module_segments')->insert(['module_id' => $moduleId, 'segment_code' => $segment, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($capabilityCodes as $index => $code) {
            $configured = $allowedCapabilities->get($code);
            $custom = $customCapabilities->get($code) ?: DB::table('catalog_custom_capabilities')->where('product_id', $product->id)->where('module_code', $family)->where('code', $code)->first();
            DB::table('module_capabilities')->insert(['id' => PrefixedUlid::make('MCF'), 'module_id' => $moduleId, 'code' => $code, 'name' => $configured['label'] ?? ($custom->name ?? $code), 'optional' => (bool) ($configured['optional'] ?? false), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($dependencyIds as $dependencyId) DB::table('module_dependencies')->insert(['module_id' => $moduleId, 'dependency_module_id' => $dependencyId, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($incompatibilityIds as $incompatibilityId) DB::table('module_incompatibilities')->insert(['module_id' => $moduleId, 'incompatible_module_id' => $incompatibilityId, 'created_at' => now(), 'updated_at' => now()]);
        if (array_key_exists('personalizations', $data)) $this->syncPersonalizations($moduleId, $data['personalizations'] ?? []);
    }

    private function syncPersonalizations(string $moduleId, array $personalizations): void
    {
        $types = collect(config('catalog.personalization_types', []))->keyBy('code');
        $seen = [];
        DB::table('module_personalization_tiers')->whereIn('personalization_id', DB::table('module_personalizations')->where('module_id', $moduleId)->pluck('id'))->delete();
        DB::table('module_personalizations')->where('module_id', $moduleId)->delete();
        foreach (array_values($personalizations) as $order => $item) {
            $typeCode = (string) ($item['type_code'] ?? '');
            abort_if(! $types->has($typeCode) || in_array($typeCode, $seen, true), 422, 'Tipo de personalização inválido ou duplicado.');
            $seen[] = $typeCode;
            $type = $types->get($typeCode);
            $personalizationId = PrefixedUlid::make('PSN');
            DB::table('module_personalizations')->insert(['id' => $personalizationId, 'module_id' => $moduleId, 'type_code' => $typeCode, 'unit' => $type['unit'], 'required' => (bool) ($item['required'] ?? true), 'active' => (bool) ($item['active'] ?? true), 'display_order' => $order + 1, 'created_at' => now(), 'updated_at' => now()]);
            $tierValues = [];
            foreach (array_values($item['tiers'] ?? []) as $tierOrder => $tier) {
                $value = (int) ($tier['value'] ?? 0);
                abort_if($value < 1 || in_array($value, $tierValues, true), 422, 'Faixa de personalização inválida ou duplicada.');
                $tierValues[] = $value;
                DB::table('module_personalization_tiers')->insert(['id' => PrefixedUlid::make('PST'), 'personalization_id' => $personalizationId, 'value' => $value, 'additional_monthly_amount' => max(0, (float) ($tier['additional_monthly_amount'] ?? 0)), 'active' => (bool) ($tier['active'] ?? true), 'display_order' => $tierOrder + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            abort_if($tierValues === [], 422, 'Toda personalização precisa de ao menos uma faixa.');
            abort_if((bool) ($item['required'] ?? true) && ! collect($item['tiers'])->contains(fn (array $tier): bool => (bool) ($tier['active'] ?? true)), 422, 'Personalização obrigatória precisa de uma faixa ativa.');
        }
    }

    private function assertDependencyGraph(string $moduleId, array $newDependencies): void
    {
        $edges = DB::table('module_dependencies')->get(['module_id', 'dependency_module_id'])->groupBy('module_id')->map(fn ($items): array => $items->pluck('dependency_module_id')->all())->all();
        $edges[$moduleId] = $newDependencies;
        $visit = function (string $node, array $path) use (&$visit, $edges): void {
            abort_if(in_array($node, $path, true), 422, 'As dependências formam um ciclo.');
            foreach ($edges[$node] ?? [] as $next) $visit($next, [...$path, $node]);
        };
        foreach (array_keys($edges) as $node) $visit($node, []);
    }

    private function validatedPlanPersonalizationDefaults(object $plan, Collection $modules, array $defaults): array
    {
        $moduleIds = $modules->pluck('id')->all();
        $personalizations = DB::table('module_personalizations')->whereIn('module_id', $moduleIds)->get();
        $byId = $personalizations->keyBy('id');
        $rows = [];
        foreach ($defaults as $default) {
            $personalizationId = $default['personalization_id'] ?? null;
            $tierId = $default['tier_id'] ?? null;
            $personalization = $byId->get($personalizationId);
            abort_unless($personalization, 422, 'Personalização padrão inválida para o plano.');
            $tier = DB::table('module_personalization_tiers')->where('id', $tierId)->where('personalization_id', $personalizationId)->where('active', true)->first();
            abort_unless($tier, 422, 'Faixa padrão inválida para a personalização.');
            $rows[] = ['plan_id' => $plan->id, 'personalization_id' => $personalizationId, 'tier_id' => $tierId];
        }
        foreach ($personalizations->where('required', true) as $personalization) abort_if(! collect($rows)->contains('personalization_id', $personalization->id), 422, 'Personalização obrigatória precisa de uma faixa-padrão no plano.');
        return $rows;
    }

    private function planPersonalizationDefaults(string $planId): array
    {
        return DB::table('plan_personalization_defaults as default_value')->join('module_personalizations as personalization', 'personalization.id', '=', 'default_value.personalization_id')->join('module_personalization_tiers as tier', 'tier.id', '=', 'default_value.tier_id')->where('default_value.plan_id', $planId)->get(['personalization.id as personalization_id', 'personalization.type_code', 'personalization.required', 'tier.id as tier_id', 'tier.value', 'tier.additional_monthly_amount'])->map(fn (object $item): array => (array) $item)->values()->all();
    }

    private function planWritePayload(array $data, array $extra): array
    {
        return [
            ...$extra,
            ...array_filter([
                'product_id' => $data['product_id'] ?? null,
                'code' => isset($data['code']) ? Str::slug($data['code']) : null,
                'name' => isset($data['base_name']) ? trim((string) $data['base_name']) : (isset($data['name']) ? trim((string) $data['name']) : null),
                'technical_description' => $data['technical_description'] ?? null,
                'commercial_content' => $data['commercial_content'] ?? null,
                'monthly_amount' => array_key_exists('monthly_amount', $data) && $data['monthly_amount'] !== null ? max(0, (float) $data['monthly_amount']) : null,
                'segment' => $data['segment'] ?? null,
                'status' => $data['status'] ?? null,
                'publication_state' => $data['publication_state'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : null,
                'featured' => array_key_exists('featured', $data) ? (bool) $data['featured'] : null,
            ], fn ($value): bool => $value !== null),
        ];
    }

    private function planMonthlyAmount(object $plan): float
    {
        $base = $plan->configured_monthly_amount === null
            ? CatalogPricing::suggestedMonthly((float) $plan->module_monthly_amount)
            : max(0, (float) $plan->configured_monthly_amount);
        return max(0, round($base + collect($this->planPersonalizationDefaults($plan->id))->sum(fn (array $default): float => max(0, (float) ($default['additional_monthly_amount'] ?? 0))), 2));
    }

    private function lineName(string $productName, ?string $segment): string
    {
        if ($productName !== 'Fokus Cloud Lead') {
            return $productName;
        }

        return match ($segment) {
            'one' => 'Fokus Cloud Lead One',
            'team' => 'Fokus Cloud Lead Team',
            default => $productName,
        };
    }

}
