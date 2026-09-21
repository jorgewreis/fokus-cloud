<?php

namespace Tests\Feature;

use Tests\TestCase;

class FormDesignSystemTest extends TestCase
{
    public function test_backoffice_forms_use_the_shared_form_contract(): void
    {
        $pages = [
            'public/backoffice/ativar.html',
            'public/backoffice/pages/security.html',
            'public/backoffice/pages/subscription-plans.html',
            'public/backoffice/pages/companies.html',
            'public/backoffice/pages/modules.html',
            'public/backoffice/pages/subscriptions.html',
            'public/backoffice/pages/vouchers.html',
        ];

        foreach ($pages as $page) {
            $contents = file_get_contents(base_path($page));

            $this->assertNotFalse($contents, $page);
            $this->assertStringContainsString('fs-form', $contents, $page);
            $this->assertStringNotContainsString('<style', $contents, $page);
            $this->assertStringNotContainsString('style=', $contents, $page);
        }
    }

    public function test_form_system_documents_all_required_components(): void
    {
        $css = file_get_contents(base_path('public/backoffice/assets/css/components/form-admin.css'));
        $documentation = file_get_contents(base_path('docs/03-architecture/form-design-system.md'));
        $script = file_get_contents(base_path('public/backoffice/assets/js/form-system.js'));

        foreach (['form-error-summary', 'form-file', 'input-group', 'form-range', 'dialog-panel', 'is-loading'] as $component) {
            $this->assertStringContainsString($component, $css, $component);
        }

        foreach (['FokusForm.validate', 'FokusForm.mapServerErrors', 'FokusForm.setLoading', 'Checklist de contrato'] as $contract) {
            $this->assertTrue(str_contains($script, $contract) || str_contains($documentation, $contract), $contract);
        }
    }

    public function test_backoffice_required_fields_are_auto_marked_and_cache_busted(): void
    {
        $panel = file_get_contents(base_path('public/backoffice/index.html'));
        $home = file_get_contents(base_path('public/index.html'));
        $activate = file_get_contents(base_path('public/backoffice/ativar.html'));
        $css = file_get_contents(base_path('public/backoffice/assets/css/components/form-admin.css'));
        $script = file_get_contents(base_path('public/backoffice/assets/js/form-system.js'));

        $this->assertMatchesRegularExpression('/const pageVersion = "20\\d{6}-[a-z0-9-]+";/', $panel);
        $this->assertStringContainsString('records-page.js?v=20260920-records-overlay-v3', $panel);
        $this->assertStringContainsString('window.initBackofficeRecordsPage?.(root)', file_get_contents(base_path('public/backoffice/pages/modules.html')));
        $this->assertStringContainsString("document.addEventListener('fs:show'", file_get_contents(base_path('public/backoffice/assets/js/records-page.js')));
        $this->assertStringContainsString('] || "companies",', $panel);
        $this->assertStringContainsString('data-sidebar-item="companies"', $panel);
        $this->assertStringContainsString('data-platform-access-card', $home);
        $this->assertStringContainsString('platform-access.js?v=20260914-platform-access1', $home);
        $this->assertStringContainsString('20260916-fokus-styles-auth-shell', $activate);

        $this->assertStringContainsString('MutationObserver', $script);
        $this->assertStringContainsString('markRequiredFields', $script);
        $this->assertStringContainsString('fs-invalid-feedback', $script);
        $this->assertStringContainsString('fs-form-control', file_get_contents(base_path('public/assets/css/shared/fokus.css')));
    }

    public function test_admin_invite_form_does_not_reuse_sidebar_admin_id(): void
    {
        $panel = file_get_contents(base_path('public/backoffice/index.html'));
        $security = file_get_contents(base_path('public/backoffice/pages/security.html'));

        $this->assertStringNotContainsString('id="admin-name"', $panel);
        $this->assertStringNotContainsString('id="admin-name"', $security);
        $this->assertStringContainsString('id="invite-admin-name"', $security);
    }

