import { test, expect } from '@playwright/test';

test('falha de ativação mantém reconciliação acessível com ponte de toast ativa', async ({ page }) => {
    await page.route('**/api/backoffice/catalog/modules/MOD_1/activate', route => route.abort('failed'));
    await page.goto('/backoffice/modulos');
    await page.locator('#module-list [data-module-action="view"]').first().evaluate(button => { button.dataset.moduleAction = 'activate'; });
    await page.locator('#module-list [data-module-action="activate"]').click();
    const reconciliation = page.locator('[data-module-reconciliation]');
    await expect(reconciliation).toBeVisible();
    await expect(reconciliation).toContainText('Não foi possível confirmar');
    await expect(reconciliation.getByRole('button', { name: 'Já conferi o catálogo' })).toBeDisabled();
    const reload = reconciliation.getByRole('button', { name: 'Recarregar catálogo' });
    await reload.focus();
    await page.keyboard.press('Enter');
    await expect(reconciliation.getByRole('button', { name: 'Já conferi o catálogo' })).toBeEnabled();
    await expect(reconciliation.getByRole('button', { name: 'Já conferi o catálogo' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(reconciliation).toHaveCount(0);
    await expect(page.locator('#module-query')).toBeFocused();
});

test('reconciliação não rouba foco movido durante consulta', async ({ page }) => {
    await page.route('**/api/backoffice/catalog/modules/MOD_1/activate', route => route.abort('failed'));
    await page.goto('/backoffice/modulos');
    await page.locator('#module-list [data-module-action="view"]').first().evaluate(button => { button.dataset.moduleAction = 'activate'; });
    await page.locator('#module-list [data-module-action="activate"]').click();
    const reconciliation = page.locator('[data-module-reconciliation]');
    await expect(reconciliation).toBeVisible();
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    let intercepted;
    const started = new Promise(resolve => { intercepted = resolve; });
    await page.route('**/api/backoffice/catalog', async route => { intercepted(); await gate; await route.continue(); });
    await reconciliation.getByRole('button', { name: 'Recarregar catálogo' }).click();
    await started;
    await page.locator('#module-query').focus();
    release();
    await expect(reconciliation.getByRole('button', { name: 'Já conferi o catálogo' })).toBeEnabled();
    await expect(page.locator('#module-query')).toBeFocused();
});
