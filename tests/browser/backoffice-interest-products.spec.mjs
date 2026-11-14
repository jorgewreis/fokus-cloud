import { test, expect } from '@playwright/test';

test('o filtro usa produtos autorizados e códigos históricos', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: {
        role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'],
    } } }));
    await page.route('**/api/backoffice/product-interests-options', route => route.fulfill({ json: { products: [
        { code: 'new', name: 'Produto Novo' }, { code: 'retired', name: 'retired' },
    ] } }));
    let selected = '';
    await page.route('**/api/backoffice/product-interests?*', route => {
        selected = new URL(route.request().url()).searchParams.get('product') || '';
        return route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } });
    });
    await page.goto('/backoffice/interesses');
    const product = page.locator('#interest-product-filter');
    await expect(product.locator('option')).toHaveText(['Todos', 'Produto Novo', 'retired']);
    await product.selectOption('retired');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect.poll(() => selected).toBe('retired');
});

test('falha de catálogo informa o operador sem inventar opções', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: {
        role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'],
    } } }));
    await page.route('**/api/backoffice/product-interests-options', route => route.fulfill({ status: 503, json: { message: 'Indisponível' } }));
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } }));
    await page.goto('/backoffice/interesses');
    await expect(page.locator('#interest-product-error')).toBeVisible();
    await expect(page.locator('#interest-product-filter option')).toHaveCount(1);
});
