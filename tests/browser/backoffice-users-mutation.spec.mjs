import { test, expect } from '@playwright/test';

test('convite simultâneo deve enviar uma só requisição', async ({ page }) => {
    let count = 0;
    let release;
    await page.route('**/api/backoffice/admins/invitations', async (route) => {
        count += 1;
        await new Promise((resolve) => { release = resolve; });
        await route.fulfill({ status: 422, json: { message: 'Convite não aceito.' } });
    });
    await page.goto('/backoffice/usuarios');
    await page.locator('#user-invite').click();
    await page.locator('#user-name').fill('Conta Sintética');
    await page.locator('#user-email').fill('conta@example.test');
    await page.locator('#user-form').evaluate((form) => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    await expect.poll(() => count).toBe(1);
    await page.waitForTimeout(200);
    expect(count).toBe(1);
    release();
});

for (const result of ['success', 'network-error']) {
    test(`convite não pode ser repetido ao voltar à página: ${result}`, async ({ page }) => {
        let count = 0;
        let release;
        const pageErrors = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));
        await page.route('**/api/backoffice/admins/invitations', async (route) => {
            count += 1;
            await new Promise((resolve) => { release = resolve; });
            if (result === 'success') await route.fulfill({ status: 201, json: { message: 'Enviado.' } });
            else await route.abort('failed');
        });
        await page.goto('/backoffice/usuarios');
        await page.locator('#user-invite').click();
        await page.locator('#user-name').fill('Conta Sintética');
        await page.locator('#user-email').fill('conta@example.test');
        await page.locator('#user-form-submit').click();
        await expect.poll(() => count).toBe(1);
        await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
        await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'companies');
        await page.evaluate(() => window.__backofficeRouter.navigate('users'));
        await expect(page.locator('#user-invite')).toBeDisabled();
        await page.locator('#user-form').evaluate((form) => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        expect(count).toBe(1);
        release();
        await expect(page.locator('#users-message')).toContainText('Atualize a página e confira a situação da conta');
        await expect(page.locator('#user-invite')).toBeDisabled();
        expect(count).toBe(1);
        expect(pageErrors).toEqual([]);
    });
}

for (const status of [408, 422, 503]) {
    test(`convite trata HTTP ${status} conforme resultado`, async ({ page }) => {
        let count = 0;
        await page.route('**/api/backoffice/admins/invitations', async (route) => {
            count += 1;
            await route.fulfill({ status, json: { message: 'Requisição recusada.' } });
        });
        await page.goto('/backoffice/usuarios');
        await page.locator('#user-invite').click();
        await page.locator('#user-name').fill('Conta Sintética');
        await page.locator('#user-email').fill('conta@example.test');
        await page.locator('#user-form-submit').click();
        if (status === 422) {
            await expect(page.locator('#users-message')).toContainText('Requisição recusada.');
            await expect(page.locator('#user-form-submit')).toBeEnabled();
            await page.locator('#user-form-submit').click();
            await expect.poll(() => count).toBe(2);
        } else {
            await expect(page.locator('#users-message')).toContainText('Atualize a página e confira a situação da conta');
            await expect(page.locator('#user-form-submit')).toBeDisabled();
            expect(count).toBe(1);
        }
    });
}

test('convite externo também bloqueia reenvio e conserva estado após falha incerta', async ({ page }) => {
    let count = 0;
    await page.route('**/api/backoffice/directory/users', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        count += 1;
        await route.fulfill({ status: 408, json: { message: 'Tempo esgotado.' } });
    });
    await page.goto('/backoffice/usuarios');
    await page.locator('#user-invite').click();
    await page.locator('#user-account-type').selectOption('empresa');
    await expect(page.locator('#user-company option[value="CMP_VISUAL"]')).toHaveCount(1);
    await page.locator('#user-name').fill('Conta Sintética');
    await page.locator('#user-email').fill('conta@example.test');
    await page.locator('#user-cpf').fill('52998224725');
    await page.locator('#user-company').selectOption('CMP_VISUAL');
    await page.locator('#user-external-role').selectOption('gestor');
    await page.locator('#user-form-submit').click();
    await expect(page.locator('#users-message')).toContainText('Atualize a página e confira a situação da conta');
    await expect(page.locator('#user-form-submit')).toBeDisabled();
    await page.locator('#user-account-type').selectOption('plataforma');
    await expect(page.locator('#user-form-submit')).toBeDisabled();
    expect(count).toBe(1);
});

