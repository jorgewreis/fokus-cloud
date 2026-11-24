import { test, expect } from '@playwright/test';

test('convite aguarda o handler com import pendente e funciona após remontar a página', async ({ page }) => {
    let releaseImport;
    let markImportRequested;
    const importGate = new Promise((resolve) => { releaseImport = resolve; });
    const importRequested = new Promise((resolve) => { markImportRequested = resolve; });
    await page.route('**/backoffice/assets/js/users-page.js*', async (route) => {
        markImportRequested();
        await importGate;
        await route.continue();
    });
    try {
        await page.goto('/backoffice/usuarios');
        await importRequested;
        await expect(page.getByRole('status')).toContainText('Carregando página');
        await expect(page.locator('#user-invite')).toHaveCount(0);
        await expect(page.locator('#user-drawer')).toHaveCount(0);
    } finally {
        releaseImport();
    }
    await expect(page.locator('#user-invite')).toBeEnabled();
    await page.locator('#user-invite').click();
    await expect(page.locator('#user-drawer')).toBeVisible();
    await page.locator('#user-drawer-close').click();
    await expect(page.locator('#user-drawer')).toBeHidden();

    await page.evaluate(() => window.__backofficeRouter.navigate('platform-dashboard-final'));
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'platform-dashboard-final');
    await page.evaluate(() => window.__backofficeRouter.navigate('users'));
    await expect(page.locator('#user-invite')).toBeEnabled();
    await page.locator('#user-invite').click();
    await expect(page.locator('#user-drawer')).toHaveCount(1);
    await expect(page.locator('#user-drawer')).toBeVisible();
    await expect(page.locator('#user-form')).toBeVisible();
});

test('convite permanece desabilitado sem papel ou permissão de gestão', async ({ page }) => {
    for (const admin of [
        { role: 'administrador_comercial', permissions: ['platform.users.view', 'platform.security.manage'] },
        { role: 'superadministrador', permissions: ['platform.users.view'] },
    ]) {
        await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({ admin: { id: 'PAD_VISUAL', name: 'Administração de teste', ...admin } }),
        }));
        await page.goto('/backoffice/usuarios');
        await expect(page.locator('#users-list tr')).toHaveCount(2);
        await expect(page.locator('#user-invite')).toBeHidden();
        await expect(page.locator('#user-invite')).toBeDisabled();
        await expect(page.locator('#user-drawer')).toBeHidden();
        await page.unroute('**/api/backoffice/auth/me');
    }
});

test('falha da listagem usa toast de erro e não bloqueia convite autorizado', async ({ page }) => {
    await page.route('**/api/backoffice/directory/users?**', (route) => route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Listagem temporariamente indisponível.' }),
    }));
    await page.goto('/backoffice/usuarios');
    const errorToast = page.locator('#backoffice-toast-container .fs-toast-danger');
    await expect(errorToast).toBeVisible();
    await expect(errorToast.locator('.fs-toast-body')).toHaveText('Listagem temporariamente indisponível.');
    await expect(page.locator('#user-invite')).toBeEnabled();
    await page.locator('#user-invite').click();
    await expect(page.locator('#user-drawer')).toBeVisible();
    await expect(page.locator('#user-form')).toBeVisible();
    await page.locator('#user-drawer-close').click();
    await expect(page.locator('#users-message')).toContainText('Listagem temporariamente indisponível.');
});
