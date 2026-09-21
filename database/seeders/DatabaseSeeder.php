<?php

namespace Database\Seeders;

use App\Services\PrefixedUlid;
use App\Services\CatalogManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        foreach (['admin' => 'Administrador', 'gestor' => 'Gestor', 'usuario' => 'Usuário'] as $code => $name) {
            $this->upsertCatalog('roles', ['code' => $code], ['name' => $name], 'PFL');
        }

        foreach (['law' => 'Fokus Law', 'lead' => 'Fokus Lead'] as $code => $name) {
            $this->upsertCatalog('products', ['code' => $code], ['name' => $name, 'active' => true], 'PRD');
            DB::table('products')->where('code', $code)->update([
                'technical_description' => "Sistema comercial {$name} com catálogo administrável pelo Backoffice.",
                'commercial_content' => "Oferta pública {$name}.",
                'status' => 'ativo',
                'publication_state' => 'rascunho',
                'display_order' => $code === 'law' ? 1 : 2,
                'updated_at' => now(),
            ]);
        }

        $modules = [
            'law' => [
                'processos-advocacia' => ['Gestão de Processos para Advogados', 'processos', 29.90, 'advocacia', 'escritorio', 'advocacia-escritorio', false],
                'processos-vara-criminal' => ['Gestão de Processos para Varas Criminais', 'processos', 34.90, 'setor_publico', 'vara_criminal', 'setor-publico-vara-criminal', false],
                'processos-vara-civel' => ['Gestão de Processos para Varas Cíveis', 'processos', 34.90, 'setor_publico', 'vara_civel', 'setor-publico-vara-civel', false],
                'processos-juizado' => ['Gestão de Processos para Juizados', 'processos', 32.90, 'setor_publico', 'juizado', 'setor-publico-juizado', false],
                'processos-orgao-publico' => ['Gestão de Processos para Órgãos Públicos', 'processos', 34.90, 'setor_publico', 'orgao_publico', 'setor-publico-orgao-publico', false],
                'contatos-advocacia' => ['Gestão de Contatos para Advogados', 'contatos', 14.90, 'advocacia', 'escritorio', 'advocacia-escritorio', false],
                'contatos-vara' => ['Gestão de Contatos para Varas', 'contatos', 16.90, 'setor_publico', 'vara', 'setor-publico-vara', false],
                'contatos-setor-publico' => ['Gestão de Contatos para Setor Público', 'contatos', 16.90, 'setor_publico', 'orgao_publico', 'setor-publico-orgao-publico', false],
                'expedicoes-cartorio' => ['Gestão de Expedições para Cartórios', 'expedicoes', 19.90, 'setor_publico', 'cartorio', 'setor-publico-cartorio', false],
                'expedicoes-vara' => ['Gestão de Expedições para Varas', 'expedicoes', 19.90, 'setor_publico', 'vara', 'setor-publico-vara', false],
                'expedicoes-orgao-publico' => ['Gestão de Expedições para Órgãos Públicos', 'expedicoes', 19.90, 'setor_publico', 'orgao_publico', 'setor-publico-orgao-publico', false],
                'tarefas-advocacia' => ['Gestão de Tarefas para Escritórios', 'tarefas', 19.90, 'advocacia', 'escritorio', 'advocacia-escritorio', false],
                'tarefas-vara' => ['Gestão de Tarefas para Unidades Judiciais', 'tarefas', 19.90, 'setor_publico', 'vara', 'setor-publico-vara', false],
                'tarefas-juizado' => ['Gestão de Tarefas para Juizados', 'tarefas', 19.90, 'setor_publico', 'juizado', 'setor-publico-juizado', false],
                'tarefas-orgao-publico' => ['Gestão de Tarefas para Órgãos Públicos', 'tarefas', 19.90, 'setor_publico', 'orgao_publico', 'setor-publico-orgao-publico', false],
                'audiencias-advocacia' => ['Gestão de Audiências para Advogados', 'audiencias', 24.90, 'advocacia', 'escritorio', 'advocacia-escritorio', false],
                'audiencias-vara-criminal' => ['Gestão de Audiências para Varas Criminais', 'audiencias', 29.90, 'setor_publico', 'vara_criminal', 'setor-publico-vara-criminal', false],
                'audiencias-vara-civel' => ['Gestão de Audiências para Varas Cíveis', 'audiencias', 29.90, 'setor_publico', 'vara_civel', 'setor-publico-vara-civel', false],
                'audiencias-juizado' => ['Gestão de Audiências para Juizados', 'audiencias', 27.90, 'setor_publico', 'juizado', 'setor-publico-juizado', false],
                'audiencias-orgao-publico' => ['Gestão de Audiências para Órgãos Públicos', 'audiencias', 29.90, 'setor_publico', 'orgao_publico', 'setor-publico-orgao-publico', false],
                'audiencias-externo' => ['Acompanhamento Externo de Audiências', 'audiencias_externo', 9.90, 'setor_publico', 'orgao_publico', 'setor-publico-audiencias-externo', false],
            ],
            'lead' => [
                'pessoas' => ['Gestão de Pessoas', 4.90, 'one,team', 'pessoas', false],
                'empreendimentos' => ['Gestão de Empreendimentos', 4.90, 'one,team', 'empreendimentos', false],
                'imoveis' => ['Gestão de Imóveis', 4.90, 'one,team', 'imoveis', false],
                'leads' => ['Gestão de Leads', 4.90, 'one,team', 'leads', true],
                'funil' => ['Funil de Vendas', 4.90, 'one,team', 'funil', false],
                'relatorios' => ['Emissão de Relatórios de Transações', 9.90, 'one,team', 'relatorios', false],
                'whatsapp' => ['Integração com WhatsApp', 9.90, 'one,team', 'whatsapp', false],
                'website' => ['Integração com Website', 14.90, 'one,team', 'website', false],
                'portal-imoveis' => ['Portal de Imóveis', 14.90, 'team', 'portal-imoveis', true],
                'equipes' => ['Gestão de Equipes', 9.90, 'team', 'equipes', true],
                'colaboracao' => ['Colaboração entre Corretores', 9.90, 'team', 'colaboracao', true],
                'permissoes' => ['Permissões por Função', 9.90, 'team', 'permissoes', true],
                'distribuicao-leads' => ['Distribuição de Leads', 9.90, 'team', 'distribuicao-leads', true],
                'visao-gerencial' => ['Visão Gerencial', 14.90, 'team', 'visao-gerencial', true],
                'filiais' => ['Gestão de Filiais', 14.90, 'team', 'filiais', true],
                'relatorios-gerenciais' => ['Relatórios Gerenciais', 14.90, 'team', 'relatorios-gerenciais', true],
                'notificacoes' => ['Notificações de Equipe', 4.90, 'team', 'notificacoes', true],
            ],
        ];

        foreach ($modules as $productCode => $items) {
            $productId = DB::table('products')->where('code', $productCode)->value('id');
            foreach ($items as $code => $definition) {
                if ($productCode === 'law') {
                    [$name, $moduleCode, $price, $segment, $context, $legacyFamily, $estimate] = $definition;
                } else {
                    [$name, $price, $segment, $moduleCode, $estimate] = $definition;
                    $context = null;
                }
                $this->upsertCatalog('modules', ['product_id' => $productId, 'code' => $code], ['name' => $name, 'monthly_price' => $price], 'MOD');
                DB::table('modules')->where('product_id', $productId)->where('code', $code)->update([
                    'module_code' => $moduleCode,
                    'context_code' => $context,
                    'technical_description' => "Funcionalidade {$name} vinculada ao catálogo {$productCode}.",
                    'commercial_content' => $name,
                    'status' => 'ativo',
                    'publication_state' => 'rascunho',
                    'display_order' => array_search($code, array_keys($items), true) + 1,
                    'available_standalone' => true,
                    'price_is_estimate' => $estimate,
                    'updated_at' => now(),
                ]);
                $moduleId = DB::table('modules')->where('product_id', $productId)->where('code', $code)->value('id');
                DB::table('module_segments')->where('module_id', $moduleId)->delete();
                foreach (array_filter(array_map('trim', explode(',', (string) $segment))) as $segmentCode) {
                    if (collect(config('catalog.segments.'.$productCode, []))->pluck('code')->contains($segmentCode)) {
                        DB::table('module_segments')->insert(['module_id' => $moduleId, 'segment_code' => $segmentCode, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                DB::table('module_capabilities')->where('module_id', $moduleId)->delete();
                foreach (config('catalog.capabilities.'.$moduleCode, []) as $capability) {
                    DB::table('module_capabilities')->insert(['id' => PrefixedUlid::make('MCF'), 'module_id' => $moduleId, 'code' => $capability['code'], 'name' => $capability['label'], 'optional' => (bool) ($capability['optional'] ?? false), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        $plans = [
            'law' => [
                'law-advocacia' => ['Advocacia', 'advocacia', ['processos-advocacia', 'contatos-advocacia', 'tarefas-advocacia']],
                'law-cartorio-criminal' => ['Cartório Criminal', 'setor_publico', ['processos-vara-criminal', 'contatos-vara', 'expedicoes-cartorio', 'tarefas-vara']],
                'law-cartorio-civel' => ['Cartório Cível', 'setor_publico', ['processos-vara-civel', 'contatos-vara', 'expedicoes-cartorio', 'tarefas-vara']],
                'law-gestao-audiencias' => ['Gestão de Audiências', 'setor_publico', ['processos-juizado', 'contatos-vara', 'tarefas-juizado', 'audiencias-juizado']],
                'law-gestao-expedientes' => ['Gestão de Expedientes', 'setor_publico', ['processos-orgao-publico', 'contatos-setor-publico', 'expedicoes-orgao-publico', 'tarefas-orgao-publico']],
                'law-audiencias-advocacia' => ['Audiências para Advocacia', 'advocacia', ['processos-advocacia', 'contatos-advocacia', 'tarefas-advocacia', 'audiencias-advocacia']],
                'law-audiencias-setor-publico' => ['Audiências para Setor Público', 'setor_publico', ['processos-vara-criminal', 'contatos-vara', 'tarefas-vara', 'audiencias-vara-criminal']],
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

        foreach ($plans as $productCode => $items) {
            $productId = DB::table('products')->where('code', $productCode)->value('id');
            foreach ($items as $code => [$name, $segment, $moduleCodes]) {
                $plan = DB::table('plans')->where('product_id', $productId)->where('code', $code)->first(['id']);
                if (! $plan) {
                    $this->upsertCatalog('plans', ['product_id' => $productId, 'code' => $code], ['name' => $name], 'PLN');
                    $planId = DB::table('plans')->where('product_id', $productId)->where('code', $code)->value('id');
                    DB::table('plans')->where('id', $planId)->update([
                        'segment' => $segment,
                        'status' => 'ativo',
                        'publication_state' => 'rascunho',
                        'display_order' => array_search($code, array_keys($items), true),
                        'updated_at' => now(),
                    ]);
                } else {
                    $planId = $plan->id;
                }

                DB::table('plans')->where('id', $planId)->update([
                    'name' => $name,
                    'technical_description' => "Plano {$name} com composição publicada pelo Backoffice.",
                    'commercial_content' => "Plano {$name}.",
                    'segment' => $segment,
                    'status' => 'ativo',
                    'updated_at' => now(),
                ]);
                DB::table('plan_modules')->where('plan_id', $planId)->delete();

                $moduleIds = DB::table('modules')->where('product_id', $productId)->whereIn('code', $moduleCodes)->pluck('id');
                foreach ($moduleIds as $moduleId) {
                    DB::table('plan_modules')->updateOrInsert(
                        ['plan_id' => $planId, 'module_id' => $moduleId],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                }
            }
        }

        foreach ($modules as $productCode => $items) {
            $productId = DB::table('products')->where('code', $productCode)->value('id');
            DB::table('modules')->where('product_id', $productId)->whereNotIn('code', array_keys($items))->delete();
        }

        foreach (['law', 'lead'] as $productCode) {
            $productId = DB::table('products')->where('code', $productCode)->value('id');
            if ($productId && ! DB::table('catalog_publications')->where('product_id', $productId)->exists()) {
                app(CatalogManager::class)->publish($productId, null, 'Carga inicial publicada pelo seeder do Marco 3.');
            }
        }
    }

    private function upsertCatalog(string $table, array $where, array $values, string $prefix): void
    {
        $query = DB::table($table)->where($where);
        if ($query->exists()) {
            $query->update([...$values, 'updated_at' => now()]);

            return;
        }
        DB::table($table)->insert([...$where, ...$values, 'id' => PrefixedUlid::make($prefix), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lawCapabilities(string $module, string $segment, string $context): array
    {
        return match ($module) {
            'processos' => $segment === 'advocacia'
                ? ['anotacoes_manuais', 'controle_prazos', 'acompanhamento_estrategico', 'clientes_partes']
                : ($segment === 'setor_publico'
                    ? ['processos_administrativos', 'interessados', 'setores', 'tramitacao_interna', 'pareceres', 'controle_prazos']
                    : ['tramitacao_processual', 'partes_processuais', 'sigilo', 'movimentacoes_oficiais', 'filas_operacionais']),
            'contatos' => $segment === 'advocacia'
                ? ['clientes', 'partes', 'advogados', 'correspondentes']
                : ($segment === 'setor_publico' ? ['orgaos', 'unidades', 'autoridades', 'destinatarios_oficiais'] : ['partes_processuais', 'representantes', 'orgaos']),
            'expedicoes' => ['oficios', 'mandados', 'cartas', 'editais', 'guias_execucao', 'atos_ordinatorios'],
            'audiencias' => ['agenda', 'processo', 'participantes', 'alertas', 'notificacoes', 'anotacoes_internas', 'status_audiencia', 'tarefas_relacionadas'],
            'audiencias_externo' => ['status_tempo_real', 'data_horario', 'modalidade', 'mensagens_institucionais', 'acesso_expiravel', 'registro_de_acessos'],
            default => ['tarefas', 'fluxos', 'receitas', 'prazos', 'pendencias', 'alertas'],
        };
    }
}