    public function test_catalog_and_voucher_actions_use_accessible_dialogs_and_shared_icons(): void
    {
        $catalog = file_get_contents(base_path('public/backoffice/pages/subscription-plans.html'));
        $vouchers = file_get_contents(base_path('public/backoffice/pages/vouchers.html'));

        $this->assertStringContainsString('id="catalog-destructive-dialog"', $catalog);
        $this->assertStringContainsString('id="voucher-destructive-dialog"', $vouchers);
        $this->assertStringContainsString('Shopping-Basket-Edit--Streamline-Ultimate.png', $catalog);
        $this->assertStringContainsString('Shopping-Basket-Subtract--Streamline-Ultimate.png', $catalog);
        foreach (['Tags-Add--Streamline-Ultimate.png', 'Ticket-Exchange--Streamline-Ultimate.png', 'Tags-Minus--Streamline-Ultimate.png', 'Tags-Remove--Streamline-Ultimate.png'] as $icon) {
            $this->assertStringContainsString($icon, $vouchers, $icon);
        }
        $this->assertStringContainsString('data-voucher-action="remove-or-archive"', $vouchers);
        $this->assertStringNotContainsString('data-voucher-action="archive"', $vouchers);
        $this->assertStringNotContainsString('data-voucher-action="delete"', $vouchers);
        $this->assertStringNotContainsString('window.confirm', $catalog.$vouchers);
        $this->assertStringNotContainsString('prompt(', $catalog.$vouchers);
    }

    public function test_module_actions_use_publication_semantics_and_assets(): void
    {
        $modules = file_get_contents(base_path('public/backoffice/pages/modules.html'));
        $drawerStyles = file_get_contents(base_path('public/backoffice/assets/css/components/drawer-form.css'));

        $this->assertStringContainsString("action('publish'", $modules);
        $this->assertStringContainsString("action('pause'", $modules);
        $this->assertStringContainsString('Pausar publicação', $modules);
        $this->assertStringContainsString('Common-File-Check--Streamline-Ultimate.png', $modules);
        $this->assertStringContainsString('App-Window-Disable--Streamline-Ultimate.png', $modules);
        $this->assertStringNotContainsString("action('activate'", $modules);
        $this->assertStringNotContainsString('Zip-File-Download--Streamline-Ultimate.png', $modules);
        $this->assertStringContainsString('data-action="publish"', $drawerStyles);
        $this->assertStringContainsString('App-Window-Disable--Streamline-Ultimate.png', $drawerStyles);
        $this->assertStringContainsString('.admin-modules-page [data-action="publish"]::before { background-image:', $drawerStyles);
    }

    public function test_catalog_uses_masked_currency_controls_and_compact_plan_checkboxes(): void
    {
        $catalog = file_get_contents(base_path('public/backoffice/pages/subscription-plans.html'));
        $css = file_get_contents(base_path('public/backoffice/assets/css/components/form-admin.css'));
        $pageCss = file_get_contents(base_path('public/backoffice/assets/css/pages/mockup.css'));

        $this->assertSame(2, substr_count($catalog, 'data-currency-input'));
        $this->assertStringContainsString('plan-module-checkbox', $catalog);
        $this->assertStringContainsString('fs-input-group-text', $catalog);
        $this->assertStringContainsString('fs-check-label', $catalog);
    }

    public function test_catalog_tables_expose_reusable_pagination_controls(): void
    {
        $catalog = file_get_contents(base_path('public/backoffice/pages/subscription-plans.html'));

        foreach (['product-pagination', 'module-pagination', 'plan-pagination', 'publication-pagination', 'data-catalog-page', 'pageSize = 20'] as $fragment) {
            $this->assertStringContainsString($fragment, $catalog, $fragment);
        }
    }

    public function test_modules_page_uses_catalog_components_and_accessible_drawers(): void
    {
        $modules = file_get_contents(base_path('public/backoffice/pages/modules.html'));
        $panel = file_get_contents(base_path('public/backoffice/index.html'));

        foreach (['fs-container-fluid', 'fs-card-panel', 'fs-table-responsive', 'fs-table-records', 'fs-pagination', 'fs-offcanvas', 'fs-modal', 'fs-form-control', 'aria-describedby'] as $fragment) {
            $this->assertStringContainsString($fragment, $modules, $fragment);
        }

        $this->assertStringContainsString('data-sidebar-item="modules"', $panel);
        $this->assertStringContainsString('modulos: "modules"', $panel);
        $this->assertStringContainsString('modules: "modules"', $panel);
        $this->assertStringNotContainsString('disabled title="Em breve">\n                                <span class="sidebar-button-label text-subtitle-sm">Módulos e funcionalidades', $panel);
    }

