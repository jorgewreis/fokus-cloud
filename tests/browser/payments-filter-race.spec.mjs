import { test, expect } from '@playwright/test';

for (const kind of ['payments', 'reconciliation', 'refunds']) {
 test(`filtro de ${kind} substitui consulta em andamento`, async ({ page }) => {
    const seen = [];
    let release;
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: { id: 'PAD_TEST', name: 'Teste', role: 'superadministrador', permissions: ['platform.payments.view', 'platform.reconciliation.view', 'platform.refunds.request'] } } }));
    await page.route(`**/api/backoffice/${kind}?**`, async (route) => {
        const query = new URL(route.request().url()).searchParams.get('q') || '';
        seen.push(query);
        if (query === 'antigo') await new Promise((resolve) => { release = resolve; });
        const data = query ? [{ id: query, company_name: query, status: 'aprovado', amount: 10, currency: 'BRL' }] : [];
        await route.fulfill({ json: { data, meta: { current_page: 1, per_page: 15, total: data.length, last_page: 1 } } }).catch(() => {});
    });
    await page.goto('/backoffice/pagamentos');
    if (kind !== 'payments') await page.locator(`#billing-${kind}-tab`).click();
    await expect.poll(() => seen.includes('')).toBe(true);
    await page.locator(`#billing-${kind}-query`).fill('antigo');
    await page.locator(`#billing-${kind}-filter-form`).evaluate((form) => form.requestSubmit());
    await expect.poll(() => seen.includes('antigo')).toBe(true);
    await page.locator(`#billing-${kind}-query`).fill('atual');
    await page.locator(`#billing-${kind}-filter-form`).evaluate((form) => form.requestSubmit());
    await expect.poll(() => seen.includes('atual')).toBe(true);
    await expect(page.locator(`#billing-${kind}-list`)).toContainText('atual');
    release();
    await expect(page.locator(`#billing-${kind}-list`)).toContainText('atual');
    await expect(page.locator(`#billing-${kind}-list`)).not.toContainText('antigo');
});

}
