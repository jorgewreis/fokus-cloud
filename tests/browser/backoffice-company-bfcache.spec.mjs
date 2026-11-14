import { test, expect } from '@playwright/test';

test('resultado de envio após restauração exige conferência sem reenviar', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
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
        window.FokusToast.show('mensagem da sessão anterior');
        window.__oldSessionToast = document.querySelector('#backoffice-toast-container .fs-toast');
    });
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.FokusToast.show('mensagem durante suspensão');
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await expect(page.locator('html')).not.toHaveAttribute('inert', '');
    await expect(page.locator('[data-backoffice-mount]')).not.toHaveAttribute('data-old-session-sentinel', 'antigo');
    await expect(page.locator('#company-drawer')).toBeHidden();
    await expect(page.locator('.admin-global-search input')).toHaveValue('');
    await expect(page.locator('#admin-global-search-results')).toBeHidden();
    await expect(page.locator('#admin-global-search-results')).toBeEmpty();
    await expect(page.locator('#admin-header-toast-content')).toBeEmpty();
    await expect(page.locator('#backoffice-toast-container')).not.toContainText('mensagem da sessão anterior');
    await expect(page.locator('#backoffice-toast-container')).not.toContainText('mensagem durante suspensão');
    await page.evaluate(() => {
        window.FokusToast.show('mensagem da sessão atual');
        window.__oldSessionToast.dispatchEvent(new Event('fs:toast:hidden'));
    });
    await expect(page.locator('#backoffice-toast-container')).toContainText('mensagem da sessão atual');
    expect(pageErrors).toEqual([]);
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

for (const transition of ['bfcache', 'navigation']) {
    test(`detalhe atrasado não altera a tela após ${transition}`, async ({ page }) => {
        const company = { id: 'CMP_TEST', legal_name: 'Empresa Exemplo', document_type: 'cnpj', document_number: '11222333000181', status: 'ativa', version: 3, active_subscriptions: 0, admin: { name: 'Admin Exemplo', cpf: '52998224725', email: 'exemplo@example.test' }, data_volume: {} };
        let releaseDetail, markRequested;
        const gate = new Promise((resolve) => { releaseDetail = resolve; });
        const requested = new Promise((resolve) => { markRequested = resolve; });
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.stack));
        await page.route('**/api/backoffice/companies**', async (route) => {
            if (new URL(route.request().url()).pathname.endsWith('/CMP_TEST')) {
                markRequested();
                await gate;
                return route.fulfill({ json: { company: { ...company, legal_name: 'DADO ANTIGO SENSÍVEL' } } }).catch(() => {});
            }
            return route.fulfill({ json: { data: [company], meta: { total: 1, current_page: 1, per_page: 15, last_page: 1 }, summary: {} } });
        });
        await page.goto('/backoffice/empresas');
        await page.getByRole('button', { name: 'Editar empresa' }).click();
        await requested;
        if (transition === 'bfcache') {
            await page.evaluate(() => {
                window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
                window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
            });
            await expect(page.locator('html')).not.toHaveAttribute('inert', '');
        } else {
            await page.evaluate(() => window.__backofficeRouter.navigate('platform-dashboard-final'));
            await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
        }
        await expect(page.locator('#company-create')).toBeVisible();
        releaseDetail();
        await page.waitForTimeout(250);
        await expect(page.locator('#company-drawer')).toBeHidden();
        await expect(page.locator('body')).not.toContainText('DADO ANTIGO SENSÍVEL');
        expect(errors).toEqual([]);
    });
}

test('erro tardio do dashboard não altera a página seguinte', async ({ page }) => {
    let release, requested;
    const gate = new Promise((resolve) => { release = resolve; });
    const started = new Promise((resolve) => { requested = resolve; });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.stack));
    await page.route('**/api/backoffice/dashboard', async (route) => {
        requested();
        await gate;
        await route.fulfill({ status: 500, json: { message: 'ERRO ANTIGO DO DASHBOARD' } }).catch(() => {});
    });
    await page.goto('/backoffice/painel');
    await started;
    await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
    await expect(page.locator('#company-create')).toBeVisible();
    release();
    await page.waitForTimeout(250);
    await expect(page.locator('body')).not.toContainText('ERRO ANTIGO DO DASHBOARD');
    expect(errors).toEqual([]);
});

