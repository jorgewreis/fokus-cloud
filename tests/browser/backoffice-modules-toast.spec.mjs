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
    await reconciliation.getByRole('button', { name: 'Já conferi o catálogo' }).click();
    await expect(reconciliation).toHaveCount(0);
});
