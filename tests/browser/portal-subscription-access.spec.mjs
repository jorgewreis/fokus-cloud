import { test, expect } from '@playwright/test';

for (const width of [375, 1440]) {
    test(`customer without commercial access stays in portal at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        let subscriptionRequests = 0;
        await page.route('**/api/auth/me', (route) => route.fulfill({ json: {
            user: { name: 'Pessoa sintética', email_verified: true }, active_company_id: 'COMPANY_A',
            companies: [{ id: 'COMPANY_A', name: 'Empresa sintética', role: 'gestor' }],
        } }));
        await page.route('**/api/subscriptions', (route) => {
            subscriptionRequests++;
            return route.fulfill({ status: 403, json: { message: 'Acesso negado' } });
        });
        await page.goto('/portal/dashboard.html');
        await expect(page.locator('#message')).toContainText('Empresa sintética');
        await expect(page.locator('#role')).toHaveText('Gestor');
        await expect(page.locator('#subscriptions')).toBeHidden();
        await expect(page.locator('main .review-actions a[href="/portal/assinaturas"]')).toBeHidden();
        expect(subscriptionRequests).toBe(0);
        expect(new URL(page.url()).pathname).toBe('/portal/dashboard.html');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);

        await page.goto('/portal/subscriptions.html');
        await expect(page.locator('#message')).toContainText('Somente administradores');
        expect(new URL(page.url()).pathname).toBe('/portal/subscriptions.html');
        expect(subscriptionRequests).toBe(1);
    });
}

test('support session receives a support-specific explanation', async ({ page }) => {
    await page.route('**/api/auth/me', (route) => route.fulfill({ json: {
        user: { id: 'USER_A', name: 'Pessoa sintética' }, active_company_id: 'COMPANY_A',
        companies: [{ id: 'COMPANY_A', name: 'Empresa sintética' }],
        support_mode: { active: true, company: 'Empresa sintética', plan: 'Plano sintético', subscription_status: 'ativa' },
    } }));
    await page.route('**/api/subscriptions', (route) => route.fulfill({ status: 403, json: { code: 'support_customer_api_denied' } }));
    await page.goto('/portal/subscriptions.html');
    await expect(page.locator('#message')).toContainText('não está disponível durante uma sessão de suporte');
    await expect(page.locator('[data-support-context]')).toContainText('Empresa sintética');
    expect(new URL(page.url()).pathname).toBe('/portal/subscriptions.html');
});
