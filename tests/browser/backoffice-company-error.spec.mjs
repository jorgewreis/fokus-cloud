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
