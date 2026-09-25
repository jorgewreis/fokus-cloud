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
            $this->assertCount($host === 'styles.fokuscloud.com.br' ? 6 : 6, $xml->url);
            foreach ($xml->url as $page) {
                $this->assertSame($host, parse_url((string) $page->loc, PHP_URL_HOST));
                $this->get((string) $page->loc)->assertOk()->assertHeaderMissing('X-Robots-Tag');
            }
            $this->assertEmpty($response->headers->getCookies());
            $this->get("https://{$host}/robots.txt")->assertOk()
                ->assertSee("Sitemap: https://{$host}/sitemap.xml", false);
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
