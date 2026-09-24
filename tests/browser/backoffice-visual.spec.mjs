import { test, expect } from '@playwright/test';

const viewports = [
    ['desktop', { width: 1440, height: 900 }],
    ['notebook', { width: 1024, height: 768 }],
    ['tablet', { width: 768, height: 1024 }],
    ['mobile', { width: 375, height: 812 }],
    ['mobile-narrow', { width: 320, height: 700 }],
];

const useSubscriptionCatalog = async (page) => {
    await page.route('**/api/backoffice/catalog', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
            products: [
                { id: 'PRD_LAW', name: 'Fokus Law', code: 'law', plans: [{ id: 'PLN_1', name: 'Advocacia', full_name: 'Fokus Law - Advocacia' }] },
                { id: 'PRD_LEAD', name: 'Fokus Lead', code: 'lead', plans: [{ id: 'PLN_LEAD', name: 'Essencial', full_name: 'Fokus Lead - Essencial' }] },
            ],
            options: { segments: {}, module_codes: [], personalization_types: [] },
        }),
    }));
};

for (const [name, viewport] of viewports) {
    for (const [route, pageId] of [['empresas', 'companies'], ['produtos', 'products'], ['modulos', 'modules']]) {
        test(`visual ${pageId} ${name}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await page.goto(`/backoffice/${route}`);
            await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', pageId);
            await expect(page.locator('.backoffice-records-page')).toBeVisible();
            await expect(page).toHaveScreenshot(`${pageId}-${name}.png`, {
                fullPage: true,
                animations: 'disabled',
                // The same Chromium revision rasterizes the bundled webfonts
                // differently on Windows and Linux. Keep the baseline strict
                // enough to flag layout/color regressions while tolerating
                // cross-platform glyph antialiasing.
                maxDiffPixelRatio: 0.08,
            });
        });
    }
}

test('navegação cancela a página anterior e mantém somente um drawer portaled', async ({ page }) => {
    await page.goto('/backoffice/produtos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'products');
    await expect(page.locator('body > #product-drawer')).toHaveCount(1);
    await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'companies');
    await expect(page.locator('body > #product-drawer')).toHaveCount(0);
    await expect(page.locator('body > #company-drawer')).toHaveCount(1);
});

test('visão geral do catálogo resume estados, pendências e as cinco publicações recentes', async ({ page }) => {
    const publications = Array.from({ length: 6 }, (_, index) => ({
        id: `PUB_${index + 1}`,
        product_id: 'PRD_LAW',
        product_name: 'Fokus Law',
        version: 6 - index,
        reason: `Publicação ${6 - index}`,
        published_at: `2026-09-${String(24 - index).padStart(2, '0')}T12:00:00.000Z`,
        published_by: 'Administração Fokus',
    }));
    await page.route('**/api/backoffice/catalog', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
            products: [
                { id: 'PRD_LAW', name: 'Fokus Law', code: 'law', status: 'ativo', publication_pending: true, published_catalog_version: 5, modules: [{ id: 'MOD_1', status: 'ativo' }, { id: 'MOD_2', status: 'pausado' }], plans: [{ id: 'PLN_1', status: 'ativo' }, { id: 'PLN_2', status: 'rascunho' }] },
                { id: 'PRD_LEAD', name: 'Fokus Lead', code: 'lead', status: 'ativo', publication_pending: false, published_catalog_version: 0, modules: [], plans: [] },
                { id: 'PRD_OLD', name: 'Produto pausado', code: 'old', status: 'pausado', publication_pending: false, published_catalog_version: 2, modules: [], plans: [] },
            ],
            publications,
        }),
    }));

    for (const viewport of [{ width: 1440, height: 900 }, { width: 768, height: 1024 }, { width: 375, height: 812 }, { width: 320, height: 700 }]) {
        await page.setViewportSize(viewport);
        await page.goto('/backoffice/visao-geral-catalogo');
        await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'catalog-overview');
        await expect(page.locator('#catalog-overview-content')).toBeVisible();
        await expect(page.locator('#catalog-overview-title')).toHaveText('Visao geral');
        await expect(page.locator('#catalog-products-total')).toHaveText('3');
        await expect(page.locator('#catalog-products-statuses')).toContainText('Ativo: 2');
        await expect(page.locator('#catalog-modules-total')).toHaveText('2');
        await expect(page.locator('#catalog-modules-statuses')).toContainText('Pausado: 1');
        await expect(page.locator('#catalog-plans-statuses')).toContainText('Rascunho: 1');
        await expect(page.locator('#catalog-pending-count')).toHaveText('2 pendentes');
        await expect(page.locator('#catalog-pending-list tr')).toHaveCount(3);
        await expect(page.locator('#catalog-pending-list tr').first()).toContainText('Fokus Law');
        await expect(page.locator('#catalog-pending-list')).toContainText('Publicado');
        await expect(page.locator('#catalog-history-list tr')).toHaveCount(5);
        await expect(page.locator('#catalog-history-list')).toContainText('Publicação 6');
        await expect(page.locator('#catalog-history-list')).not.toContainText('Publicação 1');
        const layout = await page.evaluate(() => {
            const metrics = document.querySelector('.catalog-overview-metrics').getBoundingClientRect();
            const section = document.querySelector('.catalog-overview-section').getBoundingClientRect();
            const table = document.querySelector('.catalog-overview-section .fs-table');
            const header = [...table.querySelectorAll('thead th')].map((cell) => cell.getBoundingClientRect());
            const firstRow = [...table.querySelector('tbody tr').querySelectorAll('td')].map((cell) => cell.getBoundingClientRect());
            return {
                metricSectionWidthDifference: Math.abs(metrics.width - section.width),
                columnStartDifferences: header.map((cell, index) => Math.abs(cell.x - firstRow[index].x)),
                columnWidthDifferences: header.map((cell, index) => Math.abs(cell.width - firstRow[index].width)),
                title: document.querySelector('#catalog-overview-title').textContent.trim(),
            };
        });
        expect(layout.metricSectionWidthDifference).toBeLessThanOrEqual(1);
        expect(layout.columnStartDifferences.every((difference) => difference <= 1)).toBe(true);
        expect(layout.columnWidthDifferences.every((difference) => difference <= 1)).toBe(true);
        expect(layout.title).toBe('Visao geral');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }

    await page.locator('#catalog-pending-list').getByRole('button', { name: /Gerenciar produto Fokus Law/ }).click();
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'products');
});

test('visão geral do catálogo apresenta estados vazios e permite tentar novamente após erro', async ({ page }) => {
    let fail = true;
    await page.route('**/api/backoffice/catalog', (route) => {
        if (fail) return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Catálogo indisponível.' }) });
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ products: [], publications: [] }) });
    });
    await page.goto('/backoffice/visao-geral-catalogo');
    await expect(page.locator('#catalog-overview-error')).toBeVisible();
    await expect(page.locator('#catalog-overview-error-message')).toContainText('Catálogo indisponível');
    fail = false;
    const retry = page.getByRole('button', { name: 'Tentar novamente' });
    await retry.focus();
    await retry.press('Enter');
    await expect(page.locator('#catalog-overview-content')).toBeVisible();
    await expect(page.locator('#catalog-products-total')).toHaveText('0');
    await expect(page.locator('#catalog-pending-empty')).toBeVisible();
    await expect(page.locator('#catalog-history-empty')).toBeVisible();
});

test('Assinaturas filtra por empresa, produto e status e consulta detalhes em drawer responsivo', async ({ page }) => {
    for (const [name, viewport] of [['desktop', { width: 1440, height: 900 }], ['tablet', { width: 768, height: 1024 }], ['mobile', { width: 375, height: 812 }]]) {
        await page.setViewportSize(viewport);
        await useSubscriptionCatalog(page);
        await page.goto('/backoffice/assinaturas');
        await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'subscriptions');
        await expect(page.locator('#subscription-list tr')).toHaveCount(15);
        await expect(page.locator('#subscription-list .fs-table-action img[src*="Folder-File--Streamline-Ultimate.png"]')).toHaveCount(15);
        await expect(page.locator('#subscription-product option')).toHaveCount(3);
        await expect(page).toHaveScreenshot(`subscriptions-${name}.png`, { fullPage: true, animations: 'disabled', maxDiffPixelRatio: 0.08 });

        await page.getByRole('button', { name: 'Próxima página' }).click();
        await expect(page.locator('#subscription-list tr')).toHaveCount(2);
        await expect(page.locator('#subscription-table-footer-summary')).toContainText('Página 2 de 2');
        await page.getByRole('button', { name: 'Página anterior' }).click();
        await expect(page.locator('#subscription-list tr')).toHaveCount(15);

        await page.getByLabel('Empresa ou produto').fill('Demonstração');
        await page.locator('#subscription-product').selectOption('PRD_LAW');
        await page.locator('#subscription-status').selectOption('ativa');
        await page.getByRole('button', { name: 'Filtrar' }).click();
        await expect(page.locator('#subscription-list tr')).toHaveCount(1);
        await expect(page.locator('#subscription-list')).toContainText('Empresa de Demonstração');
        await expect(page.locator('#subscription-pagination .fs-page-item.is-active')).toContainText('1');
        await expect(page.getByRole('button', { name: 'Página anterior' })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Próxima página' })).toBeDisabled();
        await expect(page).toHaveURL(/q=Demonstra%C3%A7%C3%A3o/);
        await expect(page).toHaveURL(/status=ativa/);
        await expect(page).toHaveURL(/product_id=PRD_LAW/);

        await page.getByRole('button', { name: /Ver detalhes da assinatura/ }).click();
        await expect(page.locator('#subscription-drawer')).toBeVisible();
        await expect(page.locator('#subscription-drawer-title')).toHaveText('Detalhes da assinatura');
        await expect(page.locator('#subscription-detail-payments')).toContainText('Pago');
        await expect(page.locator('#subscription-detail-items')).toContainText('Gestão de processos');
        await expect(page.locator('#subscription-detail-history')).toContainText('Atualização do contrato');
        await expect(page.locator('#subscription-detail-history')).toContainText('Consultar snapshots comerciais');
        await expect(page.locator('#subscription-override-option')).toBeHidden();
        await expect(page).toHaveScreenshot(`subscriptions-drawer-${name}.png`, { fullPage: true, animations: 'disabled', maxDiffPixelRatio: 0.08 });

        const drawerMetrics = await page.locator('#subscription-drawer').evaluate((drawer) => ({
            width: Number.parseFloat(getComputedStyle(drawer).width),
            footerVisible: (() => {
                const footer = drawer.querySelector(':scope > .fs-offcanvas-footer').getBoundingClientRect();
                return footer.bottom <= window.innerHeight && footer.top >= 0;
            })(),
        }));
        expect(drawerMetrics.width).toBe(Math.min(480, viewport.width - 16));
        expect(drawerMetrics.footerVisible).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

        await page.keyboard.press('Escape');
        await expect(page.locator('#subscription-drawer')).toBeHidden();
        await expect(page.getByRole('button', { name: /Ver detalhes da assinatura/ })).toBeFocused();
    }
});

test('drawer de assinaturas organiza dados longos e mostra vazios sem pagamentos ou itens', async ({ page }) => {
    await useSubscriptionCatalog(page);
    await page.route('**/api/backoffice/subscriptions/SUB_VISUAL_01', async (route) => {
        const response = await route.fetch();
        const subscription = await response.json();
        await route.fulfill({ response, json: {
            ...subscription,
            items: [{ ...subscription.items[0], name: 'Item de assinatura com nome extenso para conferir a quebra de linha em painéis estreitos', conditions: { ...subscription.items[0].conditions, personalizations: { 'gestao-contatos': { value: 1, additional_monthly_amount: 4.49 } }, personalization_delta: 0, long_description: 'Descrição complementar extensa para verificar leitura e ausência de overflow horizontal dentro do drawer.' } }],
            payments: [],
            history: Array.from({ length: 24 }, (_, index) => ({ ...subscription.history[0], id: `CHG_LONG_${index}`, reason: `Registro de histórico comercial número ${index + 1} com observações de atendimento e motivo detalhado.`, created_at: `2026-08-${String((index % 28) + 1).padStart(2, '0')}T12:00:00.000Z` })),
        } });
    });
    await page.route('**/api/backoffice/subscriptions/SUB_VISUAL_02', async (route) => {
        const response = await route.fetch();
        const subscription = await response.json();
        await route.fulfill({ response, json: { ...subscription, items: [], payments: [], history: [] } });
    });
    await page.goto('/backoffice/assinaturas');
    await page.getByRole('button', { name: /Ver detalhes da assinatura/ }).first().click();
    await expect(page.locator('#subscription-drawer')).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.activeElement?.closest('#subscription-drawer')?.id || '')).toBe('subscription-drawer');
    await expect(page.locator('#subscription-detail-items')).toContainText('Item de assinatura com nome extenso');
    await expect(page.locator('#subscription-detail-items')).toContainText('gestao-contatos: 1 · R$ 4,49/mês');
    await expect(page.locator('#subscription-detail-items')).toContainText('R$ 0,00');
    await expect(page.locator('#subscription-detail-items')).not.toContainText('[object Object]');
    await expect(page.locator('#subscription-detail-payments')).toContainText('Nenhum pagamento vinculado');
    await expect(page.locator('#subscription-detail-history article')).toHaveCount(24);
    expect(await page.locator('#subscription-drawer').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
    await page.locator('#subscription-drawer-close').click();
    await expect(page.getByRole('button', { name: /Ver detalhes da assinatura/ }).first()).toBeFocused();

    await page.getByRole('button', { name: /Ver detalhes da assinatura/ }).nth(1).click();
    await expect(page.locator('#subscription-detail-items')).toContainText('Nenhum item contratado');
    await expect(page.locator('#subscription-detail-payments')).toContainText('Nenhum pagamento vinculado');
    await expect(page.locator('#subscription-detail-history')).toContainText('Nenhuma alteração comercial registrada');
});

test('Backoffice gera checkout assistido e atualiza a listagem', async ({ page }) => {
    await useSubscriptionCatalog(page);
    await page.route('**/api/backoffice/subscriptions/checkout-options?**', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
            companies: [{ id: 'COM_ALPHA', legal_name: 'Empresa Alpha' }],
            products: [{ code: 'law', name: 'Fokus Law', plans: [{ code: 'law-advocacia', name: 'Advocacia', monthly_amount: 64.7, annual_amount: 647 }] }],
        }),
    }));
    let checkoutBody;
    await page.route('**/api/backoffice/subscriptions/checkout', (route) => {
        checkoutBody = route.request().postDataJSON();
        return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ subscription_id: 'ASS_NEW', checkout_url: 'https://mercadopago.test/checkout', amount: 64.7 }) });
    });
    await page.goto('/backoffice/assinaturas');
    await page.getByRole('button', { name: 'Nova assinatura' }).click();
    await expect(page.locator('#subscription-create-drawer')).toBeVisible();
    await page.locator('#subscription-create-company').selectOption('COM_ALPHA');
    await page.locator('#subscription-create-product').selectOption('law');
    await page.locator('#subscription-create-plan').selectOption('law-advocacia');
    await expect(page.locator('#subscription-create-amount')).toContainText('R$ 64,70');
    await page.getByRole('button', { name: 'Gerar checkout' }).click();
    await expect(page.locator('#subscription-create-success')).toContainText('ASS_NEW');
    await expect(page.getByRole('link', { name: 'Abrir checkout do Mercado Pago' })).toHaveAttribute('href', 'https://mercadopago.test/checkout');
    expect(checkoutBody).toEqual({ company_id: 'COM_ALPHA', product_code: 'law', plan_code: 'law-advocacia', cycle: 'monthly' });
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Nova assinatura' })).toBeFocused();
});

test('checkout assistido segue o drawer de registros em desktop e mobile', async ({ page }) => {
    for (const [name, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 375, height: 812 }]]) {
        await page.setViewportSize(viewport);
        await page.route('**/api/backoffice/subscriptions/checkout-options?**', (route) => route.fulfill({
            contentType: 'application/json', body: JSON.stringify({ companies: [{ id: 'COM_ALPHA', legal_name: 'Empresa Alpha' }], products: [{ code: 'law', name: 'Fokus Law', plans: [{ code: 'law-advocacia', name: 'Advocacia', monthly_amount: 64.7, annual_amount: 647 }] }] }),
        }));
        await page.goto('/backoffice/assinaturas');
        await page.getByRole('button', { name: 'Nova assinatura' }).click();
        await expect(page.locator('#subscription-create-drawer')).toBeVisible();
        await expect(page.locator('#subscription-create-company option')).toHaveCount(2);
        await page.locator('#subscription-create-product').selectOption('law');
        await page.locator('#subscription-create-plan').selectOption('law-advocacia');
        await expect(page).toHaveScreenshot(`subscriptions-create-${name}.png`, { fullPage: true, animations: 'disabled', maxDiffPixelRatio: 0.08 });
        expect(await page.locator('#subscription-create-drawer').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
        await page.keyboard.press('Escape');
        await expect(page.getByRole('button', { name: 'Nova assinatura' })).toBeFocused();
    }
});

test('assinatura pendente pode ser ativada por voucher gratuito no drawer', async ({ page }) => {
    await useSubscriptionCatalog(page);
    let activated = false;
    let submittedCode;
    await page.route('**/api/backoffice/subscriptions/SUB_VISUAL_02', async (route) => {
        const response = await route.fetch();
        const subscription = await response.json();
        await route.fulfill({ response, json: { ...subscription, status: activated ? 'ativa' : 'aguardando_pagamento' } });
    });
    await page.route('**/api/backoffice/subscriptions/SUB_VISUAL_02/free-voucher', (route) => {
        submittedCode = route.request().postDataJSON().voucher_code;
        activated = true;
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'Assinatura ativada pelo voucher gratuito.', benefit_ends_at: '2026-10-01T12:00:00Z' }) });
    });
    await page.goto('/backoffice/assinaturas');
    await page.getByRole('button', { name: /Ver detalhes da assinatura/ }).nth(1).click();
    await expect(page.locator('#subscription-free-voucher-form')).toBeVisible();
    await page.locator('#subscription-free-voucher-code').fill('FREE7');
    await page.getByRole('button', { name: 'Ativar acesso gratuito' }).click();
    await expect(page.locator('#subscription-detail-summary')).toContainText('Ativa');
    await expect(page.locator('#subscription-free-voucher-form')).toBeHidden();
    expect(submittedCode).toBe('FREE7');
});

test('checkout assistido explica quando ainda não há oferta publicada', async ({ page }) => {
    await useSubscriptionCatalog(page);
    await page.route('**/api/backoffice/subscriptions/checkout-options?**', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ companies: [], products: [], catalog_message: 'Nenhuma oferta publicada está disponível para contratação no momento.' }),
    }));
    await page.goto('/backoffice/assinaturas');
    await page.getByRole('button', { name: 'Nova assinatura' }).click();
    await expect(page.locator('#subscription-create-product')).toContainText('Nenhuma oferta publicada disponível');
    await expect(page.locator('#subscription-create-plan')).toContainText('Nenhum plano publicado disponível');
    await expect(page.locator('#subscription-create-catalog-notice')).toContainText('Nenhuma oferta publicada está disponível');
});

test('encerramento imediato de assinatura exige confirmação e atualiza detalhe e listagem', async ({ page }) => {
    let patchCount = 0;
    page.on('request', (request) => {
        if (request.method() === 'PATCH' && request.url().includes('/api/backoffice/subscriptions/')) patchCount += 1;
    });
    await useSubscriptionCatalog(page);
    await page.goto('/backoffice/assinaturas');
    await page.getByRole('button', { name: /Ver detalhes da assinatura/ }).first().click();
    await page.locator('#subscription-action').selectOption('cancelamento_imediato');
    await page.getByLabel('Motivo').fill('Encerramento solicitado pela empresa.');
    await page.getByRole('button', { name: 'Registrar ação' }).click();
    await expect(page.locator('#subscription-confirm-dialog')).toBeVisible();
    expect(patchCount).toBe(0);

    await page.locator('#subscription-confirm-submit').click();
    await expect(page.locator('#backoffice-toast-container .fs-toast-body')).toContainText('Alteração comercial registrada.');
    await expect(page.locator('#subscription-detail-summary')).toContainText('Encerrada');
    await expect(page.locator('#subscription-list')).toContainText('Encerrada');
    expect(patchCount).toBe(1);
});

test('ações de mudança mostram os campos próprios e respeitam permissão de override', async ({ page }) => {
    await useSubscriptionCatalog(page);
    await page.goto('/backoffice/assinaturas');
    await page.getByRole('button', { name: /Ver detalhes da assinatura/ }).first().click();
    await expect(page.locator('#subscription-drawer')).toBeVisible();
    await page.locator('#subscription-action').selectOption('upgrade');
    await expect(page.locator('#subscription-target-fields')).toBeVisible();
    await expect(page.locator('#subscription-target-plan')).toHaveAttribute('required', '');
    await page.locator('#subscription-action').selectOption('downgrade');
    await expect(page.locator('#subscription-target-fields')).toBeVisible();
    await page.locator('#subscription-action').selectOption('suspensao');
    await expect(page.locator('#subscription-target-fields')).toBeHidden();
    await expect(page.locator('#subscription-override-option')).toBeHidden();
});

test('Assinaturas comunica resultado vazio e falha de carregamento', async ({ page }) => {
    await page.route('**/api/backoffice/subscriptions?**', (route) => {
        const query = new URL(route.request().url()).searchParams.get('q');
        if (query === 'empresa inexistente') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { total: 0, current_page: 1, per_page: 15, last_page: 1 } }) });
        if (query === 'erro') return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Serviço indisponível.' }) });
        return route.continue();
    });
    await page.goto('/backoffice/assinaturas');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'subscriptions');
    await expect(page.locator('#subscription-list tr')).toHaveCount(15);
    await page.getByLabel('Empresa ou produto').fill('empresa inexistente');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page.locator('[data-fs-datatable-empty]')).toBeVisible();
    await expect(page.locator('#subscription-table-summary')).toHaveText('0 assinaturas encontradas');
    expect(await page.evaluate(() => new URL(location.href).searchParams.get('q'))).toBe('empresa inexistente');

    await page.getByLabel('Empresa ou produto').fill('erro');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page.locator('[data-fs-datatable-error]')).toContainText('Serviço indisponível.');
    await expect(page.locator('[data-fs-datatable-loading]')).toBeHidden();
});

test('diretório Usuários abre detalhes de conta e se adapta a telas menores', async ({ page }) => {
    for (const viewport of [{ width: 1440, height: 900 }, { width: 768, height: 1024 }, { width: 375, height: 812 }]) {
        await page.setViewportSize(viewport);
        await page.goto('/backoffice/usuarios');
        await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'users');
        await expect(page.locator('#users-list tr[data-account-type]')).toHaveCount(2);
        await expect(page.locator('#users-list tr[data-account-type="empresa"] [data-label="Tipo"]')).toHaveText('Usuário Externo - Assinatura');
        await expect(page.locator('#users-list tr[data-account-type="empresa"] [data-label="Perfil ou vínculos"]')).toContainText('Administrador');
        await expect(page.locator('#users-list tr[data-account-type="empresa"] [data-label="Perfil ou vínculos"]')).toContainText('Empresa de Demonstração');
        await expect(page.locator('#users-list tr[data-account-type="plataforma"] [data-label="Tipo"]')).toHaveText('Usuário interno - FokusCloud');
        const brokenActionIcons = await page.locator('#users-list button[data-user-action] img').evaluateAll((images) => images.filter((image) => image.naturalWidth === 0).map((image) => new URL(image.src).pathname));
        expect(brokenActionIcons).toEqual([]);
        await page.getByRole('button', { name: 'Ver detalhes' }).nth(1).click();
        await expect(page.locator('#user-drawer')).toBeVisible();
        await expect(page.locator('#user-drawer-title')).toHaveText('Ana Empresa');
        await expect(page.locator('#user-view-panel')).toContainText('Assinaturas da empresa');
        await expect(page.locator('#user-view-panel')).toContainText('123.456.789-01');
        const overflow = await page.locator('.backoffice-records-page').evaluate((element) => element.scrollWidth > element.clientWidth);
        expect(overflow).toBe(false);
        await page.keyboard.press('Escape');
        await expect(page.locator('#user-drawer')).toBeHidden();
    }
});

test('Novo usuário permite convidar usuário externo vinculado a empresa Fokus Law', async ({ page }) => {
    await page.goto('/backoffice/usuarios');
    await page.locator('#user-invite').click();
    await page.locator('#user-account-type').selectOption('empresa');
    await expect(page.locator('#user-company option[value="CMP_VISUAL"]')).toHaveText('Fokus Law - Empresa de Demonstração');
    await expect(page.locator('#user-external-role')).toBeVisible();
    await page.locator('#user-name').fill('Pessoa Convidada');
    await page.locator('#user-email').fill('convidada@example.test');
    await page.locator('#user-cpf').fill('52998224725');
    await page.locator('#user-company').selectOption('CMP_VISUAL');
    await page.locator('#user-external-role').selectOption('gestor');
    await page.locator('#user-form-submit').click();
    await expect(page.locator('#backoffice-toast-container .fs-toast-body')).toContainText('Convite enviado ao usuário externo.');
});

test('consulta de e-mail no acesso Fokus Law identifica nome e sistema ativo', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Acesso interno não autenticado.' }) }));
    await page.goto('/marketing/products/fokus-law.html');
    await page.locator('#law-email').fill('pessoa@example.test');
    await expect(page.locator('#law-system')).toBeEnabled();
    await expect(page.locator('#law-system option')).toHaveText('Empresa de Demonstração — Fokus Law · Advocacia');
    await expect(page.locator('[data-law-login-status]')).toContainText('Pessoa Teste');
    await expect(page.locator('#law-password')).toBeEnabled();
});

test('consulta de e-mail mostra usuário existente sem assinatura ativa', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Acesso interno não autenticado.' }) }));
    await page.route('**/api/auth/law-context', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            user: { name: 'Pessoa Teste', email: 'pessoa@example.test' },
            systems: [],
            message: 'Não existe nenhuma assinatura ativa do Fokus Law vinculada a este usuário.',
        }),
    }));
    await page.goto('/marketing/products/fokus-law.html');
    await page.locator('#law-email').fill('pessoa@example.test');
    await expect(page.locator('[data-law-login-status]')).toContainText('Pessoa Teste — Não existe nenhuma assinatura ativa do Fokus Law vinculada a este usuário.');
    await expect(page.locator('#law-system')).toBeDisabled();
    await expect(page.locator('#law-password')).toBeDisabled();
});

test('Fokus Law usa o mesmo toast do Backoffice para erro de consulta', async ({ page }) => {
    await page.route('**/api/auth/law-context', (route) => route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Usuário não encontrado.' }) }));
    await page.goto('/marketing/products/fokus-law.html');
    await page.locator('#law-email').fill('ausente@example.test');
    await expect(page.locator('#backoffice-toast-container .fs-backoffice-toast')).toBeVisible();
    await expect(page.locator('#backoffice-toast-container .fs-toast-title')).toHaveText('Erro');
    await expect(page.locator('#backoffice-toast-container .fs-toast-body')).toHaveText('Usuário não encontrado.');
    await expect(page.locator('#backoffice-toast-container .fs-backoffice-toast')).toHaveScreenshot('fokus-law-login-error-toast.png', { animations: 'disabled', maxDiffPixelRatio: 0.02 });
    const lawToastStyles = await page.locator('#backoffice-toast-container .fs-backoffice-toast').evaluate((toast) => {
        const read = (element) => {
            const style = getComputedStyle(element);
            return Object.fromEntries(['position', 'display', 'flexDirection', 'alignItems', 'gap', 'padding', 'borderInlineStartWidth', 'borderRadius'].map((property) => [property, style[property]]));
        };
        return {
            container: read(toast.parentElement),
            toast: read(toast),
            header: read(toast.querySelector('.fs-toast-header')),
            title: read(toast.querySelector('.fs-toast-title')),
            body: read(toast.querySelector('.fs-toast-body')),
            close: read(toast.querySelector('.fs-toast-close')),
            progress: read(toast.querySelector('.fs-toast-progress')),
        };
    });

    await page.goto('/backoffice/assinaturas');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'subscriptions');
    await page.evaluate(() => window.FokusToast.show('Usuário não encontrado.', 'danger'));
    await expect(page.locator('#backoffice-toast-container .fs-backoffice-toast')).toBeVisible();
    const backofficeToastStyles = await page.locator('#backoffice-toast-container .fs-backoffice-toast').evaluate((toast) => {
        const read = (element) => {
            const style = getComputedStyle(element);
            return Object.fromEntries(['position', 'display', 'flexDirection', 'alignItems', 'gap', 'padding', 'borderInlineStartWidth', 'borderRadius'].map((property) => [property, style[property]]));
        };
        return {
            container: read(toast.parentElement),
            toast: read(toast),
            header: read(toast.querySelector('.fs-toast-header')),
            title: read(toast.querySelector('.fs-toast-title')),
            body: read(toast.querySelector('.fs-toast-body')),
            close: read(toast.querySelector('.fs-toast-close')),
            progress: read(toast.querySelector('.fs-toast-progress')),
        };
    });
    expect(lawToastStyles).toEqual(backofficeToastStyles);
});

test('Superadministrador MFA pode iniciar acesso de suporte a perfil real', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ admin: { role: 'superadministrador', name: 'Superadmin Teste', email: 'superadmin@example.test' } }) }));
    await page.route('**/api/backoffice/support/law-context', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ subscriptions: [{ id: 'SUB_TESTE_01', label: 'Empresa Teste — Essencial (Suspensa)', status: 'suspensa', users: [{ membership_id: 'MBS_TESTE_01', label: 'Administrador — Pessoa Teste (pessoa@example.test)' }] }] }) }));
    await page.route('**/api/backoffice/support/access', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ redirect_to: '/portal' }) }));
    await page.goto('/marketing/products/fokus-law.html');
    await expect(page.locator('[data-law-login-form]')).toBeVisible();
    await expect(page.locator('[aria-labelledby="law-support-title"]')).toBeHidden();
    await page.locator('#law-email').fill('superadmin@example.test');
    await expect(page.locator('[aria-labelledby="law-support-title"]')).toBeVisible();
    await expect(page.locator('[data-law-login-form]')).toBeHidden();
    await page.locator('#law-support-subscription').selectOption('SUB_TESTE_01');
    await page.locator('#law-support-user').selectOption('MBS_TESTE_01');
    await page.locator('#law-support-reason').fill('Investigar erro de permissões');
    await page.locator('#law-support-start').click();
    await expect(page).toHaveURL(/\/portal$/);
});

test('Portal identifica e encerra modo de suporte preservando o retorno ao Backoffice', async ({ page }) => {
    await page.route('**/api/csrf-token', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'csrf-visual' }) }));
    await page.route('**/api/auth/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ user: { name: 'Pessoa Teste', email_verified: true }, companies: [{ id: 'CMP_TESTE', name: 'Empresa Teste', role: 'admin' }], active_company_id: 'CMP_TESTE', support_mode: { active: true, company: 'Empresa Teste', subscription_status: 'suspensa', reason: 'Investigar falha' } }) }));
    await page.route('**/api/subscriptions', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) }));
    await page.route('**/api/backoffice/support/exit', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ redirect_to: '/backoffice/' }) }));
    await page.goto('/portal/dashboard.html');
    await expect(page.getByText('Modo de suporte ativo')).toBeVisible();
    await expect(page.locator('[data-support-context]')).toContainText('Empresa Teste');
    await page.getByRole('button', { name: 'Encerrar acesso de suporte' }).click();
    await expect(page).toHaveURL(/\/backoffice\/$/);
});

test('drawer de empresas preserva largura, cards e alertas do contrato visual', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/empresas');
    await page.locator('#company-create').click();
    await expect(page.locator('#company-drawer')).toBeVisible();

    const drawerContract = await page.locator('#company-drawer').evaluate((drawer) => {
        const cards = [...drawer.querySelectorAll('#company-form > .fs-card-panel')];
        const labels = [...drawer.querySelectorAll('#company-form label.fs-form-label')];
        const formColumns = [...drawer.querySelectorAll('#company-form .fs-form-col')];
        const controls = [...drawer.querySelectorAll('#company-form input:not([type="hidden"]), #company-form select')];
        return {
            width: getComputedStyle(drawer).width,
            bodyPadding: getComputedStyle(drawer.querySelector(':scope > .fs-offcanvas-body')).padding,
            cardsFitContent: cards.every((card) => {
                const body = card.querySelector(':scope > .fs-card-body');
                return body && body.getBoundingClientRect().bottom <= card.getBoundingClientRect().bottom + 1;
            }),
            cardBodySpacing: cards.every((card) => card.querySelector(':scope > .fs-card-body.fs-u-mx-2.fs-u-mt-2.fs-u-mb-3')),
            rowSpacing: [...drawer.querySelectorAll('#company-form .fs-card-body > .fs-form-row')]
                .every((row) => row.classList.contains('fs-u-mb-2')),
            labelsHaveSpans: labels.every((label) => label.firstElementChild?.matches('span.fs-u-ml-2')),
            labelsHaveSpacing: labels.every((label) => label.classList.contains('fs-u-mt-3')),
            labelsFitContent: labels.every((label) => getComputedStyle(label).width !== 'auto'),
            columnsDoNotGrow: formColumns.every((column) => getComputedStyle(column).flex === '0 1 auto'),
            controlsUseGoogleSans: controls.every((control) => getComputedStyle(control).fontFamily.includes('Google Sans')),
            fieldWidths: {
                documentType: drawer.querySelector('#company-document-type').classList.contains('fs-width-300'),
                document: drawer.querySelector('#company-document-number').classList.contains('fs-width-500'),
                legalName: drawer.querySelector('#company-legal-name').classList.contains('fs-width-600'),
                adminName: drawer.querySelector('#company-admin-name').classList.contains('fs-width-600'),
            },
        };
    });

    expect(drawerContract).toEqual({
        width: '480px',
        bodyPadding: '20px',
        cardsFitContent: true,
        cardBodySpacing: true,
        rowSpacing: true,
        labelsHaveSpans: true,
        labelsHaveSpacing: true,
        labelsFitContent: true,
        columnsDoNotGrow: true,
        controlsUseGoogleSans: true,
        fieldWidths: { documentType: true, document: true, legalName: true, adminName: true },
    });

    const alertContract = await page.locator('#company-message').evaluate((alert) => {
        alert.hidden = false;
        const style = getComputedStyle(alert);
        return {
            display: style.display,
            flexDirection: style.flexDirection,
            margin: style.margin,
            padding: style.padding,
        };
    });

    expect(alertContract).toEqual({
        display: 'flex',
        flexDirection: 'column',
        margin: '10px',
        padding: '10px 20px',
    });
});

test('controles compartilhados preservam a cascata do Fokus Styles', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/produtos');
    await expect(page.locator('#product-pagination .fs-page-item.is-active .fs-page-link')).toHaveCount(1);

    const beforeHover = await page.evaluate(() => {
        const filter = document.querySelector('#product-filter-form .fs-btn-outline-secondary');
        const activePage = document.querySelector('#product-pagination .fs-page-item.is-active .fs-page-link');
        const input = document.querySelector('#product-search');
        const select = document.querySelector('#product-status-filter');
        return {
            filterBorder: getComputedStyle(filter).border,
            activeBorder: getComputedStyle(activePage).border,
            activeBackground: getComputedStyle(activePage).backgroundColor,
            inputPadding: getComputedStyle(input).padding,
            selectPadding: getComputedStyle(select).padding,
        };
    });
    expect(beforeHover.filterBorder).toMatch(/^1px solid (rgb|oklch)/);
    expect(beforeHover.activeBorder).toMatch(/^1px solid (rgb|oklch)/);
    expect(beforeHover.activeBackground).toMatch(/^(rgb|oklch)/);
    expect(beforeHover.inputPadding).toBe('0px 12px');
    expect(beforeHover.selectPadding).toBe('0px 12px');

    await page.locator('#product-filter-form .fs-btn-outline-secondary').hover();
    await page.waitForTimeout(200);
    const hoverContract = await page.locator('#product-filter-form .fs-btn-outline-secondary').evaluate((button) => ({
        hovered: button.matches(':hover'),
        background: getComputedStyle(button).backgroundColor,
        color: getComputedStyle(button).color,
    }));
    expect(hoverContract.hovered).toBe(true);
    expect(hoverContract.color).toBe('rgb(255, 255, 255)');
    expect(hoverContract.background).toMatch(/^(rgb|oklch)/);
});

test('produtos replica o contrato visual e mantém create, edit e view independentes', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/produtos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'products');
    await expect(page.locator('#product-list tr')).toHaveCount(1);

    await page.locator('#product-new').click();
    await expect(page.locator('#product-drawer')).toBeVisible();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'create');
    const createContract = await page.locator('#product-drawer').evaluate((drawer) => {
        const labels = [...drawer.querySelectorAll('#product-form label.fs-form-label')].filter((label) => !label.hidden);
        const controls = [...drawer.querySelectorAll('#product-form input, #product-form select, #product-form textarea')].filter((control) => !control.disabled);
        const textareas = [...drawer.querySelectorAll('#product-form textarea')];
        return {
            width: getComputedStyle(drawer).width,
            cards: drawer.querySelectorAll('#product-form > .fs-card.fs-card-panel').length,
            labelsHaveSpans: labels.every((label) => label.firstElementChild?.matches('span.fs-u-ml-2')),
            labelsHaveSpacing: labels.every((label) => label.classList.contains('fs-u-mt-3')),
            cardBodySpacing: [...drawer.querySelectorAll('#product-form .fs-card-panel > .fs-card-body')]
                .every((body) => body.matches('.fs-u-mx-2.fs-u-mt-2.fs-u-mb-3')),
            controlsUseGoogleSans: controls.every((control) => getComputedStyle(control).fontFamily.includes('Google Sans')),
            textareaPadding: [...new Set(textareas.map((textarea) => `${getComputedStyle(textarea).paddingTop} ${getComputedStyle(textarea).paddingRight}`))],
            fieldWidths: {
                code: drawer.querySelector('#product-code').classList.contains('fs-width-600'),
                technicalDescription: drawer.querySelector('#product-technical-description').classList.contains('fs-width-600'),
                commercialDescription: drawer.querySelector('#product-commercial-content').classList.contains('fs-width-600'),
            },
        };
    });
    expect(createContract).toEqual({
        width: '480px',
        cards: 2,
        labelsHaveSpans: true,
        labelsHaveSpacing: true,
        cardBodySpacing: true,
        controlsUseGoogleSans: true,
        textareaPadding: ['10px 15px'],
        fieldWidths: { code: true, technicalDescription: true, commercialDescription: true },
    });

    await page.locator('#product-drawer-close').click();
    await expect(page.locator('#product-drawer')).toBeHidden();
    await expect(page.getByRole('button', { name: 'Editar produto' })).toHaveCount(0);
    await page.route('**/api/backoffice/catalog/products', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, status: 'pausado' })) } });
    });
    await page.reload();
    await page.getByRole('button', { name: 'Editar produto' }).click();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'edit');
    await expect(page.locator('#product-drawer-title')).toHaveText('Editar dados do produto');
    await expect(page.locator('#product-form-submit')).toHaveText('Salvar alterações');
    await expect(page.locator('#product-display-order-field')).toBeVisible();

    await page.locator('#product-drawer-close').click();
    await page.getByRole('button', { name: 'Ver detalhes do produto' }).click();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'view');
    await expect(page.locator('#product-form')).toBeHidden();
    await expect(page.locator('#product-view-panel')).toBeVisible();
    await expect(page.locator('#product-view-name')).toHaveText('Fokus Law');
});

test('módulos replica o contrato visual, personalizações e estados do drawer', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/modulos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'modules');
    await expect(page.locator('#module-list tr')).toHaveCount(1);

    await page.locator('#module-new').click();
    await expect(page.locator('#module-drawer')).toBeVisible();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'create');
    const createContract = await page.locator('#module-drawer').evaluate((drawer) => {
        const labels = [...drawer.querySelectorAll('#module-form label.fs-form-label')].filter((label) => !label.hidden);
        const textareas = [...drawer.querySelectorAll('#module-form textarea')];
        return {
            width: getComputedStyle(drawer).width,
            cards: drawer.querySelectorAll('#module-form > .fs-card.fs-card-panel').length,
            labelsHaveSpans: labels.every((label) => label.firstElementChild?.matches('span.fs-u-ml-2')),
            cardBodySpacing: [...drawer.querySelectorAll('#module-form .fs-card-panel > .fs-card-body')]
                .every((body) => body.matches('.fs-u-mx-2.fs-u-mt-2.fs-u-mb-3')),
            compactMultiSelects: [...drawer.querySelectorAll('#module-form select[multiple]')]
                .every((select) => select.getAttribute('size') === '1'),
            textareaPadding: [...new Set(textareas.map((textarea) => getComputedStyle(textarea).padding))],
            textareaFonts: [...new Set(textareas.map((textarea) => getComputedStyle(textarea).fontFamily))],
        };
    });
    expect(createContract).toEqual({
        width: '480px',
        cards: 4,
        labelsHaveSpans: true,
        cardBodySpacing: true,
        compactMultiSelects: true,
        textareaPadding: ['10px 15px'],
        textareaFonts: ['"Google Sans", sans-serif'],
    });

    await page.locator('#module-personalizations-open').click();
    await expect(page.locator('#module-personalizations-drawer')).toBeVisible();
    await page.locator('#personalization-add').click();
    await expect(page.locator('#personalizations-list > .fs-card')).toHaveCount(1);
    await page.locator('#personalizations-save').click();
    await expect(page.locator('#module-personalizations-drawer')).toBeHidden();

    await page.locator('#module-drawer-close').click();
    await expect(page.getByRole('button', { name: 'Editar módulo' })).toHaveCount(0);
    await page.route('**/api/backoffice/catalog', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, modules: product.modules.map((module) => ({ ...module, status: 'inativo', publication_state: 'pausado' })) })) } });
    });
    await page.reload();
    await page.getByRole('button', { name: 'Editar módulo' }).click();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'edit');
    await expect(page.locator('#module-drawer-title')).toHaveText('Editar dados do módulo');
    await expect(page.locator('#module-form-submit')).toHaveText('Salvar alterações');
    await expect(page.locator('#module-edit-controls')).toBeVisible();

    await page.locator('#module-drawer-close').click();
    await page.getByRole('button', { name: 'Ver detalhes do módulo' }).click();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'view');
    await expect(page.locator('#module-form')).toBeHidden();
    await expect(page.locator('#module-view-panel')).toBeVisible();
    await expect(page.locator('#module-view-panel')).toContainText('Gestão de processos');
});

test('salvar alterações de módulo usa PATCH no módulo em edição', async ({ page }) => {
    let saveRequest = null;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/modules/MOD_1')) saveRequest = { method: request.method(), url: request.url() };
    });

    await page.route('**/api/backoffice/catalog', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, modules: product.modules.map((module) => ({ ...module, status: 'inativo', publication_state: 'pausado' })) })) } });
    });
    await page.goto('/backoffice/modulos');
    await page.getByRole('button', { name: 'Editar módulo' }).click();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'edit');
    await page.locator('#module-name').fill('Gestão de processos atualizada');
    await page.locator('#module-form-submit').click();
    await expect.poll(() => saveRequest).toEqual({ method: 'PATCH', url: 'http://127.0.0.1:4177/api/backoffice/catalog/modules/MOD_1' });
});

test('salvar alterações de produto usa PATCH no produto em edição', async ({ page }) => {
    let saveRequest = null;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/products/PRD_LAW')) saveRequest = { method: request.method(), url: request.url() };
    });

    await page.route('**/api/backoffice/catalog/products', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, status: 'pausado' })) } });
    });
    await page.goto('/backoffice/produtos');
    await page.getByRole('button', { name: 'Editar produto' }).click();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'edit');
    await page.locator('#product-name').fill('Fokus Law atualizado');
    await page.locator('#product-form-submit').click();
    await expect.poll(() => saveRequest).toEqual({ method: 'PATCH', url: 'http://127.0.0.1:4177/api/backoffice/catalog/products/PRD_LAW' });
});

test('salvar alterações de plano usa PATCH no plano em edição', async ({ page }) => {
    let saveRequest = null;
    let saveRequests = 0;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/plans/PLN_1')) { saveRequests += 1; saveRequest = { method: request.method(), url: request.url() }; }
    });

    await page.route('**/api/backoffice/plans', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: data.map((plan) => ({ ...plan, status: 'inativo', publication_state: 'pausado' })) });
    });
    await page.goto('/backoffice/planos');
    await page.getByRole('button', { name: 'Editar plano' }).click();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'edit');
    await page.locator('#plan-name').fill('Essencial atualizado');
    await page.locator('#plan-form-submit').dblclick();
    await expect.poll(() => saveRequest).toEqual({ method: 'PATCH', url: 'http://127.0.0.1:4177/api/backoffice/catalog/plans/PLN_1' });
    expect(saveRequests).toBe(1);
});

test('ativar módulo não dispara a ação nem o toast mais de uma vez', async ({ page }) => {
    let activationRequests = 0;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/modules/MOD_1/activate')) activationRequests += 1;
    });

    await page.goto('/backoffice/modulos');
    await expect(page.locator('#module-list')).toBeVisible();
    await page.evaluate(() => {
        window.__moduleClickCount = 0;
        document.querySelector('#module-list').addEventListener('click', () => { window.__moduleClickCount += 1; }, true);
    });
    await page.locator('#module-list [data-module-action="view"]').first().evaluate((button) => {
        button.dataset.moduleAction = 'activate';
        button.setAttribute('aria-label', 'Ativar módulo');
    });
    await page.locator('#module-list [data-module-action="activate"]').click();
    expect(await page.evaluate(() => window.__moduleClickCount)).toBe(1);
    await expect.poll(() => activationRequests).toBe(1);
    await expect.poll(() => page.locator('#backoffice-toast-container .fs-toast').count()).toBe(1);
});

test('planos replica o contrato visual, composição e estados do drawer', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/planos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'subscription-plans');
    await expect(page.locator('#plan-list tr')).toHaveCount(1);
    await expect(page.locator('#plan-pagination .fs-page-link')).toHaveCount(3);
    await expect(page.locator('#plan-pagination .fs-page-item.is-active')).toContainText('1');
    await expect(page.locator('#plan-table-footer-summary')).toHaveText('Mostrando página 1 de 1 com 1 registros, de um total de 1 páginas.');
    await expect(page.locator('#plan-list [data-plan-action="pause"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="activate"], #plan-list [data-plan-action="archive"], #plan-list [data-plan-action="delete"]')).toHaveCount(0);
    await expect(page.locator('#plan-product-filter, #plan-publication-filter, #plan-featured-filter')).toHaveCount(0);
    await expect(page.locator('table[aria-label="Planos de assinatura"] thead th')).toHaveText(['Plano', 'Produto', 'Valores', 'Status', 'Publicação', 'Versão', 'Assinaturas', 'Ações']);
    const priceContract = await page.locator('#plan-list td[data-label="Valores"]').evaluate((cell) => ({
        lines: cell.querySelectorAll('.plan-price-line').length,
        annualSmaller: Number.parseFloat(getComputedStyle(cell.querySelector('.plan-price-line--annual .plan-price-value')).fontSize) < Number.parseFloat(getComputedStyle(cell.querySelector('.plan-price-line:not(.plan-price-line--annual) .plan-price-value')).fontSize),
    }));
    expect(priceContract).toEqual({ lines: 2, annualSmaller: true });

    await page.locator('#plan-new').click();
    await expect(page.locator('#plan-drawer')).toBeVisible();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'create');
    const createContract = await page.locator('#plan-drawer').evaluate((drawer) => ({
        width: getComputedStyle(drawer).width,
        cards: drawer.querySelectorAll('#plan-form > .fs-card.fs-card-panel').length,
        controlsUseGoogleSans: [...drawer.querySelectorAll('input, select, textarea')].every((control) => getComputedStyle(control).fontFamily.includes('Google Sans')),
        textareaPadding: [...new Set([...drawer.querySelectorAll('textarea')].map((textarea) => getComputedStyle(textarea).padding))],
        segmentIsSelect: drawer.querySelector('#plan-segment')?.tagName === 'SELECT',
        segmentOptions: [...drawer.querySelectorAll('#plan-segment option')].map((option) => option.value),
        descriptionWidths: [...drawer.querySelectorAll('#plan-technical-description, #plan-commercial-content')].map((field) => field.classList.contains('fs-width-600')),
        descriptionMaxLengths: [...drawer.querySelectorAll('#plan-technical-description, #plan-commercial-content')].map((field) => field.getAttribute('maxlength')),
        primaryButton: drawer.querySelector('#plan-form-submit')?.classList.contains('fs-btn-primary'),
    }));
    expect(createContract).toEqual({ width: '480px', cards: 3, controlsUseGoogleSans: true, textareaPadding: ['10px 15px'], segmentIsSelect: true, segmentOptions: ['', 'advocacia'], descriptionWidths: [true, true], descriptionMaxLengths: ['2000', '20000'], primaryButton: true });

    await page.locator('#plan-product').selectOption('PRD_LAW');
    await page.locator('#plan-segment').selectOption('advocacia');
    await page.locator('#plan-composition-open').click();
    await expect(page.locator('#plan-composition-drawer')).toBeVisible();
    await expect(page.locator('#plan-module-options')).toContainText('Gestão de processos');
    await expect(page.locator('#plan-module-options .fs-check-label')).toHaveCount(1);
    const moduleCheckbox = page.locator('#plan-module-options input[type="checkbox"]').first();
    await page.locator('#plan-module-options .plan-module-meta').click();
    await expect(moduleCheckbox).toBeChecked();
    await page.locator('#plan-composition-save').click();
    await expect(page.locator('#plan-composition-drawer')).toBeHidden();
    await expect(page.locator('#plan-composition-summary')).toContainText('1 funcionalidade');
    await expect(page.locator('#plan-suggested-price')).toHaveText('R$ 24,90');
    await expect(page.locator('#plan-annual-price')).toHaveText('R$ 249,00');

    await page.locator('#plan-drawer-close').click();
    await expect(page.getByRole('button', { name: 'Editar plano' })).toHaveCount(0);
    await page.route('**/api/backoffice/plans', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: data.map((plan) => ({ ...plan, status: 'inativo', publication_state: 'pausado' })) });
    });
    await page.reload();
    await page.getByRole('button', { name: 'Editar plano' }).click();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'edit');
    await expect(page.locator('#plan-form-submit')).toHaveText('Salvar alterações');
    await expect(page.locator('#plan-code')).toBeDisabled();

    await page.locator('#plan-drawer-close').click();
    await page.getByRole('button', { name: 'Ver detalhes do plano' }).click();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'view');
    await expect(page.locator('#plan-form')).toBeHidden();
    await expect(page.locator('#plan-view-panel')).toBeVisible();
    await expect(page.locator('#plan-view-panel')).toHaveClass(/fs-offcanvas-body/);
    await expect(page.locator('#plan-view-panel > .fs-card.fs-card-panel')).toHaveCount(3);
    await expect(page.locator('#plan-view-panel')).toContainText('2');
});

test('planos limita os ícones de ciclo de vida aos estados permitidos', async ({ page }) => {
    let status = 'inativo';
    await page.route('**/api/backoffice/plans', async (route) => {
        const response = await route.fetch();
        const plans = await response.json();
        await route.fulfill({ response, json: plans.map((plan) => ({ ...plan, status, publication_state: status === 'arquivado' ? 'arquivado' : 'pausado' })) });
    });
    await page.goto('/backoffice/planos');
    await expect(page.locator('#plan-list [data-plan-action="activate"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="archive"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="pause"], #plan-list [data-plan-action="delete"]')).toHaveCount(0);

    status = 'arquivado';
    await page.reload();
    await expect(page.locator('#plan-list [data-plan-action="delete"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="activate"], #plan-list [data-plan-action="archive"], #plan-list [data-plan-action="pause"]')).toHaveCount(0);

    status = 'ativo';
    await page.reload();
    await expect(page.locator('#plan-list [data-plan-action="publish"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="pause"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="archive"], #plan-list [data-plan-action="edit"]')).toHaveCount(0);
});

test('Vouchers filtra, pagina e consulta regras, resgates e reservas em drawer responsivo', async ({ page }) => {
    const products = [{
        id: 'PRD_VOUCHER_LAW', name: 'Fokus Law', code: 'fokus-law', plans: [
            { id: 'PLN_VOUCHER_01', name: 'Essencial', full_name: 'Fokus Law — Essencial', monthly_amount: 50, annual_amount: 500 },
        ],
    }];
    const vouchers = Array.from({ length: 17 }, (_, index) => ({
        id: `VCH_VISUAL_${String(index + 1).padStart(2, '0')}`,
        code: `CAMPANHA${index + 1}`,
        name: `Campanha visual ${index + 1}`,
        product_id: 'PRD_VOUCHER_LAW',
        product_name: 'Fokus Law',
        plan_id: 'PLN_VOUCHER_01',
        plan_name: 'Essencial',
        discount_type: index % 2 ? 'percentage' : 'trial_free',
        discount_value: index % 2 ? 10 : 100,
        base_amount: 50,
        benefit_duration: 'm1',
        redemptions_count: index === 0 ? 1 : 0,
        redemption_limit: 25,
        redemption_limit_per_company: 1,
        starts_at: '2026-09-01',
        ends_at: '2027-09-01',
        status: index === 1 ? 'suspensa' : 'ativa',
        computed_status: index === 2 ? 'expirada' : index === 1 ? 'suspensa' : 'ativa',
        origin: 'Homologação visual',
        notes: 'Observação interna do voucher de teste.',
    }));
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        admin: { role: 'superadministrador', name: 'Superadmin Teste', email: 'superadmin@example.test', permissions: ['platform.vouchers.manage', 'platform.catalog.publish'] },
    }) }));
    await page.route('**/api/backoffice/catalog', (route) => route.fulfill({
        contentType: 'application/json', body: JSON.stringify({ products, options: { segments: {}, module_codes: [], personalization_types: [] } }),
    }));
    await page.route('**/api/backoffice/vouchers', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(vouchers) }));
    await page.route('**/api/backoffice/vouchers/VCH_VISUAL_01', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({
        voucher: vouchers[0],
        redemptions: Array.from({ length: 24 }, (_, index) => ({
            id: `VRD_VISUAL_${String(index + 1).padStart(2, '0')}`, voucher_id: vouchers[0].id, company_id: `COM_VISUAL_${String(index + 1).padStart(2, '0')}`, subscription_id: `SUB_VISUAL_${String(index + 1).padStart(2, '0')}`, discount_amount: 50,
            benefit_starts_at: '2026-09-20T12:00:00Z', benefit_ends_at: '2026-10-20T12:00:00Z', created_at: '2026-09-20T12:00:00Z',
            snapshot: { plan_name: 'Fokus Law — Essencial', company_id: `COM_VISUAL_${String(index + 1).padStart(2, '0')}`, discount_amount: 50 },
        })),
        reservations: Array.from({ length: 12 }, (_, index) => ({
            id: `VRS_VISUAL_${String(index + 1).padStart(2, '0')}`, status: index ? 'released' : 'pending', company_id: `COM_RESERVATION_${String(index + 1).padStart(2, '0')}`, subscription_id: `SUB_RESERVATION_${String(index + 1).padStart(2, '0')}`,
            reserved_at: '2026-09-22T12:00:00Z', expires_at: '2026-09-22T12:30:00Z', snapshot: { company_id: `COM_RESERVATION_${String(index + 1).padStart(2, '0')}` },
        })),
    }) }));
    await page.route('**/api/backoffice/vouchers/VCH_VISUAL_02', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ voucher: vouchers[1], redemptions: [], reservations: [] }) }));

    for (const [name, viewport] of [['desktop', { width: 1440, height: 900 }], ['tablet', { width: 768, height: 1024 }], ['mobile', { width: 375, height: 812 }]]) {
        await page.setViewportSize(viewport);
        await page.goto('/backoffice/vouchers');
        await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'vouchers');
        await expect(page.locator('.backoffice-records-page')).toBeVisible();
        await expect(page.locator('#voucher-list tr')).toHaveCount(15);
        await expect(page.locator('.fs-table thead th')).toHaveCount(7);
        await expect(page.locator('.fs-table thead')).not.toContainText('Código');
        await expect(page.locator('#voucher-list tr').first().locator('td').first()).toContainText('Campanha visual 1');
        await expect(page.locator('#voucher-list tr').first().locator('td').first().locator('strong')).toHaveText('Campanha visual 1');
        await expect(page.locator('#voucher-list tr').first().locator('td').first().locator('small')).toHaveText('CAMPANHA1');
        await expect(page.locator('#voucher-product-filter option')).toHaveCount(2);
        await expect(page.locator('#voucher-table-footer-summary')).toContainText('página 1 de 2');
        await expect(page.locator('#voucher-pagination')).toHaveAccessibleName('Paginação de vouchers');

        await page.locator('#voucher-search').fill('Campanha visual 1');
        await expect(page.locator('#voucher-list tr')).toHaveCount(9);
        await page.locator('#voucher-search').fill('');
        await page.locator('#voucher-product-filter').selectOption('PRD_VOUCHER_LAW');
        await page.locator('#voucher-type-filter').selectOption('trial_free');
        await page.locator('#voucher-status-filter').selectOption('expirada');
        await expect(page.locator('#voucher-list tr')).toHaveCount(1);
        await expect(page.locator('#voucher-list')).toContainText('CAMPANHA3');
        await page.locator('#voucher-type-filter').selectOption('');
        await page.locator('#voucher-status-filter').selectOption('');
        await page.getByRole('button', { name: 'Filtrar' }).click();

        await page.locator('#voucher-search').fill('');
        await page.getByRole('button', { name: 'Próxima página' }).click();
        await expect(page.locator('#voucher-list tr')).toHaveCount(2);
        await expect(page.locator('#voucher-table-footer-summary')).toContainText('página 2 de 2');
        await page.getByRole('button', { name: 'Página anterior' }).click();
        await expect(page.locator('#voucher-list tr')).toHaveCount(15);

        const viewButton = page.getByRole('button', { name: 'Ver detalhes do voucher' }).first();
        await viewButton.click();
        await expect(page.locator('#voucher-drawer')).toBeVisible();
        await expect(page.locator('#voucher-detail-commercial')).toContainText('CAMPANHA1');
        await expect(page.locator('#voucher-detail-redemptions')).toContainText('COM_VISUAL_01');
        await expect(page.locator('#voucher-detail-reservations')).toContainText('COM_RESERVATION_02');
        await expect(page.locator('#voucher-detail-redemptions article')).toHaveCount(24);
        await expect(page.locator('#voucher-detail-reservations article')).toHaveCount(12);
        const drawer = await page.locator('#voucher-drawer').evaluate((element) => ({
            width: element.getBoundingClientRect().width,
            scrollWidth: element.scrollWidth,
            clientWidth: element.clientWidth,
            footerVisible: element.querySelector('#voucher-view-footer').getBoundingClientRect().bottom <= window.innerHeight,
        }));
        expect(drawer.width).toBeGreaterThan(0);
        expect(drawer.width).toBeLessThanOrEqual(viewport.width);
        expect(drawer.scrollWidth).toBeLessThanOrEqual(drawer.clientWidth);
        expect(drawer.footerVisible).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.keyboard.press('Escape');
        await expect(page.locator('#voucher-drawer')).toBeHidden();
        await expect(viewButton).toBeFocused();
        await page.getByRole('button', { name: 'Ver detalhes do voucher' }).nth(1).click();
        await expect(page.locator('#voucher-detail-redemptions')).toContainText('Nenhum resgate confirmado');
        await expect(page.locator('#voucher-detail-reservations')).toContainText('Nenhuma reserva de checkout registrada');
        await page.locator('#voucher-drawer-close').click();
    }
});

test('Vouchers cria campanha e limita ações comerciais às permissões documentadas', async ({ page }) => {
    const products = [{ id: 'PRD_VOUCHER_LAW', name: 'Fokus Law', code: 'fokus-law', plans: [{ id: 'PLN_VOUCHER_01', name: 'Essencial', full_name: 'Fokus Law — Essencial', monthly_amount: 50, annual_amount: 500 }] }];
    const vouchers = [];
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        admin: { role: 'administrador_comercial', name: 'Admin Comercial Teste', email: 'comercial@example.test', permissions: ['platform.vouchers.manage'] },
    }) }));
    await page.route('**/api/backoffice/catalog', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ products }) }));
    await page.route('**/api/backoffice/vouchers', async (route) => {
        if (route.request().method() === 'POST') {
            const payload = route.request().postDataJSON();
            expect(payload.code).toMatch(/^[A-Z0-9]+$/);
            expect(payload).toMatchObject({ name: 'Campanha de boas-vindas', product_id: 'PRD_VOUCHER_LAW', plan_id: 'PLN_VOUCHER_01', discount_type: 'percentage', discount_value: 15, benefit_duration: 'm1' });
            vouchers.unshift({ id: 'VCH_CREATED_01', ...payload, product_name: 'Fokus Law', plan_name: 'Essencial', redemptions_count: 0, status: 'ativa', computed_status: 'ativa' });
            return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ id: 'VCH_CREATED_01', code: payload.code, message: 'Voucher criado.' }) });
        }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify(vouchers) });
    });
    await page.route('**/api/backoffice/vouchers/VCH_CREATED_01', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ voucher: vouchers[0], redemptions: [], reservations: [] }) }));

    await page.goto('/backoffice/vouchers');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'vouchers');
    await expect(page.getByRole('button', { name: 'Novo voucher' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Arquivar voucher' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Excluir voucher' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Novo voucher' }).click();
    await expect(page.locator('#voucher-drawer')).toBeVisible();
    await page.locator('#voucher-name').fill('Campanha de boas-vindas');
    await expect(page.locator('#voucher-code')).toHaveValue(/^[A-Z0-9]+$/);
    await page.locator('#voucher-product').selectOption('PRD_VOUCHER_LAW');
    await page.locator('#voucher-plan').selectOption('PLN_VOUCHER_01');
    await page.locator('#voucher-discount-type').selectOption('trial_free');
    await expect(page.locator('#voucher-discount-percent')).toHaveValue('100');
    await expect(page.locator('#voucher-discount-percent')).toBeDisabled();
    await expect(page.locator('#voucher-discount-amount')).toBeDisabled();
    await page.locator('#voucher-plan').selectOption('__all__');
    await expect(page.locator('#voucher-discount-type option[value="trial_free"]')).toHaveAttribute('disabled', '');
    await expect(page.locator('#voucher-discount-type')).toHaveValue('');
    await expect(page.locator('#voucher-benefit-help')).toContainText('Para assinatura gratuita, desconto fixo ou crédito, selecione um plano específico.');
    await page.locator('#voucher-plan').selectOption('PLN_VOUCHER_01');
    await page.locator('#voucher-discount-type').selectOption('percentage');
    await page.locator('#voucher-duration').selectOption('m1');
    await page.locator('#voucher-discount-percent').fill('15');
    await page.locator('#voucher-start-date').fill('2026-09-23');
    await page.locator('#voucher-end-date').fill('2027-09-23');
    await page.locator('#voucher-total').fill('50');
    await page.locator('#voucher-company-limit').fill('1');
    await page.getByRole('button', { name: 'Cadastrar voucher' }).click();
    await expect(page.locator('#voucher-message')).toContainText('Voucher cadastrado.');
    await expect(page.locator('#voucher-list')).toContainText('Campanha de boas-vindas');
    await expect(page.locator('#voucher-drawer')).toBeHidden();
    await page.getByRole('button', { name: 'Editar voucher' }).click();
    await expect(page.locator('#voucher-drawer-title')).toHaveText('Editar voucher');
    await expect(page.locator('#voucher-form-submit')).toHaveText('Salvar alterações');
    await expect(page.locator('#voucher-view-panel')).toBeHidden();
    await page.locator('#voucher-form-cancel').click();
    await page.getByRole('button', { name: 'Ver detalhes do voucher' }).click();
    await expect(page.locator('#voucher-view-panel')).toBeVisible();
    await expect(page.locator('#voucher-form')).toBeHidden();
    await expect(page.locator('#voucher-detail-redemptions')).toContainText('Nenhum resgate confirmado');
});

test('voucher de assinatura gratuita envia uma duração ao cadastro', async ({ page }) => {
    const products = [{ id: 'PRD_VOUCHER_LAW', name: 'Fokus Law', plans: [{ id: 'PLN_VOUCHER_01', name: 'Essencial', monthly_amount: 50, annual_amount: 500 }] }];
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        admin: { role: 'superadministrador', name: 'Superadmin Teste', permissions: ['platform.vouchers.manage', 'platform.catalog.publish'] },
    }) }));
    await page.route('**/api/backoffice/catalog', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ products }) }));
    await page.route('**/api/backoffice/vouchers', async (route) => {
        if (route.request().method() === 'POST') {
            expect(route.request().postDataJSON()).toMatchObject({
                product_id: 'PRD_VOUCHER_LAW', plan_id: 'PLN_VOUCHER_01',
                discount_type: 'trial_free', discount_value: 100, benefit_duration: 'm3',
            });
            return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ id: 'VCH_FREE_01', code: 'GRATUITO1', message: 'Voucher criado.' }) });
        }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify([]) });
    });

    await page.goto('/backoffice/vouchers');
    await page.getByRole('button', { name: 'Novo voucher' }).click();
    await page.locator('#voucher-name').fill('Acesso gratuito de teste');
    await page.locator('#voucher-product').selectOption('PRD_VOUCHER_LAW');
    await page.locator('#voucher-plan').selectOption('PLN_VOUCHER_01');
    await page.locator('#voucher-discount-type').selectOption('trial_free');
    await page.locator('#voucher-duration').selectOption('m3');
    await page.locator('#voucher-start-date').fill(await page.evaluate(() => new Date().toISOString().slice(0, 10)));
    await page.locator('#voucher-end-date').fill(await page.evaluate(() => { const date = new Date(); date.setFullYear(date.getFullYear() + 1); return date.toISOString().slice(0, 10); }));
    await page.locator('#voucher-total').fill('10');
    await page.locator('#voucher-company-limit').fill('1');
    await page.getByRole('button', { name: 'Cadastrar voucher' }).click();
    await expect(page.locator('#voucher-message')).toContainText('Voucher cadastrado.');
});
