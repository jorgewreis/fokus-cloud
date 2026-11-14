import { test, expect } from '@playwright/test';

test('o filtro usa produtos autorizados e códigos históricos', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: {
        role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'],
    } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [
        { code: 'new', name: 'Produto Novo' }, { code: 'retired', name: 'retired' },
    ], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let selected = '';
    await page.route('**/api/backoffice/product-interests?*', route => {
        selected = new URL(route.request().url()).searchParams.get('product') || '';
        return route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } });
    });
    await page.goto('/backoffice/interesses');
    const product = page.locator('#interest-product-filter');
    await expect(page.locator('#interest-product-options option')).toHaveCount(2);
    await expect(page.locator('#interest-product-options option').first()).toHaveAttribute('label', 'Produto Novo');
    await expect(page.getByText("Cannot read properties of null", { exact: false })).toHaveCount(0);
    await product.fill('retired');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect.poll(() => selected).toBe('retired');
});

test('falha de catálogo informa o operador sem inventar opções', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: {
        role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'],
    } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ status: 503, json: { message: 'Indisponível' } }));
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } }));
    await page.goto('/backoffice/interesses');
    await expect(page.locator('#interest-product-error')).toBeVisible();
    await expect(page.locator('#interest-product-options option')).toHaveCount(0);
    await page.locator('#interest-product-filter').fill('legacy');
    await page.getByRole('button', { name: 'Filtrar' }).click();
});

test('sugestões históricas pendentes permitem código manual e navegação paginada', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: {
        role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'],
    } } }));
    const pages = [];
    await page.route('**/api/backoffice/product-interests-options?*', route => {
        const current = Number(new URL(route.request().url()).searchParams.get('page'));
        pages.push(current);
        return route.fulfill({ json: { products: [{ code: `catalog-${current}`, name: `Catálogo ${current}` }],
            meta: { current_page: current, has_more: current === 1 }, historical_index_ready: false } });
    });
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } }));
    await page.goto('/backoffice/interesses');
    await expect(page.locator('#interest-product-error')).toContainText('históricos ainda não está pronto');
    await page.getByRole('button', { name: 'Mais produtos' }).click();
    await expect.poll(() => pages.at(-1)).toBe(2);
    await expect(page.locator('#interest-product-options option')).toHaveAttribute('value', 'catalog-2');
    await page.getByRole('button', { name: 'Produtos anteriores' }).click();
    await expect.poll(() => pages.at(-1)).toBe(1);
    await page.locator('#interest-product-filter').fill('historico-manual');
    await page.getByRole('button', { name: 'Filtrar' }).click();
});
