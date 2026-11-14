import { test, expect } from '@playwright/test';

test('resultado de envio após restauração exige conferência sem reenviar', async ({ page }) => {
    let releasePost;
    let postStarted;
    const gate = new Promise((resolve) => { releasePost = resolve; });
    const started = new Promise((resolve) => { postStarted = resolve; });
    let posts = 0;
    await page.route('**/api/backoffice/companies', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        posts++;
        postStarted();
        await gate;
        await route.fulfill({ status: 201, json: {} });
    });
    await page.goto('/backoffice/empresas');
    await page.locator('#company-create').click();
    for (const [id, value] of [
        ['company-document-number', '11222333000181'], ['company-legal-name', 'Empresa Exemplo'],
        ['company-admin-name', 'Admin Exemplo'], ['company-admin-cpf', '52998224725'],
        ['company-admin-email', 'exemplo@example.test'], ['company-admin-password', 'SenhaExemplo123!'],
    ]) await page.locator(`#${id}`).fill(value);
    await page.locator('#company-form-submit').click();
    await started;
    await page.evaluate(() => {
        document.querySelector('[data-backoffice-mount]').dataset.oldSessionSentinel = 'antigo';
        document.querySelector('.admin-global-search input').value = 'registro antigo';
        const results = document.querySelector('#admin-global-search-results');
        results.textContent = 'resultado antigo';
        results.hidden = false;
        document.querySelector('#admin-header-toast-content').textContent = 'aviso antigo';
    });
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await expect(page.locator('html')).not.toHaveAttribute('inert', '');
    await expect(page.locator('[data-backoffice-mount]')).not.toHaveAttribute('data-old-session-sentinel', 'antigo');
    await expect(page.locator('#company-drawer')).toBeHidden();
    await expect(page.locator('.admin-global-search input')).toHaveValue('');
    await expect(page.locator('#admin-global-search-results')).toBeHidden();
    await expect(page.locator('#admin-global-search-results')).toBeEmpty();
    await expect(page.locator('#admin-header-toast-content')).toBeEmpty();
    releasePost();
    await expect(page.locator('#company-operation-page-status')).toContainText('Não foi possível confirmar');
    await page.locator('#company-create').click();
    await page.locator('#company-form').evaluate((form) => { form.noValidate = true; });
    await page.locator('#company-form-submit').click();
    await expect(page.locator('#company-form-error')).toContainText('Confira a lista');
    expect(posts).toBe(1);
});

test('restauração preserva operação pendente somente após revalidar identidade', async ({ page }) => {
    let releaseAuth;
    let authRequested;
    const requested = new Promise((resolve) => { authRequested = resolve; });
    const release = new Promise((resolve) => { releaseAuth = resolve; });
    await page.goto('/backoffice/empresas');
    await expect(page.locator('#company-create')).toBeVisible();
    const admin = await page.evaluate(() => window.__backofficeAdmin);
    await page.route('**/api/backoffice/auth/me', async (route) => {
        authRequested();
        await release;
        await route.fulfill({ json: { admin } });
    });
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await requested;
    await expect(page.locator('html')).toHaveAttribute('inert', '');
    await expect(page.locator('html')).toHaveCSS('visibility', 'hidden');
    releaseAuth();
    await expect(page.locator('html')).not.toHaveAttribute('inert', '');
    await expect(page.locator('#company-create')).toBeVisible();
});

test('identidade diferente mantém tela anterior oculta até nova autenticação', async ({ page }) => {
    await page.goto('/backoffice/empresas');
    await expect(page.locator('#company-create')).toBeVisible();
    let releaseReload;
    const holdReload = new Promise((resolve) => { releaseReload = resolve; });
    let calls = 0;
    await page.route('**/api/backoffice/auth/me', async (route) => {
        calls++;
        if (calls > 1) await holdReload;
        await route.fulfill({ json: { admin: { id: 'OUTRO', role: 'superadministrador', permissions: [] } } });
    });
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await expect.poll(() => calls).toBeGreaterThanOrEqual(2);
    await expect(page.locator('#company-create')).toHaveCount(0);
    releaseReload();
});

test('falha de autenticação não revela a tela restaurada', async ({ page }) => {
    await page.goto('/backoffice/empresas');
    await expect(page.locator('#company-create')).toBeVisible();
    let releaseReload;
    const holdReload = new Promise((resolve) => { releaseReload = resolve; });
    let calls = 0;
    await page.route('**/api/backoffice/auth/me', async (route) => {
        calls++;
        if (calls > 1) await holdReload;
        await route.fulfill({ status: 401, json: { message: 'Não autenticado' } });
    });
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await expect.poll(() => calls).toBeGreaterThanOrEqual(2);
    await expect(page.locator('#company-create')).toHaveCount(0);
    releaseReload();
});

test('suspensões sucessivas não mantêm a interface oculta após confirmação', async ({ page }) => {
    await page.goto('/backoffice/empresas');
    await expect(page.locator('#company-create')).toBeVisible();
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await expect(page.locator('#company-create')).toBeVisible();
    await expect(page.locator('html')).not.toHaveAttribute('inert', '');
});
