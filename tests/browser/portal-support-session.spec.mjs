import { test, expect } from '@playwright/test';

const supportDenied = { code: 'support_customer_api_denied', message: 'O acesso de suporte não pode acessar APIs operacionais de clientes.' };

async function mockSupportSession(page) {
    const calls = { exit: 0, subscriptions: 0, logout: 0 };
    await page.route('**/api/auth/me', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 403, json: supportDenied }));
    await page.route('**/api/subscriptions', (route) => { calls.subscriptions += 1; return route.fulfill({ status: 403, json: supportDenied }); });
    await page.route('**/api/auth/logout', (route) => { calls.logout += 1; return route.fulfill({ status: 403, json: supportDenied }); });
    await page.route('**/api/csrf-token', (route) => route.fulfill({ json: { token: 'support-test-token' } }));
    await page.route('**/api/backoffice/support/exit', (route) => { calls.exit += 1; return route.fulfill({ json: { redirect_to: '/backoffice/' } }); });
    return calls;
}

for (const width of [375, 1440]) {
    test(`support session on the dashboard shows no customer data and can exit at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        const calls = await mockSupportSession(page);
        await page.goto('/portal/dashboard.html');
        const notice = page.locator('.portal-content > .fs-alert-warning');
        await expect(notice).toBeVisible();
        await expect(notice).toContainText('Modo de suporte ativo');
        await expect(notice).toContainText('Os dados do cliente não ficam disponíveis durante o acesso de suporte.');
        await expect(page.locator('#role')).toHaveText('Suporte');
        await expect(page.locator('#subscriptions')).toBeHidden();
        await expect(page.locator('#renewal')).toBeHidden();
        await expect(page.locator('#portal-user-name')).toHaveText('Acesso de suporte');
        // The dashboard must stay on the page instead of redirecting to the login.
        await page.waitForTimeout(1200);
        await expect(page).toHaveURL(/\/portal\/dashboard\.html$/);
        expect(calls.subscriptions).toBe(0);
        await notice.getByRole('button', { name: 'Encerrar acesso de suporte' }).click();
        await expect(page).toHaveURL(/\/backoffice\/$/);
        expect(calls.exit).toBe(1);
    });
}

test('logout button ends the support session instead of the customer logout', async ({ page }) => {
    const calls = await mockSupportSession(page);
    await page.goto('/portal/dashboard.html');
    await expect(page.locator('.portal-content > .fs-alert-warning')).toBeVisible();
    await page.locator('#portal-logout').click();
    await expect(page).toHaveURL(/\/backoffice\/$/);
    expect(calls.exit).toBe(1);
    expect(calls.logout).toBe(0);
});

test('guest on the dashboard is still sent to the login and never sees the support exit', async ({ page }) => {
    await page.route('**/api/auth/me', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
    await page.route('**/acesso', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Acesso</title><main>Acesso</main>' }));
    await page.goto('/portal/dashboard.html');
    await expect(page).toHaveURL(/\/acesso$/);
});
