import { test, expect } from '@playwright/test';

for (const width of [320, 375, 1440]) {
    for (const [route, pageId] of [['pagamentos', 'pagamentos'], ['vouchers', 'vouchers']]) {
        test(`${route}: filtros acessíveis e contidos em ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.route('**/*', (route) => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
            await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: { id: 'PAD_TEST', name: 'Admin Teste', role: 'superadministrador', permissions: ['platform.access', 'platform.payments.view', 'platform.reconciliation.view', 'platform.refunds.request', 'platform.vouchers.manage', 'platform.catalog.manage', 'platform.catalog.publish'] } } }));
            await page.route(/\/api\/backoffice\/(payments|reconciliation|refunds|vouchers)(\?.*)?$/, (route) => route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 15 } } }));
            await page.mouse.move(width - 10, 890);
            await page.goto(`/backoffice/${route}`);
            await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', pageId);
            const forms = route === 'pagamentos'
                ? [['#billing-payments-filter-form', '#billing-payments-query', null], ['#billing-reconciliation-filter-form', '#billing-reconciliation-query', '#billing-reconciliation-tab'], ['#billing-refunds-filter-form', '#billing-refunds-query', '#billing-refunds-tab']]
                : [['#voucher-filter-form', '#voucher-search', null]];
            for (const [formSelector, searchSelector, tabSelector] of forms) {
                if (tabSelector) await page.locator(tabSelector).click();
                const form = page.locator(formSelector);
                await expect(form).toBeVisible();
                const controls = form.locator('input, select, button');
                for (let i = 0; i < await controls.count(); i++) {
                    const control = controls.nth(i);
                    const bounds = await control.evaluate((element) => {
                        const rect = (item) => { const r = item.getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width }; };
                        return { control: rect(element), card: rect(element.closest('.fs-card-panel')), viewport: innerWidth };
                    });
                    expect(bounds.control.width).toBeGreaterThan(0);
                    expect(bounds.control.left).toBeGreaterThanOrEqual(bounds.card.left - 1);
                    expect(bounds.control.right).toBeLessThanOrEqual(bounds.card.right + 1);
                    expect(bounds.control.right).toBeLessThanOrEqual(bounds.viewport + 1);
                }
                const search = page.locator(searchSelector);
                await search.fill('Filtro sintético');
                await expect(search).toHaveValue('Filtro sintético');
                const listRequest = page.waitForRequest((request) => request.method() === 'GET' && new URL(request.url()).searchParams.get(route === 'vouchers' ? 'search' : 'q') === 'Filtro sintético');
                await search.press('Enter');
                await listRequest;
            }
        });
    }
}
