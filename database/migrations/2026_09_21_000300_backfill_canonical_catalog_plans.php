<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plans = [
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

        foreach ($plans as $productCode => $definitions) {
            $productId = DB::table('products')->where('code', $productCode)->value('id');
            if (! $productId) continue;

            foreach ($definitions as $code => [$name, $segment, $moduleCodes]) {
                $existing = DB::table('plans')->where('product_id', $productId)->where('code', $code)->first(['id']);
                $planId = $existing?->id;

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
                        'display_order' => array_search($code, array_keys($definitions), true),
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
        // Os planos e vínculos podem ser utilizados por histórico comercial;
        // nenhuma exclusão automática é segura durante o rollback.
    }
};