    public function test_company_and_subscription_pages_follow_live_backoffice_components(): void
    {
        $pages = [
            'public/backoffice/pages/companies.html' => 'admin-company-page',
            'public/backoffice/pages/subscriptions.html' => 'subscription-page',
            'public/backoffice/pages/pagamentos.html' => 'pagamentos-page',
        ];

        foreach ($pages as $page => $rootClass) {
            $contents = file_get_contents(base_path($page));

            $this->assertStringContainsString('fs-container-fluid', $contents, $page);
            $this->assertStringContainsString('class="'.$rootClass, $contents, $page);
            $this->assertStringContainsString('fs-table-responsive', $contents, $page);
            $this->assertStringContainsString('fs-table', $contents, $page);
            $this->assertStringContainsString('fs-btn', $contents, $page);
            $this->assertStringNotContainsString('class="btn', $contents, $page);
            $this->assertStringNotContainsString('table-container', $contents, $page);
        }

        $companies = file_get_contents(base_path('public/backoffice/pages/companies.html'));
        $products = file_get_contents(base_path('public/backoffice/pages/products.html'));
        $subscriptions = file_get_contents(base_path('public/backoffice/pages/subscriptions.html'));
        $this->assertStringContainsString('fs-card-title">Dados da empresa', $companies);
        $this->assertStringContainsString('fs-card-title">Administrador responsável', $companies);
        $this->assertStringContainsString('fs-badge', $companies);
        $this->assertStringContainsString('fs-offcanvas', $companies);
        $this->assertStringContainsString('backoffice-records-page', $products);
        $this->assertStringContainsString('product-destructive-dialog', $products);
        $this->assertStringNotContainsString('window.confirm', $products);
        $this->assertStringContainsString('card-body', $subscriptions);
        $this->assertStringContainsString('cancelamento_imediato', $subscriptions);

        $this->assertStringNotContainsString('admin-company-page {', file_get_contents(base_path('public/backoffice/assets/css/pages/admin-dashboard.css')));
        $this->assertFileDoesNotExist(base_path('public/backoffice/assets/css/pages/admin-products.css'));
    }

    public function test_payments_deep_links_require_backoffice_session(): void
    {
        $this->get('/backoffice/pagamentos')->assertRedirect('/?acesso=administrativo');
        $this->get('/backoffice/billing')->assertRedirect('/?acesso=administrativo');
    }

    public function test_public_products_index_lists_the_portfolio(): void
    {
        $index = file_get_contents(base_path('public/marketing/products/index.html'));

        $this->assertStringContainsString('/produtos/fokus-styles', $index);
        $this->assertStringContainsString('Fokus Law', $index);
        $this->assertStringContainsString('Fokus Lead', $index);
        $this->assertSame(0, substr_count($index, 'Em breve'));
        $this->assertStringContainsString('/produtos/fokus-law', $index);
        $this->assertStringContainsString('/produtos/fokus-lead', $index);
    }

    public function test_styles_layout_documentation_is_complete_and_uses_official_layout_classes(): void
    {
        $page = file_get_contents(base_path('public/styles/docs/layout/index.html'));
        $script = file_get_contents(base_path('public/assets/js/styles-layout-doc.js'));

        $this->assertStringContainsString('Layout', $page);
        $this->assertStringContainsString('fs-container', $page);
        $this->assertStringContainsString('fs-row', $page);
        $this->assertStringContainsString('fs-stack', $page);
        $this->assertStringContainsString('Use assim', $page);
        $this->assertStringContainsString('Evite assim', $page);
        $this->assertStringContainsString('data-copy-target', $page);
        $this->assertStringContainsString('IntersectionObserver', $script);
    }

    public function test_styles_home_links_to_available_layout_documentation(): void
    {
        $home = file_get_contents(base_path('public/styles/index.html'));

        $this->assertStringContainsString('href="/layout">Layout</a>', $home);
        $this->assertStringContainsString('href="/forms">Forms</a>', $home);
        $this->assertStringContainsString('href="/components">Components</a>', $home);
        $this->assertStringContainsString('href="/helpers">Helpers</a>', $home);
        $this->assertStringContainsString('href="/utilities">Utilities</a>', $home);
        $this->assertSame(0, substr_count($home, 'class="styles-sidebar-planned"'));
        $this->assertStringNotContainsString('href="#layout"', $home);
    }

    public function test_styles_forms_documentation_covers_semantic_fields_and_validation(): void
    {
        $page = file_get_contents(base_path('public/styles/docs/forms/index.html'));

        foreach (['fs-form-control', 'fs-form-select', 'fs-form-fieldset', 'fs-form-label', 'fs-check', 'fs-radio', 'fs-invalid-feedback', 'fs-valid-feedback', 'aria-invalid="true"', 'required', 'Use assim', 'Evite assim', 'data-copy-target'] as $fragment) {
            $this->assertStringContainsString($fragment, $page, $fragment);
        }
    }

    public function test_portal_index_redirects_to_the_user_dashboard(): void
    {
        $index = file_get_contents(base_path('public/portal/index.html'));

        $this->assertStringContainsString('/portal/dashboard.html', $index);
        $this->assertStringContainsString('noindex', $index);
    }
}