test('convite mantém reserva quando listagem falha após confirmação do envio', async ({ page }) => {
    let loads = 0;
    let count = 0;
    await page.route('**/api/backoffice/directory/users?**', async (route) => {
        loads += 1;
        if (loads > 1) return route.fulfill({ status: 503, json: { message: 'Listagem indisponível.' } });
        await route.continue();
    });
    await page.route('**/api/backoffice/admins/invitations', async (route) => {
        count += 1;
        await route.fulfill({ status: 201, json: { message: 'Enviado.' } });
    });
    await page.goto('/backoffice/usuarios');
    await expect(page.locator('#users-list tr')).toHaveCount(2);
    await page.locator('#user-invite').click();
    await page.locator('#user-name').fill('Conta Sintética');
    await page.locator('#user-email').fill('conta@example.test');
    await page.locator('#user-form-submit').click();
    await expect(page.locator('#users-message')).toContainText('Atualize a página e confira a situação da conta');
    await expect(page.locator('#user-invite')).toBeDisabled();
    expect(count).toBe(1);
});

for (const operation of ['profile', 'block']) {
    for (const result of ['success', 'network-error']) {
        test(`${operation} bloqueia replay depois de navegar: ${result}`, async ({ page }) => {
            let count = 0;
            let release;
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.route(`**/api/backoffice/admins/PAD_VISUAL/${operation}`, async (route) => {
                count += 1;
                await new Promise((resolve) => { release = resolve; });
                if (result === 'success') await route.fulfill({ json: { message: 'Operação concluída.' } });
                else await route.abort('failed');
            });
            await page.goto('/backoffice/usuarios');
            await page.locator(`#users-list [data-user-action="${operation === 'profile' ? 'edit' : 'block'}"]`).click();
            if (operation === 'profile') {
                await page.locator('#user-name').fill('Conta Sintética');
                await page.locator('#user-form').evaluate((form) => {
                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                });
            } else {
                await page.locator('#user-action-reason').fill('Teste sintético');
                await page.locator('#user-action-form').evaluate((form) => {
                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                });
            }
            await expect.poll(() => count).toBe(1);
            await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
            await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'companies');
            await page.evaluate(() => window.__backofficeRouter.navigate('users'));
            await expect(page.locator('#user-invite')).toBeDisabled();
            await expect(page.locator(`#users-list [data-user-action="${operation === 'profile' ? 'edit' : 'block'}"]`)).toBeDisabled();
            release();
            await expect(page.locator('#users-message')).toContainText('Atualize a página e confira a situação da conta');
            expect(count).toBe(1);
            expect(errors).toEqual([]);
        });
    }
}

for (const operation of ['profile', 'block']) {
    for (const status of [408, 422]) {
        test(`${operation} trata HTTP ${status} sem reenvio automático`, async ({ page }) => {
            let count = 0;
            await page.route(`**/api/backoffice/admins/PAD_VISUAL/${operation}`, async (route) => {
                count += 1;
                await route.fulfill({ status, json: { message: 'Rejeitada.' } });
            });
            await page.goto('/backoffice/usuarios');
            await page.locator(`#users-list [data-user-action="${operation === 'profile' ? 'edit' : 'block'}"]`).click();
            if (operation === 'profile') await page.locator('#user-form-submit').click();
            else { await page.locator('#user-action-reason').fill('Teste sintético'); await page.locator('#user-action-submit').click(); }
            if (status === 408) {
                await expect(page.locator('#users-message')).toContainText('Atualize a página e confira a situação da conta');
                await expect(page.locator(operation === 'profile' ? '#user-form-submit' : '#user-action-submit')).toBeDisabled();
            } else {
                await expect(page.locator('#users-message')).toContainText('Rejeitada.');
                await expect(page.locator(operation === 'profile' ? '#user-form-submit' : '#user-action-submit')).toBeEnabled();
            }
            expect(count).toBe(1);
        });
    }
}
