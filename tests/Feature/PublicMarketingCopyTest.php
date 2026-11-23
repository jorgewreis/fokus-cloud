<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicMarketingCopyTest extends TestCase
{
    public function test_public_pages_follow_the_approved_marketing_copy(): void
    {
        $home = $this->get('https://www.fokuscloud.com.br/')->assertOk();
        $home->assertSee('Produtos digitais para rotinas que precisam de ordem.', false);
        $home->assertSee('Em desenvolvimento', false);
        $home->assertSee('Conhecer o Fokus Law', false);
        $home->assertDontSee('Mais liberdade para grandes conquistas', false);

        $law = $this->get('https://www.fokuscloud.com.br/produtos/fokus-law')->assertOk();
        $law->assertSee('Seus contatos e processos, no mesmo lugar.', false);
        $law->assertSee('Fokus Law organiza contatos e processos de escritórios de advocacia e unidades do poder público, com controle de acesso e histórico auditável.', false);
        $law->assertSee('Integração com o Datajud.', false);
        $law->assertSee('Arquivamento de processos.', false);
        $law->assertSee('Fokus Law sob medida', false);
        $law->assertDontSee('78%', false);
        $law->assertDontSee('Pesquisa, indicadores', false);

        $plans = $this->get('https://www.fokuscloud.com.br/produtos/fokus-law/planos')->assertOk();
        $plans->assertSee('Escolha o plano para a sua operação.', false);
        $plans->assertSee('Módulos avulsos', false);
        $plans->assertDontSee('Assinatura Personalizada', false);
        $plans->assertDontSee('Conecte processos, pessoas, prazos e atividades', false);

        $lead = $this->get('https://www.fokuscloud.com.br/produtos/fokus-lead')->assertOk();
        $lead->assertSee('Em desenvolvimento', false);
        $lead->assertSee('Um CRM imobiliário desenhado a partir da rotina de quem atende.', false);
        $lead->assertSee('Os planos serão publicados quando o produto estiver disponível.', false);
        $lead->assertSee('O que estamos desenhando', false);
        $lead->assertDontSee('Entrar no Fokus Lead', false);
        $lead->assertDontSee('decisões mais inteligentes', false);

        $custom = $this->get('https://www.fokuscloud.com.br/produtos/fokus-law/sob-medida')->assertOk();
        $custom->assertSee('Solicitar proposta', false);
        $custom->assertSee('código, dados e operação isolados', false);
        $custom->assertDontSee('Ilhéus', false);
        $custom->assertDontSee('Vara', false);

        $this->get('https://www.fokuscloud.com.br/sitemap.xml')
            ->assertSee('https://www.fokuscloud.com.br/produtos/fokus-law/sob-medida', false);

        $this->assertStringNotContainsString(
            'Assinatura Personalizada',
            file_get_contents(public_path('marketing/products/fokus-law-plans.js'))
        );
    }
}
