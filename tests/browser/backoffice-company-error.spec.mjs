import { test, expect } from '@playwright/test';

for (const width of [375, 1440]) {
    test(`empresa informa erro dentro do drawer e impede envio repetido em ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.route('**/*', (route) => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
        let requests = 0;
        let release;
        let requested;
        const gate = new Promise((resolve) => { release = resolve; });
        const firstRequest = new Promise((resolve) => { requested = resolve; });
        await page.route('**/api/backoffice/companies', async (route) => {
            if (route.request().method() !== 'POST') return route.continue();
            requests++;
            if (requests === 1) {
                requested();
                await gate;
                return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Erro sintético de validação' }) });
            }
            if (requests === 2) return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Erro temporário' }) });
            return route.fulfill({ status: 201, contentType: 'application/json', body: '{}' });
        });
        await page.goto('/backoffice/empresas');
        await page.locator('#company-create').click();
        await page.locator('#company-document-number').fill('11222333000181');
        await page.locator('#company-legal-name').fill('Empresa Exemplo');
        await page.locator('#company-admin-name').fill('Admin Exemplo');
        await page.locator('#company-admin-cpf').fill('52998224725');
        await page.locator('#company-admin-email').fill('exemplo@example.test');
        await page.locator('#company-admin-password').fill('SenhaExemplo123!');
        const button = page.locator('#company-form-submit');
        await button.click();
        await firstRequest;
        await expect(button).toBeDisabled();
        await page.locator('#company-form').evaluate((form) => form.requestSubmit());
        expect(requests).toBe(1);
        release();
        const error = page.locator('#company-form-error');
        await expect(error).toHaveText('Erro sintético de validação');
        await expect(error).toBeVisible();
        await expect(error).toBeFocused();
        await expect(button).toBeEnabled();
        await expect(page.locator('#company-legal-name')).toHaveValue('Empresa Exemplo');
        expect(await error.evaluate((node) => {
            const rect = node.getBoundingClientRect();
            const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
            return rect.top >= 0 && rect.bottom <= innerHeight && (hit === node || node.contains(hit));
        })).toBe(true);
        await page.screenshot({ path: `${process.env.TEMP}/company-error-${width}.png` });
        await button.click();
        await expect(error).toHaveText('Erro temporário');
        await expect(error).toBeVisible();
        await expect(button).toBeEnabled();
        await button.click();
        await expect(error).toBeHidden();
        await expect(page.locator('#company-drawer')).toBeHidden();
        expect(requests).toBe(3);
        await page.locator('#company-create').click();
        await expect(error).toBeHidden();
    });
}

test('erro pendente não rouba o foco de outra janela modal', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await page.route('**/*', (route) => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
    let release;
    let requested;
    const gate = new Promise((resolve) => { release = resolve; });
    const requestStarted = new Promise((resolve) => { requested = resolve; });
    await page.route('**/api/backoffice/companies', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        requested();
        await gate;
        return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Erro sintético' }) });
    });
    await page.goto('/backoffice/empresas');
    await page.locator('#company-create').click();
    await page.locator('#company-document-number').fill('11222333000181');
    await page.locator('#company-legal-name').fill('Empresa Exemplo');
    await page.locator('#company-admin-name').fill('Admin Exemplo');
    await page.locator('#company-admin-cpf').fill('52998224725');
    await page.locator('#company-admin-email').fill('exemplo@example.test');
    await page.locator('#company-admin-password').fill('SenhaExemplo123!');
    await page.locator('#company-form-submit').click();
    await requestStarted;
    await page.evaluate(() => {
        const modal = document.createElement('div');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML = '<button id="other-modal-button">Outra janela</button>';
        document.body.append(modal);
        modal.querySelector('button').focus();
    });
    await expect(page.locator('#other-modal-button')).toBeFocused();
    release();
    await expect(page.locator('#company-form-error')).toHaveText('Erro sintético');
    await expect(page.locator('#other-modal-button')).toBeFocused();
});

const fakeCompany = {
    id: 'CMP_TEST', legal_name: 'Empresa Exemplo', document_type: 'cnpj', document_number: '11222333000181',
    status: 'ativa', version: 3, active_subscriptions: 0,
    admin_name: 'Admin Exemplo', admin_email: 'exemplo@example.test',
    admin: { name: 'Admin Exemplo', cpf: '52998224725', email: 'exemplo@example.test' },
    data_volume: {},
};

for (const status of [200, 422]) {
    test(`PATCH tardio ${status} não altera drawer reaberto nem página remontada`, async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.route('**/*', (route) => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
        let release;
        let markRequested;
        const gate = new Promise((resolve) => { release = resolve; });
        const requested = new Promise((resolve) => { markRequested = resolve; });
        let listRequests = 0;
        let patchBody;
        await page.route('**/api/backoffice/companies**', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() === 'PATCH') {
                patchBody = request.postDataJSON();
                markRequested();
                await gate;
                return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(status === 200 ? {} : { message: 'Erro obsoleto' }) });
            }
            if (url.pathname.endsWith('/CMP_TEST')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ company: fakeCompany }) });
            listRequests++;
            return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [fakeCompany], meta: { total: 1, current_page: 1, per_page: 15, last_page: 1 }, summary: {} }) });
        });
        await page.goto('/backoffice/empresas');
        await page.getByRole('button', { name: 'Editar empresa' }).click();
        await expect(page.locator('#company-drawer')).toBeVisible();
        await page.locator('#company-legal-name').fill('Alteração sintética');
        await page.locator('#company-form-submit').click();
        await requested;
        expect(patchBody).toMatchObject({ legal_name: 'Alteração sintética', version: 3 });
        await expect(page.locator('#company-form-submit')).toBeDisabled();
        await page.locator('#company-form-cancel').click();
        await expect(page.locator('#company-drawer')).toBeHidden();
        await page.locator('#company-create').click();
        await expect(page.locator('#company-drawer')).toBeVisible();
        await expect(page.locator('#company-form-submit')).toBeDisabled();
        const before = listRequests;
        const response = page.waitForResponse((item) => item.request().method() === 'PATCH' && item.url().includes('/CMP_TEST'));
        release();
        await response;
        await expect(page.locator('#company-form-submit')).toBeEnabled();
        await expect(page.locator('#company-drawer')).toBeVisible();
        await expect(page.locator('#company-drawer-title')).toHaveText('Nova empresa');
        await expect(page.locator('#company-form-error')).toBeHidden();
        expect(listRequests).toBe(before);
        await expect(page.locator('.fs-backoffice-toast')).toHaveCount(0);
    });
}

test('POST tardio após navegação SPA não afeta nova montagem de empresas', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.route('**/*', (route) => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
    let release;
    let requested;
    const gate = new Promise((resolve) => { release = resolve; });
    const started = new Promise((resolve) => { requested = resolve; });
    let listRequests = 0;
    await page.route('**/api/backoffice/companies**', async (route) => {
        if (route.request().method() === 'POST') {
            requested();
            await gate;
            return route.fulfill({ status: 201, contentType: 'application/json', body: '{}' });
        }
        listRequests++;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [], meta: { total: 0, current_page: 1, per_page: 15, last_page: 1 }, summary: {} }) });
    });
    await page.goto('/backoffice/empresas');
    await page.locator('#company-create').click();
    await page.locator('#company-document-number').fill('11222333000181');
    await page.locator('#company-legal-name').fill('Empresa Exemplo');
    await page.locator('#company-admin-name').fill('Admin Exemplo');
    await page.locator('#company-admin-cpf').fill('52998224725');
    await page.locator('#company-admin-email').fill('exemplo@example.test');
    await page.locator('#company-admin-password').fill('SenhaExemplo123!');
    await page.locator('#company-form-submit').click();
    await started;
    await page.evaluate(() => window.__backofficeRouter.navigate('platform-dashboard-final'));
    await expect(page.locator('[data-backoffice-page="platform-dashboard-final"]')).toBeVisible();
    await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
    await expect(page.locator('#company-create')).toBeVisible();
    await page.locator('#company-create').click();
    await expect(page.locator('#company-drawer')).toBeVisible();
    const before = listRequests;
    const response = page.waitForResponse((item) => item.request().method() === 'POST' && item.url().endsWith('/companies'));
    release();
    await response;
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));
    await expect(page.locator('#company-drawer')).toBeVisible();
    await expect(page.locator('#company-form-error')).toBeHidden();
    await expect(page.locator('.fs-backoffice-toast')).toHaveCount(0);
    expect(listRequests).toBe(before);
});
