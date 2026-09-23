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
        $this->assertStringContainsString('(() => {', $panel);
        $this->assertStringContainsString('20260923-form-edit-guards-v3', file_get_contents(base_path('public/backoffice/pages/modules.html')));
        $this->assertStringContainsString('module: "/backoffice/assets/js/modules-page.js"', file_get_contents(base_path('public/backoffice/assets/js/backoffice-router.js')));
        $this->assertStringContainsString("document.addEventListener('fs:show'", file_get_contents(base_path('public/backoffice/assets/js/records-page.js')));
        $this->assertStringContainsString('BackofficeRouter.create', $panel);
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
        $catalogScript = file_get_contents(base_path('public/backoffice/assets/js/subscription-plans-page.js'));
        $vouchers = file_get_contents(base_path('public/backoffice/pages/vouchers.html'));

        $this->assertStringContainsString('id="plan-destructive-dialog"', $catalog);
        $this->assertStringContainsString('id="voucher-destructive-dialog"', $vouchers);
        $this->assertStringContainsString('Common-File-Edit--Streamline-Ultimate.png', $catalogScript);
        $this->assertStringContainsString('Common-File-Remove--Streamline-Ultimate.png', $catalogScript);
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
        $modulePage = file_get_contents(base_path('public/backoffice/pages/modules.html'));
        $moduleScript = file_get_contents(base_path('public/backoffice/assets/js/modules-page.js'));
        $modules = $modulePage.$moduleScript;
        $productPage = file_get_contents(base_path('public/backoffice/pages/products.html'));
        $productModule = file_get_contents(base_path('public/backoffice/assets/js/products-page.js'));
        $products = $productPage.$productModule;
        $drawerStyles = file_get_contents(base_path('public/backoffice/assets/css/components/drawer-form.css'));
        $sharedStyles = file_get_contents(base_path('public/assets/css/shared/fokus.css'));

        $this->assertStringContainsString('action("publish"', $modules);
        $this->assertStringContainsString('action("activate"', $modules);
        $this->assertStringContainsString('action("pause"', $modules);
        $this->assertStringContainsString('Pausar publicação', $modules);
        $this->assertStringContainsString('File-Code-2--Streamline-Ultimate.png', $modules);
        $this->assertStringContainsString('File-Code-Subtract--Streamline-Ultimate.png', $modules);
        $this->assertStringContainsString('File-Code-Remove--Streamline-Ultimate.png', $modules);
        $this->assertStringContainsString('Common-File-Check--Streamline-Ultimate.png', $modules);
        $this->assertStringContainsString('Common-File-Remove--Streamline-Ultimate.png', $modules);
        $this->assertStringNotContainsString('Browser-Hand--Streamline-Ultimate.png', $modules);
        $this->assertStringNotContainsString('App-Window-Disable--Streamline-Ultimate.png', $modules);
        $this->assertStringNotContainsString('Zip-File-Download--Streamline-Ultimate.png', $modules);
        $this->assertStringNotContainsString('Zip-File-Upload--Streamline-Ultimate.png', $modules);
        $this->assertStringContainsString('const actions = (module)', $modules);
        $this->assertStringNotContainsString('normalizeModuleActions', $modules);
        $this->assertStringContainsString('fs-table-action', $modules);
        $this->assertStringContainsString('fs-confirmation-dialog-form', $modules);
        $this->assertStringContainsString('fs-confirmation-dialog-body', $modules);
        $this->assertStringContainsString('fs-confirmation-dialog-field', $modules);
        $this->assertStringContainsString('Personalizações e limites', $modules);
        $this->assertStringContainsString('Configurar personalizações', $modules);
        $this->assertStringNotContainsString('fs-form-col fs-form-label" for="module-dialog-reason"', $modules);
        $this->assertStringContainsString('.fs-confirmation-dialog-field .fs-form-select', $drawerStyles);
        $this->assertStringContainsString('flex: 0 0 38px;', $drawerStyles);
        $this->assertStringContainsString('.fs-confirmation-dialog .fs-modal-footer .fs-btn-danger', $drawerStyles);
        $this->assertStringContainsString('background: var(--fs-color-danger) !important;', $drawerStyles);
        $this->assertStringContainsString('.fs-table-action::before { display: none !important; content: none !important; }', $sharedStyles);
        $this->assertStringContainsString('.fs-table-action > img', $sharedStyles);
        $this->assertStringContainsString('visibility: visible !important;', $sharedStyles);
        $this->assertStringNotContainsString('data-action]::before', $drawerStyles);

        foreach (['File-Code-2--Streamline-Ultimate.png', 'File-Code-Subtract--Streamline-Ultimate.png', 'File-Code-Remove--Streamline-Ultimate.png', 'Common-File-Check--Streamline-Ultimate.png', 'Common-File-Remove--Streamline-Ultimate.png'] as $icon) {
            $this->assertFileExists(base_path("public/backoffice/assets/icons/{$icon}"), $icon);
        }

        $this->assertStringContainsString('Common-File-Subtract--Streamline-Ultimate.png', $products);
        $this->assertStringContainsString('Common-File-Remove--Streamline-Ultimate.png', $products);
        $this->assertStringContainsString('Common-File-Check--Streamline-Ultimate.png', $products);
        $this->assertStringNotContainsString('Button-Pause-1--Streamline-Ultimate.png', $products);
        $this->assertStringNotContainsString('Power-Button-1--Streamline-Ultimate.png', $products);
        $this->assertStringContainsString('action("pause"', $products);
        $this->assertStringContainsString('/pause', $products);
        $this->assertStringContainsString('status === "ativo"', $products);
        $this->assertStringContainsString('product-view-panel', $products);
        $this->assertStringContainsString('resetDrawerState', $products);
        $this->assertStringContainsString('form.elements.namedItem("code").disabled = true', $products);
        $this->assertStringContainsString('data-sidebar-item="products"', file_get_contents(base_path('public/backoffice/index.html')));
        $this->assertStringNotContainsString('product-confirm-dialog', $products);
        $this->assertStringNotContainsString('action("deactivate"', $products);
        $this->assertStringNotContainsString('/deactivate', $products);
        $this->assertStringNotContainsString('name="status"', $products);
        $this->assertStringNotContainsString('publication_state', $products);
    }

    public function test_all_backoffice_table_action_icons_use_the_shared_contract(): void
    {
        $sharedStyles = file_get_contents(base_path('public/assets/css/shared/fokus.css'));

        foreach ([
            'fs-width-100' => '38px',
            'fs-width-200' => '80px',
            'fs-width-300' => '120px',
            'fs-width-400' => '180px',
            'fs-width-500' => '240px',
            'fs-width-600' => '300px',
            'fs-width-700' => '360px',
            'fs-width-800' => '420px',
            'fs-width-900' => '480px',
        ] as $class => $width) {
            $this->assertStringContainsString(".{$class} { width: {$width}; }", $sharedStyles);
            $this->assertStringContainsString(".fs-table-records .{$class} { flex-basis: {$width}; }", $sharedStyles);
        }

        foreach (glob(base_path('public/backoffice/pages/*.html')) as $page) {
            preg_match_all('/class="([^"]*\\bfs-btn-icon\\b[^"]*)"/', file_get_contents($page), $matches);

            foreach ($matches[1] as $classes) {
                $this->assertStringContainsString('fs-btn-icon-plain', $classes, basename($page));
                $this->assertStringContainsString('fs-table-action', $classes, basename($page));
            }
        }

        $this->assertStringContainsString('.fs-table-action,', $sharedStyles);
        $this->assertStringContainsString('flex: 0 0 24px;', $sharedStyles);
        $this->assertStringContainsString('width: 20px;', $sharedStyles);
        $this->assertStringContainsString('height: 20px;', $sharedStyles);
    }

    public function test_backoffice_reset_stays_in_the_lowest_cascade_layer(): void
    {
        $reset = file_get_contents(base_path('public/backoffice/assets/css/base/reset.css'));

        $this->assertStringContainsString('@layer reset {', $reset);
        $this->assertStringContainsString('*, *::before, *::after', $reset);
    }

    public function test_catalog_uses_masked_currency_controls_and_compact_plan_checkboxes(): void
    {
        $catalog = file_get_contents(base_path('public/backoffice/pages/subscription-plans.html'));
        $catalogScript = file_get_contents(base_path('public/backoffice/assets/js/subscription-plans-page.js'));
        $css = file_get_contents(base_path('public/backoffice/assets/css/components/form-admin.css'));
        $pageCss = file_get_contents(base_path('public/backoffice/assets/css/pages/mockup.css'));

        $this->assertSame(1, substr_count($catalog, 'data-currency-input'));
        $this->assertStringContainsString('data-currency-input', $catalog);
        $this->assertStringContainsString('plan-module-checkbox', $catalogScript);
        $this->assertStringContainsString('fs-input-group-text', $catalog);
        $this->assertStringContainsString('fs-check', $catalogScript);
    }

    public function test_catalog_tables_expose_reusable_pagination_controls(): void
    {
        $catalog = file_get_contents(base_path('public/backoffice/pages/subscription-plans.html'));

        foreach (['plan-pagination', 'data-fs-page-size="15"'] as $fragment) {
            $this->assertStringContainsString($fragment, $catalog, $fragment);
        }
        $this->assertStringContainsString('data-plan-page', file_get_contents(base_path('public/backoffice/assets/js/subscription-plans-page.js')));
        $this->assertStringContainsString('const pageSize = 15', file_get_contents(base_path('public/backoffice/assets/js/subscription-plans-page.js')));
    }

    public function test_subscription_plans_are_a_dedicated_modular_records_page(): void
    {
        $page = file_get_contents(base_path('public/backoffice/pages/subscription-plans.html'));
        $script = file_get_contents(base_path('public/backoffice/assets/js/subscription-plans-page.js'));
        $router = file_get_contents(base_path('public/backoffice/assets/js/backoffice-router.js'));

        foreach (['backoffice-plans-page', 'backoffice-records-page', 'fs-card-panel', 'fs-table-records', 'fs-offcanvas', 'plan-drawer', 'plan-composition-drawer'] as $fragment) {
            $this->assertStringContainsString($fragment, $page, $fragment);
        }
        foreach (['export async function mount', 'openCreate', 'openEdit', 'openView', 'createRecordsDrawer', 'module_ids', 'personalization_defaults'] as $fragment) {
            $this->assertStringContainsString($fragment, $script, $fragment);
        }
        $this->assertStringContainsString('module: "/backoffice/assets/js/subscription-plans-page.js"', $router);
        $this->assertStringNotContainsString('<script', $page);
        $this->assertStringNotContainsString('data-catalog-tab', $page);
        $this->assertStringNotContainsString('product-pagination', $page);
        $this->assertStringNotContainsString('module-pagination', $page);
        $this->assertStringNotContainsString('publication-pagination', $page);
    }

    public function test_modules_page_uses_catalog_components_and_accessible_drawers(): void
    {
        $modulePage = file_get_contents(base_path('public/backoffice/pages/modules.html'));
        $modules = $modulePage.file_get_contents(base_path('public/backoffice/assets/js/modules-page.js'));
        $panel = file_get_contents(base_path('public/backoffice/index.html'));

        foreach (['fs-container-fluid', 'fs-card-panel', 'fs-table-responsive', 'fs-table-records', 'fs-pagination', 'fs-offcanvas', 'fs-modal', 'fs-form-control', 'aria-describedby'] as $fragment) {
            $this->assertStringContainsString($fragment, $modules, $fragment);
        }

        $this->assertStringNotContainsString('<script', $modulePage);
        $this->assertStringNotContainsString('class="form-label', $modulePage);
        $this->assertStringNotContainsString('class="input-label', $modulePage);
        $this->assertStringNotContainsString('class="create-drawer', $modulePage);

        $this->assertStringContainsString('data-sidebar-item="modules"', $panel);
        $this->assertStringContainsString('modulos: "modules"', $panel);
        $this->assertStringContainsString('modules: "modules"', $panel);
        $this->assertStringNotContainsString('disabled title="Em breve">\n                                <span class="sidebar-button-label text-subtitle-sm">Módulos e funcionalidades', $panel);
    }

    public function test_company_and_subscription_pages_follow_live_backoffice_components(): void
    {
        $pages = [
            'public/backoffice/pages/companies.html' => 'backoffice-companies-page',
            'public/backoffice/pages/products.html' => 'backoffice-products-page',
            'public/backoffice/pages/modules.html' => 'backoffice-modules-page',
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
        $productSource = $products.file_get_contents(base_path('public/backoffice/assets/js/products-page.js'));
        $subscriptions = file_get_contents(base_path('public/backoffice/pages/subscriptions.html'));
        $this->assertStringContainsString('fs-card-title">Dados da empresa', $companies);
        $this->assertStringContainsString('fs-card-title">Administrador responsável', $companies);
        $this->assertStringContainsString('fs-badge', $companies);
        $this->assertStringContainsString('fs-offcanvas', $companies);
        $this->assertStringContainsString('backoffice-records-page', $products);
        $this->assertStringContainsString('fs-filter-form', $products);
        $this->assertStringContainsString('fs-table-records', $products);
        $this->assertStringContainsString('fs-pagination', $productSource);
        $this->assertStringContainsString('fs-card fs-card-panel', $products);
        $this->assertStringNotContainsString('<script', $products);
        $this->assertStringNotContainsString('class="form-label', $products);
        $this->assertStringNotContainsString('class="input-label', $products);
        $this->assertStringNotContainsString('product-confirm-dialog', $products);
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
