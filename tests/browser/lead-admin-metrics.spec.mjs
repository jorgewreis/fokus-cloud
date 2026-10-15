import { test, expect } from '@playwright/test';

test('métricas do Lead são separadas e removidas ao consultar empresa sem operação', async ({ page }) => {
    const rows = ['A', 'B'].map((id) => ({ id, legal_name: `Empresa ${id}`, document_type: 'cnpj', document_number: '11222333000181', status: 'ativa', subscriptions_count: 1 }));
    await page.route('**/api/backoffice/companies?*', route => route.fulfill({ json: { data: rows, meta: { total: 2, current_page: 1, last_page: 1 }, summary: {} } }));
    await page.route('**/api/backoffice/companies/*', route => {
        const id = new URL(route.request().url()).pathname.split('/').at(-1);
        return route.fulfill({ json: { company: { ...rows.find(row => row.id === id), data_volume: { lead: id === 'A' ? { available: true, metrics: { properties_count: 12, developments_count: 2, contacts_count: 8, inquiries_count: 4, referenced_users_count: 3, storage_bytes: 2048 } } : { available: false, reason: 'not_provisioned', metrics: null } } } } });
    });
    await page.goto('/backoffice/empresas');
    await page.locator('[data-company-action="details"][data-company-id="A"]').click();
    await expect(page.locator('#company-view-lead-metrics')).toBeVisible();
    await expect(page.locator('#company-view-lead-properties')).toHaveText('12');
    await expect(page.locator('#company-view-lead-storage')).toHaveText('2 KB');
    await page.locator('#company-drawer-close').click();
    await page.locator('[data-company-action="details"][data-company-id="B"]').click();
    await expect(page.locator('#company-view-lead-metrics')).toBeHidden();
    await expect(page.locator('#company-view-lead-status')).toContainText('ainda não possui uma operação');
    await expect(page.locator('#company-view-lead-properties')).toHaveText('-');
});