test('erro do dashboard ativo continua visível', async ({ page }) => {
    await page.route('**/api/backoffice/dashboard', (route) => route.fulfill({ status: 500, json: {} }));
    await page.goto('/backoffice/painel');
    await expect(page.locator('#dashboard-load-error')).toBeVisible();
});

test('resposta antiga do dashboard não altera montagem nova nem cabeçalho', async ({ page }) => {
    let releaseOld, oldRequested, completedOld;
    const gate = new Promise((resolve) => { releaseOld = resolve; });
    const requested = new Promise((resolve) => { oldRequested = resolve; });
    const completed = new Promise((resolve) => { completedOld = resolve; });
    let calls = 0;
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.stack));
    await page.addInitScript(() => {
        const originalFetch = window.fetch;
        window.fetch = async (...args) => {
            const response = await originalFetch(...args);
            if (response.url.endsWith('/api/backoffice/dashboard')) {
                const originalJson = response.json.bind(response);
                response.json = async () => {
                    const data = await originalJson();
                    if (data.user?.name === 'Pessoa Antiga') setTimeout(() => { window.__oldDashboardResponseConsumed = true; }, 0);
                    return data;
                };
            }
            return response;
        };
    });
    await page.route('**/api/backoffice/dashboard', async (route) => {
        const old = ++calls === 2;
        if (old) { oldRequested(); await gate; }
        await route.fulfill({ json: { user: { name: old ? 'Pessoa Antiga' : 'Pessoa Atual', role: 'Administrador' }, metrics: { active_companies: old ? 991 : 17, active_people: 2, active_subscriptions: 3, mrr: 4 }, subscription_registrations_6m: [], alerts: [], recent_activity: [] } });
        if (old) completedOld();
    });
    await page.goto('/backoffice/painel');
    await requested;
    await page.evaluate(() => { window.__oldDashboardMount = document.querySelector('[data-backoffice-mount]'); });
    await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
    await expect(page.locator('#company-create')).toBeVisible();
    await page.evaluate(() => window.__backofficeRouter.navigate('platform-dashboard-final'));
    await expect(page.locator('#metric-active-companies')).toHaveText('17');
    expect(await page.evaluate(() => window.__oldDashboardMount !== document.querySelector('[data-backoffice-mount]') && !window.__oldDashboardMount.isConnected)).toBe(true);
    const headerBefore = await page.locator('#dashboard-user-name').textContent();
    const response = page.waitForResponse((r) => r.url().endsWith('/api/backoffice/dashboard') && r.status() === 200);
    releaseOld();
    await completed;
    await response;
    await page.waitForFunction(() => window.__oldDashboardResponseConsumed === true);
    await expect(page.locator('#metric-active-companies')).toHaveText('17');
    await expect(page.locator('#dashboard-user-name')).toHaveText(headerBefore);
    await expect(page.locator('body')).not.toContainText('Pessoa Antiga');
    expect(errors).toEqual([]);
});

test('resultado incerto concluído permanece bloqueado após restauração da mesma identidade', async ({ page }) => {
    let posts = 0;
    let committed = 0;
    await page.route('**/api/backoffice/companies', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        posts++;
        committed++;
        return route.abort('failed');
    });
    await page.goto('/backoffice/empresas');
    await page.locator('#company-create').click();
    for (const [id, value] of [
        ['company-document-number', '11222333000181'], ['company-legal-name', 'Empresa Exemplo'],
        ['company-admin-name', 'Admin Exemplo'], ['company-admin-cpf', '52998224725'],
        ['company-admin-email', 'exemplo@example.test'], ['company-admin-password', 'SenhaExemplo123!'],
    ]) await page.locator(`#${id}`).fill(value);
    await page.locator('#company-form-submit').click();
    await expect(page.locator('#company-operation-drawer-status')).toContainText('Não foi possível confirmar');
    expect(committed).toBe(1);
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await expect(page.locator('html')).not.toHaveAttribute('inert', '');
    await expect(page.locator('#company-operation-page-status')).toContainText('Não foi possível confirmar');
    await expect(page.locator('#company-operation-page-status')).not.toContainText('Empresa Exemplo');
    await page.locator('#company-create').click();
    await page.locator('#company-form').evaluate((form) => { form.noValidate = true; });
    await page.locator('#company-form-submit').click();
    await expect(page.locator('#company-form-error')).toContainText('Confira a lista');
    expect(posts).toBe(1);
});
