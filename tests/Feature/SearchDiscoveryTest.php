<?php

namespace Tests\Feature;

use Tests\TestCase;

class SearchDiscoveryTest extends TestCase
{
    public function test_each_host_exposes_only_its_own_canonical_pages(): void
    {
        foreach (['www.fokuscloud.com.br', 'styles.fokuscloud.com.br'] as $host) {
            $response = $this->get("https://{$host}/sitemap.xml")->assertOk();
            $xml = simplexml_load_string($response->getContent());
            $this->assertCount($host === 'styles.fokuscloud.com.br' ? 7 : 9, $xml->url);
            $lastmods = json_decode(file_get_contents(resource_path('seo/lastmod.json')), true, flags: JSON_THROW_ON_ERROR);
            $pages = json_decode(file_get_contents(resource_path('seo/pages.json')), true, flags: JSON_THROW_ON_ERROR);
            $filesByUrl = [];
            foreach ($pages as $page) {
                $filesByUrl[$page['url']] = $page['file'];
            }
            foreach ($xml->url as $page) {
                $loc = (string) $page->loc;
                $this->assertSame($host, parse_url($loc, PHP_URL_HOST));
                $this->assertSame($lastmods[$filesByUrl[$loc]], (string) $page->lastmod);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $page->lastmod);
                $this->get($loc)->assertOk()->assertHeaderMissing('X-Robots-Tag');
            }
            $this->assertEmpty($response->headers->getCookies());
            if ($host === 'styles.fokuscloud.com.br') {
                $response->assertSee('https://styles.fokuscloud.com.br/docs', false)
                    ->assertDontSee('/styles/next/', false);
            }
            $robots = $this->get("https://{$host}/robots.txt")->assertOk();
            $robots->assertSee("Sitemap: https://{$host}/sitemap.xml", false)
                ->assertSee('Content-Signal: search=yes,ai-input=yes,ai-train=yes', false)
                ->assertDontSee('ai-train=no', false);
            foreach (['Disallow: /api/', 'Disallow: /backoffice/', 'Disallow: /portal/', 'Disallow: /cadastro', 'Disallow: /acesso'] as $rule) {
                $robots->assertSee($rule, false);
            }
            $this->assertDoesNotMatchRegularExpression('/^Disallow: \/$/m', $robots->getContent());
            foreach (['GPTBot', 'Google-Extended', 'Amazonbot', 'CloudflareBrowserRenderingCrawler', 'ClaudeBot', 'Applebot-Extended', 'Bytespider', 'CCBot', 'meta-externalagent', 'OAI-SearchBot', 'ChatGPT-User', 'Claude-User', 'PerplexityBot'] as $agent) {
                $robots->assertDontSee("User-agent: {$agent}", false);
            }
        }
    }

    public function test_public_redirects_keep_queries_and_do_not_redirect_styles_to_cloud(): void
    {
        $this->get('https://fokuscloud.com.br/produtos?utm_source=search')->assertStatus(301)
            ->assertRedirect('https://www.fokuscloud.com.br/produtos?utm_source=search');
        $this->get('https://styles.fokuscloud.com.br/')->assertOk();
        $this->get('https://styles.fokuscloud.com.br/produtos')->assertStatus(301)
            ->assertRedirect('https://www.fokuscloud.com.br/produtos');
        $this->get('https://www.fokuscloud.com.br/sitemap-styles.xml')->assertStatus(301)
            ->assertRedirect('https://styles.fokuscloud.com.br/sitemap.xml');
        $this->get('http://fokuscloud.com.br/')->assertStatus(301)
            ->assertRedirect('https://www.fokuscloud.com.br/');
        $this->get('http://www.fokuscloud.com.br/produtos/fokus-law')->assertStatus(301)
            ->assertRedirect('https://www.fokuscloud.com.br/produtos/fokus-law');
        $this->get('http://styles.fokuscloud.com.br/docs')->assertStatus(301)
            ->assertRedirect('https://styles.fokuscloud.com.br/docs');
        $this->get('http://styles.fokuscloud.com.br/produtos/fokus-law')->assertStatus(301)
            ->assertRedirect('https://www.fokuscloud.com.br/produtos/fokus-law');
    }

    public function test_origin_redirects_use_the_canonical_https_host(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('https://www.fokuscloud.com.br/$1/', $htaccess);
        $this->assertStringContainsString('https://www.fokuscloud.com.br%{REQUEST_URI}', $htaccess);
        $this->assertStringContainsString('https://styles.fokuscloud.com.br/$1/', $htaccess);
        $nginx = file_get_contents(base_path('deploy/cloudpanel-legacy-redirects.conf'));
        $this->assertStringContainsString('absolute_redirect off;', $nginx);
        $this->assertStringContainsString('proxy_redirect http://www.fokuscloud.com.br/ https://www.fokuscloud.com.br/;', $nginx);
        $this->assertStringContainsString('location = /styles { return 301 https://www.fokuscloud.com.br/styles/; }', $nginx);
    }

    public function test_terms_and_privacy_pages_expose_their_content(): void
    {
        $this->get('https://www.fokuscloud.com.br/termos-de-uso')->assertOk();
        $this->get('https://www.fokuscloud.com.br/privacidade')->assertOk();

        $terms = file_get_contents(public_path('marketing/terms.html'));
        $privacy = file_get_contents(public_path('marketing/privacy.html'));
        $this->assertStringContainsString('Regras para usar os serviços.', $terms);
        $this->assertStringContainsString('1. Identificação do fornecedor', $terms);
        $this->assertStringContainsString('Interesse em produtos em desenvolvimento', $privacy);
        $this->assertStringContainsString('Quais dados são solicitados?', $privacy);
        $this->assertStringContainsString('legal-document-page', $terms);
        $this->assertStringContainsString('legal-document-page', $privacy);

        $styles = file_get_contents(public_path('assets/css/modules/showcase/showcase.css'));
        $this->assertStringContainsString('.showcase-page.home-page > main >', $styles);
        $this->assertStringContainsString('.showcase-page.legal-document-page .showcase-information p { color: #4f524e; }', $styles);
    }

    public function test_account_pages_are_excluded_without_blocking_public_content(): void
    {
        $this->get('https://www.fokuscloud.com.br/portal/perfil')->assertRedirect('/?acesso=cliente');
        foreach (['portal', 'cadastro', 'recuperar-senha'] as $path) {
            $this->get("https://www.fokuscloud.com.br/{$path}")->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->get('https://www.fokuscloud.com.br/backoffice/acesso')->assertNotFound();
        $this->get('https://styles.fokuscloud.com.br/does-not-exist')->assertNotFound();
    }

    public function test_static_files_cannot_override_host_specific_discovery_routes(): void
    {
        foreach (['robots.txt', 'sitemap.xml', 'sitemap-styles.xml'] as $path) {
            $this->assertFileDoesNotExist(public_path($path));
        }
    }
}
