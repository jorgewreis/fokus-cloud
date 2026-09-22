<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackofficeUiContractTest extends TestCase
{
    public function test_backoffice_uses_a_single_router_and_page_lifecycle_contract(): void
    {
        $panel = file_get_contents(base_path('public/backoffice/index.html'));
        $router = file_get_contents(base_path('public/backoffice/assets/js/backoffice-router.js'));
        $records = file_get_contents(base_path('public/backoffice/assets/js/records-page.js'));

        $this->assertStringContainsString('backoffice-router.js', $panel);
        $this->assertStringContainsString('__backofficeBootstrapResponse', $panel);
        $this->assertSame(1, substr_count($panel, '/backoffice/auth/me'));
        $this->assertStringContainsString('AbortController', $router);
        $this->assertStringContainsString('mount(pageId, page, markup, signal)', $router);
        $this->assertStringContainsString('disposeBackofficeRecordsPage', $router);
        $this->assertStringContainsString('backofficeOwner', $records);
    }

    public function test_shared_contract_and_record_template_are_present(): void
    {
        $sync = file_get_contents(base_path('tools/sync-fokus-styles.mjs'));
        $template = file_get_contents(base_path('docs/templates/backoffice-record-page.html'));

        foreach (['immutableBackofficeContract', 'fs-backoffice-panel-space', 'data-backoffice-overlay="personalizations"'] as $contract) {
            $this->assertStringContainsString($contract, $sync);
        }

        foreach (['fs-page-layout', 'fs-card fs-card-panel', 'fs-filter-form', 'fs-table fs-table-records', 'backoffice-records-drawer'] as $contract) {
            $this->assertStringContainsString($contract, $template);
        }
    }

    public function test_reference_pages_keep_the_shared_record_anatomy(): void
    {
        foreach (['companies', 'products', 'modules'] as $page) {
            $contents = file_get_contents(base_path("public/backoffice/pages/{$page}.html"));
            $this->assertStringContainsString('backoffice-records-page', $contents, $page);
            $this->assertStringContainsString('fs-page-layout', $contents, $page);
            $this->assertStringContainsString('fs-card-panel', $contents, $page);
            $this->assertStringContainsString('fs-table-records', $contents, $page);
            $this->assertStringContainsString('backoffice-records-drawer', $contents, $page);
        }
    }
}
