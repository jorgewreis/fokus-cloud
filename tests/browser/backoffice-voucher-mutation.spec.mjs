import { test, expect } from '@playwright/test';

const voucher = { id: 'VCH_TEST', name: 'Teste sintético', code: 'TEST1234', status: 'ativa', computed_status: 'ativa', discount_type: 'percentage', discount_value: 10, product_name: 'Produto sintético', redemptions_count: 0 };
const stubList = async (page) => page.route('**/api/backoffice/vouchers?**', (route) => route.fulfill({ json: { data: [voucher], meta: { total: 1, last_page: 1 } } }));

test('pausa simultânea envia somente uma requisição', async ({ page }) => {
    let count = 0;
    let release;
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: { id: 'PAD_TEST', name: 'Admin sintético', role: 'superadministrador', permissions: ['platform.vouchers.manage', 'platform.catalog.publish', 'platform.dashboard.view'] } } }));
    await stubList(page);
    await page.route('**/api/backoffice/vouchers/VCH_TEST', async (route) => {
        count += 1;
        await new Promise((resolve) => { release = resolve; });
        await route.fulfill({ status: 422, json: { message: 'Recusado.' } });
    });
    await page.goto('/backoffice/vouchers');
    await page.locator('[data-voucher-action="pause"]').evaluate((button) => { button.click(); button.click(); });
    await expect.poll(() => count).toBe(1);
    await page.waitForTimeout(200);
    expect(count).toBe(1);
    release();
});

const authorize = (page) => page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: { id: 'PAD_TEST', name: 'Admin sintético', role: 'superadministrador', permissions: ['platform.vouchers.manage', 'platform.catalog.publish', 'platform.dashboard.view'] } } }));

for (const result of ['success', 'network-error']) {
    test(`pausa mantém reserva após navegar e voltar: ${result}`, async ({ page }) => {
        let count = 0;
        let release;
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await authorize(page);
        await stubList(page);
        await page.route('**/api/backoffice/vouchers/VCH_TEST', async (route) => {
            count += 1;
            await new Promise((resolve) => { release = resolve; });
            if (result === 'success') await route.fulfill({ json: { message: 'Atualizado.' } });
            else await route.abort('failed');
        });
        await page.goto('/backoffice/vouchers');
        await page.locator('[data-voucher-action="pause"]').click();
        await expect.poll(() => count).toBe(1);
        await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
        await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'companies');
        await page.evaluate(() => window.__backofficeRouter.navigate('vouchers'));
        await expect(page.locator('[data-voucher-action="pause"]')).toBeDisabled();
        await page.locator('[data-voucher-action="pause"]').evaluate((button) => button.dispatchEvent(new MouseEvent('click', { bubbles: true })));
        release();
        await expect(page.locator('#voucher-message')).toContainText('Atualize a página e confira a situação do voucher');
        await expect(page.locator('[data-voucher-action="pause"]')).toBeDisabled();
        expect(count).toBe(1);
        expect(errors).toEqual([]);
    });
}

for (const status of [408, 422, 503]) {
    test(`pausa trata HTTP ${status} sem repetição automática`, async ({ page }) => {
        let count = 0;
        await authorize(page);
        await stubList(page);
        await page.route('**/api/backoffice/vouchers/VCH_TEST', async (route) => { count += 1; await route.fulfill({ status, json: { message: 'Recusado.' } }); });
        await page.goto('/backoffice/vouchers');
        await page.locator('[data-voucher-action="pause"]').click();
        if (status === 422) {
            await expect(page.locator('#voucher-message')).toContainText('Recusado.');
            await expect(page.locator('[data-voucher-action="pause"]')).toBeEnabled();
        } else {
            await expect(page.locator('#voucher-message')).toContainText('Atualize a página e confira a situação do voucher');
            await expect(page.locator('[data-voucher-action="pause"]')).toBeDisabled();
        }
        expect(count).toBe(1);
    });
}

test('arquivamento bloqueia submissões concorrentes', async ({ page }) => {
    let count = 0;
    let release;
    await authorize(page);
    await stubList(page);
    await page.route('**/api/backoffice/vouchers/VCH_TEST/archive', async (route) => {
        count += 1;
        await new Promise((resolve) => { release = resolve; });
        await route.fulfill({ status: 422, json: { message: 'Recusado.' } });
    });
    await page.goto('/backoffice/vouchers');
    await page.locator('[data-voucher-action="archive"]').click();
    await page.locator('#voucher-action-reason').fill('Teste sintético');
    await page.locator('#voucher-action-form').evaluate((form) => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    await expect.poll(() => count).toBe(1);
    await page.waitForTimeout(200);
    expect(count).toBe(1);
    release();
});

test('resultado confirmado com listagem indisponível mantém voucher reservado', async ({ page }) => {
    let loads = 0;
    await authorize(page);
    await page.route('**/api/backoffice/vouchers?**', async (route) => {
        loads += 1;
        if (loads > 1) return route.fulfill({ status: 503, json: { message: 'Falha na consulta.' } });
        await route.fulfill({ json: { data: [voucher], meta: { total: 1, last_page: 1 } } });
    });
    await page.route('**/api/backoffice/vouchers/VCH_TEST', (route) => route.fulfill({ json: { message: 'Atualizado.' } }));
    await page.goto('/backoffice/vouchers');
    await page.locator('[data-voucher-action="pause"]').click();
    await expect(page.locator('#voucher-message')).toContainText('Atualize a página e confira a situação do voucher');
    await expect(page.locator('#voucher-create')).toBeDisabled();
});

test('cadastro de voucher impede submissão concorrente', async ({ page }) => {
    let count = 0;
    let release;
    await authorize(page);
    await stubList(page);
    await page.route('**/api/backoffice/catalog', (route) => route.fulfill({ json: { products: [{ id: 'PRD_TEST', name: 'Produto sintético', plans: [{ id: 'PLN_TEST', name: 'Plano sintético', monthly_amount: 10, annual_amount: 100 }] }] } }));
    await page.route('**/api/backoffice/vouchers', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        count += 1;
        await new Promise((resolve) => { release = resolve; });
        await route.fulfill({ status: 422, json: { message: 'Recusado.' } });
    });
    await page.goto('/backoffice/vouchers');
    await page.locator('#voucher-create').click();
    await page.locator('#voucher-name').fill('Campanha sintética');
    await page.locator('#voucher-product').selectOption('PRD_TEST');
    await page.locator('#voucher-plan').selectOption('PLN_TEST');
    await page.locator('#voucher-discount-type').selectOption('percentage');
    await page.locator('#voucher-duration').selectOption('m1');
    await page.locator('#voucher-discount-percent').fill('15');
    await page.locator('#voucher-start-date').fill('2026-11-14');
    await page.locator('#voucher-end-date').fill('2027-11-14');
    await page.locator('#voucher-total').fill('10');
    await page.locator('#voucher-company-limit').fill('1');
    await page.locator('#voucher-form').evaluate((form) => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    await expect.poll(() => count).toBe(1);
    await page.waitForTimeout(200);
    expect(count).toBe(1);
    release();
});
