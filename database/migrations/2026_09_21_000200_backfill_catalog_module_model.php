<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\PrefixedUlid;

return new class extends Migration
{
    public function up(): void
    {
        $capabilities = [
            'processos' => ['tramitacao_processual', 'partes_processuais', 'sigilo', 'movimentacoes_oficiais', 'controle_prazos'],
            'contatos' => ['pessoas', 'advogados', 'instituicoes', 'orgaos', 'enderecos', 'canais', 'partes_processuais'],
            'expedicoes' => ['oficios', 'mandados', 'cartas', 'editais', 'guias_execucao', 'atos_ordinatorios'],
            'tarefas' => ['tarefas', 'fluxos', 'receitas', 'prazos', 'pendencias', 'alertas'],
            'audiencias' => ['agenda', 'processo', 'participantes', 'alertas', 'notificacoes', 'anotacoes_internas', 'status_audiencia', 'tarefas_relacionadas'],
            'audiencias_externo' => ['status_tempo_real', 'data_horario', 'modalidade', 'mensagens_institucionais', 'acesso_expiravel', 'registro_de_acessos'],
            'pessoas' => ['cadastro_pessoas', 'documentos', 'enderecos', 'canais'],
            'empreendimentos' => ['cadastro_empreendimentos', 'unidades', 'tabelas_de_preco'],
            'imoveis' => ['cadastro_imoveis', 'caracteristicas', 'disponibilidade'],
            'leads' => ['captacao', 'qualificacao', 'distribuicao', 'historico'],
            'funil' => ['etapas', 'negociacoes', 'conversoes'],
            'relatorios' => ['indicadores', 'exportacoes', 'relatorios_comerciais'],
            'relatorios-gerenciais' => ['indicadores', 'metas', 'relatorios_gerenciais'],
            'whatsapp' => ['mensagens', 'templates', 'historico_comunicacao'],
            'website' => ['integracao_site', 'formularios', 'captacao'],
            'portal-imoveis' => ['vitrine_imoveis', 'publicacao', 'leads'],
            'equipes' => ['usuarios', 'equipes', 'permissoes'],
            'colaboracao' => ['comentarios', 'compartilhamento', 'historico'],
            'permissoes' => ['perfis', 'permissoes', 'auditoria'],
            'distribuicao-leads' => ['regras_distribuicao', 'fila_de_leads', 'historico'],
            'visao-gerencial' => ['indicadores', 'metas', 'desempenho'],
            'filiais' => ['unidades', 'usuarios', 'indicadores_por_filial'],
            'notificacoes' => ['notificacoes', 'alertas', 'preferencias'],
        ];

        DB::table('modules as module')
            ->join('products as product', 'product.id', '=', 'module.product_id')
            ->select('module.*', 'product.code as product_code')
            ->orderBy('module.id')
            ->get()
            ->each(function (object $module) use ($capabilities): void {
                $moduleCode = $this->moduleCode($module);
                $segment = $this->segment($module->segment_code ?? null, $module->product_code, $module->code, $module->name);
                $context = $this->context($module->context_code ?? null, $module->code, $module->name);
                $variant = $module->variant_code ?: Str::slug((string) $module->code);
                $dependencies = $moduleCode === 'audiencias_externo'
                    ? ['audiencias']
                    : ($moduleCode === 'contatos' ? [] : ($module->product_code === 'law' ? ['contatos'] : []));
                $existingCapabilities = $this->jsonArray($module->capabilities ?? null);
                $existingDependencies = $this->normalizeCodeList($this->jsonArray($module->dependencies ?? null));
                $existingIncompatibilities = $this->jsonArray($module->incompatibilities ?? null);

                DB::table('modules')->where('id', $module->id)->update([
                    'module_code' => $moduleCode,
                    'segment_code' => $segment,
                    'context_code' => $context,
                    'variant_code' => $variant,
                    'capabilities' => $existingCapabilities !== [] ? json_encode($existingCapabilities, JSON_UNESCAPED_UNICODE) : json_encode($capabilities[$moduleCode] ?? [], JSON_UNESCAPED_UNICODE),
                    'dependencies' => $existingDependencies !== [] ? json_encode($existingDependencies, JSON_UNESCAPED_UNICODE) : json_encode($dependencies, JSON_UNESCAPED_UNICODE),
                    'incompatibilities' => json_encode($existingIncompatibilities, JSON_UNESCAPED_UNICODE),
                    'technical_description' => $module->technical_description ?: "Funcionalidade {$module->name} vinculada ao catálogo {$module->product_code}.",
                    'commercial_content' => $module->commercial_content ?: $module->name,
                    'available_standalone' => $module->available_standalone ?? true,
                    'updated_at' => now(),
                ]);
            });

        DB::table('plans as plan')
            ->join('products as product', 'product.id', '=', 'plan.product_id')
            ->select('plan.*', 'product.code as product_code')
            ->orderBy('plan.id')
            ->get()
            ->each(function (object $plan): void {
                $segment = $this->planSegment($plan->segment ?? null, $plan->product_code, $plan->code, $plan->name);

                DB::table('plans')->where('id', $plan->id)->update([
                    'segment' => $segment,
                    'technical_description' => $plan->technical_description ?: "Plano {$plan->name} com composição publicada pelo Backoffice.",
                    'commercial_content' => $plan->commercial_content ?: "Plano {$plan->name}.",
                    'updated_at' => now(),
                ]);
            });

        $canonicalPlans = [
            'law' => [
                'law-advocacia' => ['Advocacia', 'advocacia', ['processos', 'contatos', 'tarefas']],
                'law-cartorio-criminal' => ['Cartório Criminal', 'setor_publico', ['processos', 'contatos', 'expedicoes', 'tarefas']],
                'law-cartorio-civel' => ['Cartório Cível', 'setor_publico', ['processos', 'contatos', 'expedicoes', 'tarefas']],
                'law-gestao-audiencias' => ['Gestão de Audiências', 'setor_publico', ['processos', 'contatos', 'tarefas', 'audiencias']],
                'law-gestao-expedientes' => ['Gestão de Expedientes', 'setor_publico', ['processos', 'contatos', 'expedicoes', 'tarefas']],
            ],
            'lead' => [
                'lead-one-essencial' => ['Essencial', 'one', ['pessoas', 'imoveis', 'notificacoes']],
                'lead-one-profissional' => ['Profissional', 'one', ['pessoas', 'imoveis', 'empreendimentos', 'leads', 'funil', 'website', 'notificacoes']],
                'lead-one-avancado' => ['Avançado', 'one', ['pessoas', 'imoveis', 'empreendimentos', 'leads', 'funil', 'website', 'relatorios', 'notificacoes']],
                'lead-one-premium' => ['Premium', 'one', ['pessoas', 'imoveis', 'empreendimentos', 'leads', 'funil', 'website', 'relatorios', 'whatsapp', 'notificacoes']],
                'lead-team-essencial' => ['Team Essencial', 'team', ['pessoas', 'imoveis', 'empreendimentos', 'funil', 'website', 'equipes', 'colaboracao', 'permissoes', 'relatorios-gerenciais', 'notificacoes']],
                'lead-team-premium' => ['Team Premium', 'team', ['pessoas', 'imoveis', 'empreendimentos', 'leads', 'funil', 'website', 'relatorios', 'whatsapp', 'portal-imoveis', 'equipes', 'colaboracao', 'permissoes', 'distribuicao-leads', 'visao-gerencial', 'filiais', 'relatorios-gerenciais', 'notificacoes']],
            ],
        ];

        foreach ($canonicalPlans as $productCode => $plans) {
            $productId = DB::table('products')->where('code', $productCode)->value('id');
            if (! $productId) continue;

            foreach ($plans as $code => [$name, $segment, $moduleCodes]) {
                $plan = DB::table('plans')->where('product_id', $productId)->where('code', $code)->first(['id']);
                $planId = $plan?->id;

                if (! $planId) {
                    $planId = PrefixedUlid::make('PLN');
                    DB::table('plans')->insert([
                        'id' => $planId,
                        'product_id' => $productId,
                        'code' => $code,
                        'name' => $name,
                        'segment' => $segment,
                        'status' => 'ativo',
                        'publication_state' => 'rascunho',
                        'display_order' => array_search($code, array_keys($plans), true),
                        'featured' => false,
                        'technical_description' => "Plano {$name} com composição publicada pelo Backoffice.",
                        'commercial_content' => "Plano {$name}.",
                        'monthly_amount' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($moduleCodes as $moduleCode) {
                    $module = DB::table('modules')
                        ->where('product_id', $productId)
                        ->where(function ($query) use ($moduleCode): void {
                            $query->where('module_code', $moduleCode)->orWhere('code', $moduleCode);
                        })
                        ->orderBy('display_order')
                        ->first(['id']);

                    if (! $module) continue;

                    DB::table('plan_modules')->updateOrInsert(
                        ['plan_id' => $planId, 'module_id' => $module->id],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        // O backfill preserva dados existentes e não deve reintroduzir o contrato legado.
    }

    private function moduleCode(object $module): string
    {
        $value = Str::lower((string) ($module->module_code ?: $module->code.' '.$module->name));

        foreach (['audiencias_externo', 'audiencias', 'expedicoes', 'processos', 'contatos', 'tarefas', 'pessoas', 'empreendimentos', 'imoveis', 'leads', 'funil', 'relatorios-gerenciais', 'relatorios', 'whatsapp', 'website', 'portal-imoveis', 'equipes', 'colaboracao', 'permissoes', 'distribuicao-leads', 'visao-gerencial', 'filiais', 'notificacoes'] as $candidate) {
            if (str_contains($value, str_replace('-', ' ', $candidate)) || str_contains($value, $candidate)) {
                return $candidate;
            }
        }

        return Str::slug((string) ($module->module_code ?: $module->code));
    }

    private function segment(?string $current, string $productCode, string $code, string $name): ?string
    {
        $value = Str::lower(trim((string) $current));
        if (in_array($value, ['advocacia', 'setor_publico', 'one', 'team'], true)) return $value;
        if (str_contains($value, 'advoc')) return 'advocacia';
        if (str_contains($value, 'setor') || str_contains($value, 'jur') || str_contains(Str::lower($code.' '.$name), 'vara')) return 'setor_publico';
        return $productCode === 'law' ? 'setor_publico' : ($value ?: null);
    }

    private function context(?string $current, string $code, string $name): ?string
    {
        if ($current) return Str::slug($current, '_');
        $value = Str::lower($code.' '.$name);
        foreach (['vara_criminal', 'vara_civel', 'juizado', 'cartorio', 'orgao_publico', 'escritorio', 'juridico'] as $candidate) {
            if (str_contains($value, str_replace('_', ' ', $candidate)) || str_contains($value, str_replace('_', '-', $candidate))) return $candidate;
        }
        return null;
    }

    private function planSegment(?string $current, string $productCode, string $code, string $name): ?string
    {
        $value = Str::lower(trim((string) $current));
        if (in_array($value, ['advocacia', 'setor_publico', 'one', 'team'], true)) return $value;
        if ($productCode === 'lead') return str_contains(Str::lower($code.' '.$name), 'team') ? 'team' : 'one';
        return str_contains($value, 'advoc') || str_contains(Str::lower($code.' '.$name), 'advoc') ? 'advocacia' : 'setor_publico';
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) return array_values(array_filter($value, 'is_string'));
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function normalizeCodeList(array $items): array
    {
        return array_values(array_unique(array_map(function (string $item): string {
            $value = Str::lower(trim($item));
            foreach (['audiencias_externo', 'audiencias', 'expedicoes', 'processos', 'contatos', 'tarefas'] as $candidate) {
                if (str_contains($value, $candidate)) return $candidate;
            }
            return Str::slug($item, '_');
        }, $items)));
    }
};
