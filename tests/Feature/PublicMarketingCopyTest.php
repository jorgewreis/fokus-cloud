<?php

namespace Tests\Feature;

use App\Support\PublishedLawOffers;
use Tests\TestCase;

class PublicMarketingCopyTest extends TestCase
{
    public function test_public_pages_follow_the_approved_marketing_copy(): void
    {
        $this->get('https://www.fokuscloud.com.br/')->assertOk();
        $home = file_get_contents(public_path('index.html'));
        $this->assertStringContainsString('Produtos digitais para rotinas que precisam de ordem.', $home);
        $this->assertStringContainsString('Em desenvolvimento', $home);
        $this->assertStringContainsString('Conhecer o Fokus Law', $home);
        $this->assertStringNotContainsString('Mais liberdade para grandes conquistas', $home);

        $this->get('https://www.fokuscloud.com.br/produtos/fokus-law')->assertOk();
        $law = file_get_contents(public_path('marketing/products/fokus-law.html'));
        $this->assertStringContainsString('Seus contatos e processos, no mesmo lugar.', $law);
        $this->assertStringContainsString('Fokus Law organiza contatos e processos de escritórios de advocacia e unidades do poder público, com controle de acesso e histórico auditável.', $law);
        $this->assertStringContainsString('Integração com o Datajud.', $law);
        $this->assertStringContainsString('Arquivamento de processos.', $law);
        $this->assertStringContainsString('Fokus Law sob medida', $law);
        $this->assertStringNotContainsString('78%', $law);
        $this->assertStringNotContainsString('Pesquisa, indicadores', $law);

        $plans = $this->get('https://www.fokuscloud.com.br/produtos/fokus-law/planos')->assertOk();
        $plans->assertSee('Escolha o plano para a sua operação.', false);
        $plans->assertSee('Módulos avulsos', false);
        $plans->assertDontSee('Assinatura Personalizada', false);
        $plans->assertDontSee('Conecte processos, pessoas, prazos e atividades', false);
        $cards = app(PublishedLawOffers::class)->cards([
            ['code' => 'advocacia-essencial', 'name' => 'Advocacia Essencial', 'segment' => 'advocacia', 'monthly_amount' => 49.9],
            ['code' => 'publico-geral', 'name' => 'Poder Público Geral Essencial', 'segment' => 'setor_publico', 'monthly_amount' => 6.99],
        ]);
        $this->assertStringContainsString('Módulos avulsos', $cards);
        $this->assertStringContainsString('>Assinar<', $cards);
        $this->assertStringContainsString('>Configurar plano<', $cards);
        $this->assertStringNotContainsString('Assinatura Personalizada', $cards);

        $this->get('https://www.fokuscloud.com.br/produtos/fokus-lead')->assertOk();
        $lead = file_get_contents(public_path('marketing/products/fokus-lead.html'));
        $this->assertStringContainsString('Em desenvolvimento', $lead);
        $this->assertStringContainsString('Um CRM imobiliário desenhado a partir da rotina de quem atende.', $lead);
        $this->assertStringContainsString('Os planos serão publicados quando o produto estiver disponível.', $lead);
        $this->assertStringContainsString('O que estamos desenhando', $lead);
        $this->assertStringNotContainsString('Entrar no Fokus Lead', $lead);
        $this->assertStringNotContainsString('decisões mais inteligentes', $lead);

        $this->get('https://www.fokuscloud.com.br/produtos/fokus-law/sob-medida')->assertOk();
        $custom = file_get_contents(public_path('marketing/products/fokus-law-sob-medida.html'));
        $this->assertStringContainsString('Solicitar proposta', $custom);
        $this->assertStringContainsString('código, dados e operação isolados', $custom);
        $this->assertStringNotContainsString('Ilhéus', $custom);
        $this->assertStringNotContainsString('Vara', $custom);

        $this->get('https://www.fokuscloud.com.br/sitemap.xml')
            ->assertSee('https://www.fokuscloud.com.br/produtos/fokus-law/sob-medida', false);
        $this->assertStringNotContainsString(
            'Assinatura Personalizada',
            file_get_contents(public_path('marketing/products/fokus-law-plans.js'))
        );
    }
}
