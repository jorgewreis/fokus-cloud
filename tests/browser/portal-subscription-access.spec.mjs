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

const supportDenied = { code: 'support_customer_api_denied', message: 'O acesso de suporte não pode acessar APIs operacionais de clientes.' };

async function mockSupportSession(page) {
    await page.route('**/api/auth/me', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 403, json: supportDenied }));
}

test('support session receives a support-specific explanation', async ({ page }) => {
    await mockSupportSession(page);
    await page.route('**/api/subscriptions', (route) => route.fulfill({ status: 403, json: supportDenied }));
    await page.goto('/portal/subscriptions.html');
    await expect(page.locator('#message')).toContainText('não está disponível durante uma sessão de suporte');
    await expect(page.locator('[data-support-context]')).toContainText('Os dados do cliente não ficam disponíveis durante o acesso de suporte.');
    await expect(page.locator('[data-support-exit]')).toBeVisible();
    await expect(page.getByText('Empresa sintética')).toHaveCount(0);
    expect(new URL(page.url()).pathname).toBe('/portal/subscriptions.html');
});

for (const width of [375, 1440]) {
    test(`support dashboard works without the customer identity at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        let subscriptionRequests = 0;
        await mockSupportSession(page);
        await page.route('**/api/subscriptions', (route) => { subscriptionRequests++; return route.fulfill({ status: 403, json: supportDenied }); });
        await page.goto('/portal/dashboard.html');
        await expect(page.locator('#role')).toHaveText('Suporte');
        await expect(page.locator('#message')).toHaveText('Sessão de suporte ativa. Os dados do cliente não ficam disponíveis.');
        await expect(page.locator('#subscriptions')).toBeHidden();
        await expect(page.locator('#renewal')).toBeHidden();
        await expect(page.locator('main .portal-section')).toBeHidden();
        expect(subscriptionRequests).toBe(0);
        expect(new URL(page.url()).pathname).toBe('/portal/dashboard.html');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    });
}
