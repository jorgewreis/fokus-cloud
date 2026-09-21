<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogManager
{
    public const CONTRACT_VERSION = '0.0.3';

    public const USAGE = [
        'expedicoes' => ['label' => 'Expedicoes', 'summary' => 'expedicoes', 'options' => [2500, 5000, 10000, 20000, 50000], 'step' => 2],
        'partes' => ['label' => 'Partes', 'summary' => 'partes', 'options' => [5000, 10000, 20000, 50000, 100000], 'step' => 4],
        'pessoas' => ['label' => 'Pessoas', 'summary' => 'pessoas', 'options' => [50, 250, 1000, 5000, 10000], 'step' => 4],
        'empreendimentos' => ['label' => 'Empreendimentos', 'summary' => 'empreendimentos', 'options' => [20, 50, 100, 500, 1000], 'step' => 4],
        'imoveis' => ['label' => 'Imoveis', 'summary' => 'imoveis', 'options' => [200, 500, 1000, 5000, 10000], 'step' => 4],
        'relatorios' => ['label' => 'Relatorios', 'summary' => 'relatorios', 'options' => [500, 1000, 2000, 5000, 10000], 'step' => 4],
    ];

    public function adminCatalog(): array
    {
        $products = DB::table('products')->orderBy('display_order')->orderBy('name')->get();
        $plans = $this->managementPlans();
        $modules = DB::table('modules')->orderBy('display_order')->orderBy('name')->get();

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'products' => $products->map(fn (object $product): array => [
                ...$this->productPayload($product),
                'published_version' => (int) $product->published_catalog_version,
                'modules' => $modules->where('product_id', $product->id)->values()->map(fn (object $module): array => $this->modulePayload($module))->all(),
                'plans' => $plans->where('product_id', $product->id)->values()->all(),
            ])->values()->all(),
            'publications' => $this->adminPublications(),
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
                'plan.display_order',
                'plan.featured',
                'product.name',
                'product.code',
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
                'plan.display_order',
                'plan.featured',
                'product.name as product_name',
                'product.code as product_code',
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
                'module.segment_code',
                'module.context_code',
                'module.variant_code',
                'module.status',
                'module.publication_state',
                'module.price_is_estimate',
            ])
            ->groupBy('plan_id');

        return $plans->map(function (object $plan) use ($planModules): array {
            $monthlyAmount = $this->planMonthlyAmount($plan);
            $lineName = $this->lineName($plan->product_name, $plan->segment);

            return [
                'id' => $plan->id,
                'product_id' => $plan->product_id,
                'product_code' => $plan->product_code,
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
                'modules' => ($planModules[$plan->id] ?? collect())->map(fn (object $module): array => [
                    ...$this->modulePayload($module),
                    'monthly_amount' => (float) $module->monthly_price,
                    'price_is_estimate' => (bool) $module->price_is_estimate,
                ])->values()->all(),
            ];
        });
    }

    public function createProduct(array $data): string
    {
        $id = PrefixedUlid::make('PRD');
        $displayOrder = array_key_exists('display_order', $data)
            ? (int) $data['display_order']
            : $this->nextProductDisplayOrder();

        DB::transaction(function () use ($data, $id, $displayOrder): void {
            $this->shiftProductOrdersFrom($displayOrder);
            DB::table('products')->insert($this->productWritePayload([...$data, 'display_order' => $displayOrder], [
                'id' => $id,
                'code' => Str::slug($data['code']),
                'active' => ($data['status'] ?? 'ativo') === 'ativo',
                'publication_state' => 'rascunho',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });

        return $id;
    }

    public function updateProduct(string $productId, array $data): void
    {
        $extra = ['updated_at' => now()];
        if (array_key_exists('status', $data)) {
            $extra['active'] = $data['status'] === 'ativo';
        }

        DB::transaction(function () use ($productId, $data, $extra): void {
            $payload = $this->productWritePayload($data, $extra);

            if (array_key_exists('display_order', $data)) {
                $currentOrder = (int) DB::table('products')->where('id', $productId)->value('display_order');
                $nextOrder = (int) $data['display_order'];

                if ($currentOrder !== $nextOrder) {
                    DB::table('products')->where('id', $productId)->update(['display_order' => $this->temporaryProductDisplayOrder(), 'updated_at' => now()]);
                    $this->shiftProductOrdersFrom($nextOrder, $productId);
                }
            }

            DB::table('products')->where('id', $productId)->update($payload);
        });
    }

    public function activateProduct(string $productId): array
    {
        $current = DB::table('products')->where('id', $productId)->first();
        abort_unless($current, 404, 'Produto não encontrado.');

        DB::table('products')->where('id', $productId)->update([
            'status' => 'ativo',
            'active' => true,
            'publication_state' => $current->publication_state === 'arquivado' ? 'rascunho' : $current->publication_state,
            'updated_at' => now(),
        ]);

        return [(array) $current, (array) DB::table('products')->where('id', $productId)->first()];
    }

    public function deleteProduct(string $productId): array
    {
        $current = DB::table('products')->where('id', $productId)->first();
        abort_unless($current, 404, 'Produto não encontrado.');

        $dependencies = collect([
            DB::table('modules')->where('product_id', $productId)->exists() ? 'módulos' : null,
            DB::table('plans')->where('product_id', $productId)->exists() ? 'planos' : null,
            DB::table('catalog_publications')->where('product_id', $productId)->exists() ? 'publicações' : null,
            DB::table('subscriptions')->where('product_id', $productId)->exists() ? 'assinaturas' : null,
            DB::table('vouchers')->where('product_id', $productId)->exists() ? 'vouchers' : null,
        ])->filter()->values();
        abort_if($dependencies->isNotEmpty(), 422, 'Não é possível excluir este produto porque existem vínculos: '.$dependencies->implode(', ').'. Arquive-o para preservar o histórico.');

        DB::table('products')->where('id', $productId)->delete();

        return (array) $current;
    }

    public function createModule(array $data): string
    {
        $id = PrefixedUlid::make('MOD');
        $publicationState = $this->modulePublicationStateForStatus($data['status'] ?? null, 'rascunho');
        DB::table('modules')->insert($this->moduleWritePayload($data, [
            'id' => $id,
            'code' => Str::slug($data['code']),
            'publication_state' => $publicationState,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $id;
    }

    public function updateModule(string $moduleId, array $data): array
    {
        $current = DB::table('modules')->where('id', $moduleId)->first();
        abort_unless($current, 404, 'Funcionalidade não encontrada.');
        $extra = ['updated_at' => now()];
        if (array_key_exists('status', $data)) {
            $extra['publication_state'] = $this->modulePublicationStateForStatus($data['status'], $current->publication_state ?? 'rascunho');
        }
        DB::table('modules')->where('id', $moduleId)->update($this->moduleWritePayload($data, $extra));
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
            $this->syncPlanModules($id, $data['module_ids']);
        }

        return $id;
    }

    public function updatePlan(string $planId, array $data): void
    {
        DB::table('plans')->where('id', $planId)->update($this->planWritePayload($data, ['updated_at' => now()]));

        if (array_key_exists('module_ids', $data)) {
            $this->syncPlanModules($planId, $data['module_ids'] ?? []);
        }
    }

    public function syncPlanModules(string $planId, array $moduleIds): void
    {
        $plan = DB::table('plans')->where('id', $planId)->first();
        abort_unless($plan, 404, 'Plano não encontrado.');

        $moduleIds = array_values(array_unique(array_filter($moduleIds)));
        abort_if($moduleIds === [], 422, 'Informe ao menos uma funcionalidade para o plano.');

        $modules = DB::table('modules')->whereIn('id', $moduleIds)->get();
        abort_unless($modules->count() === count($moduleIds), 422, 'Funcionalidade inválida.');
        abort_if($modules->pluck('product_id')->unique()->count() !== 1 || $modules->first()->product_id !== $plan->product_id, 422, 'Todas as funcionalidades devem pertencer ao sistema do plano.');

        DB::transaction(function () use ($planId, $moduleIds): void {
            DB::table('plan_modules')->where('plan_id', $planId)->delete();
            foreach ($moduleIds as $moduleId) {
                DB::table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'created_at' => now(), 'updated_at' => now()]);
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
            DB::table('products')->where('id', $productId)->update(['publication_state' => 'publicado', 'published_catalog_version' => $version, 'updated_at' => now()]);
            DB::table('modules')->where('product_id', $productId)->where('status', 'ativo')->update(['publication_state' => 'publicado', 'updated_at' => now()]);
            DB::table('plans')->where('product_id', $productId)->where('status', 'ativo')->update(['publication_state' => 'publicado', 'updated_at' => now()]);
        });

        return ['id' => $publicationId, 'version' => $version, 'snapshot' => [...$snapshot, 'published_version' => $version]];
    }

    public function publicCatalog(string $productCode): array
    {
        $product = DB::table('products')->where('code', $productCode)->first();
        abort_unless($product, 404, 'Produto não encontrado.');

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
            'products' => 'products',
            'modules' => 'modules',
            'plans' => 'plans',
            default => abort(404, 'Item de catálogo não encontrado.'),
        };

        $current = DB::table($table)->where('id', $id)->first();
        abort_unless($current, 404, 'Item de catálogo não encontrado.');

        $status = $table === 'products'
            ? ($state === 'arquivado' ? 'inativo' : 'pausado')
            : ($table === 'plans'
                ? 'inativo'
                : ($state === 'arquivado' ? 'arquivado' : 'inativo'));
        DB::table($table)->where('id', $id)->update([
            'status' => $status,
            'publication_state' => $state,
            ...($table === 'products' ? ['active' => false] : []),
            'updated_at' => now(),
        ]);

        return [(array) $current, (array) DB::table($table)->where('id', $id)->first()];
    }

    public function activateModule(string $id): array
    {
        $current = DB::table('modules')->where('id', $id)->first();
        abort_unless($current, 404, 'Módulo não encontrado.');
        DB::table('modules')->where('id', $id)->update([
            'status' => 'ativo',
            'publication_state' => 'publicado',
            'updated_at' => now(),
        ]);

        return [(array) $current, (array) DB::table('modules')->where('id', $id)->first()];
    }

    private function modulePublicationStateForStatus(?string $status, string $currentPublicationState): string
    {
        return match ($status) {
            'inativo', 'pausado' => 'pausado',
            'arquivado' => 'arquivado',
            default => $currentPublicationState,
        };
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
                'publication_state' => $latestVersion > 0 ? 'publicado' : 'rascunho',
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

    public function publishedModuleMap(string $productCode): Collection
    {
        return collect($this->publicCatalog($productCode)['modules'] ?? [])->keyBy('code');
    }

    public function publishedPlanMap(string $productCode): Collection
    {
        return collect($this->publicCatalog($productCode)['plans'] ?? [])->keyBy('code');
    }

    private function buildPublicationSnapshot(string $productId): array
    {
        $product = DB::table('products')->where('id', $productId)->first();
        abort_unless($product, 404, 'Produto não encontrado.');
        abort_if($product->status !== 'ativo' || ! $product->active, 422, 'O sistema precisa estar ativo para publicação.');
        abort_if($product->publication_state === 'arquivado', 422, 'Sistema arquivado não pode ser publicado.');

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

            $technicalCodes = collect($plan['modules'])->pluck('module_code')->filter()->values();
            abort_if($technicalCodes->count() !== $technicalCodes->unique()->count(), 422, 'Plano publicado contém variantes incompatíveis do mesmo módulo.');

            foreach ($plan['modules'] as $module) {
                foreach ($this->jsonArray($module['dependencies'] ?? null) as $dependency) {
                    abort_unless($technicalCodes->contains($dependency) || in_array($dependency, $moduleCodes, true), 422, 'Plano publicado não atende dependências de funcionalidade.');
                }
                foreach ($this->jsonArray($module['incompatibilities'] ?? null) as $incompatibility) {
                    abort_if($technicalCodes->contains($incompatibility) || in_array($incompatibility, $moduleCodes, true), 422, 'Plano publicado contém funcionalidades incompatíveis.');
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
                'usage' => $this->usageFor($module),
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
            'status' => $product->status ?? (($product->active ?? true) ? 'ativo' : 'inativo'),
            'publication_state' => $product->publication_state ?? 'rascunho',
            'display_order' => (int) ($product->display_order ?? 0),
            'featured' => (bool) ($product->featured ?? false),
            'active' => (bool) ($product->active ?? true),
        ];
    }

    private function modulePayload(object $module): array
    {
        return [
            'id' => $module->id,
            'product_id' => $module->product_id ?? null,
            'code' => $module->code,
            'module_code' => $module->module_code ?? $module->code,
            'name' => $module->name,
            'technical_description' => $module->technical_description ?? null,
            'commercial_content' => $module->commercial_content ?? null,
            'monthly_price' => (float) ($module->monthly_price ?? 0),
            'segment_code' => $module->segment_code ?? null,
            'context_code' => $module->context_code ?? null,
            'variant_code' => $module->variant_code ?? null,
            'capabilities' => $this->jsonArray($module->capabilities ?? null),
            'dependencies' => $this->jsonArray($module->dependencies ?? null),
            'incompatibilities' => $this->jsonArray($module->incompatibilities ?? null),
            'status' => $module->status,
            'publication_state' => $module->publication_state ?? 'rascunho',
            'display_order' => (int) ($module->display_order ?? 0),
            'featured' => (bool) ($module->featured ?? false),
            'capacity_unit' => $module->capacity_unit ?? null,
            'default_capacity' => isset($module->default_capacity) ? (int) $module->default_capacity : null,
            'capacity_options' => $this->jsonArray($module->capacity_options ?? null),
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
                'status' => $data['status'] ?? null,
                'publication_state' => $data['publication_state'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : null,
                'featured' => array_key_exists('featured', $data) ? (bool) $data['featured'] : null,
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
                'segment_code' => $data['segment_code'] ?? null,
                'context_code' => $data['context_code'] ?? null,
                'variant_code' => $data['variant_code'] ?? null,
                'capabilities' => array_key_exists('capabilities', $data) ? json_encode($data['capabilities'] ?? []) : null,
                'dependencies' => array_key_exists('dependencies', $data) ? json_encode($data['dependencies'] ?? []) : null,
                'incompatibilities' => array_key_exists('incompatibilities', $data) ? json_encode($data['incompatibilities'] ?? []) : null,
                'status' => $data['status'] ?? null,
                'publication_state' => $data['publication_state'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : null,
                'featured' => array_key_exists('featured', $data) ? (bool) $data['featured'] : null,
                'capacity_unit' => $data['capacity_unit'] ?? null,
                'default_capacity' => isset($data['default_capacity']) ? (int) $data['default_capacity'] : null,
                'capacity_options' => array_key_exists('capacity_options', $data) ? json_encode($data['capacity_options'] ?? []) : null,
                'available_standalone' => array_key_exists('available_standalone', $data) ? (bool) $data['available_standalone'] : null,
                'price_is_estimate' => array_key_exists('price_is_estimate', $data) ? (bool) $data['price_is_estimate'] : null,
            ], fn ($value): bool => $value !== null),
        ];
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
                'monthly_amount' => array_key_exists('monthly_amount', $data) ? $data['monthly_amount'] : null,
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
        return $plan->configured_monthly_amount === null
            ? CatalogPricing::suggestedMonthly((float) $plan->module_monthly_amount)
            : (float) $plan->configured_monthly_amount;
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

    private function usageFor(object $module): ?array
    {
        if ($module->capacity_options) {
            $options = $this->jsonArray($module->capacity_options);

            return $options ? [
                'label' => $module->capacity_unit ?: $module->name,
                'summary' => $module->capacity_unit ?: 'itens',
                'options' => $options,
                'step' => 0,
            ] : null;
        }

        return self::USAGE[$module->module_code ?: $module->code] ?? self::USAGE[$module->code] ?? null;
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = $value ? json_decode((string) $value, true) : [];

        return is_array($decoded) ? $decoded : [];
    }
}
