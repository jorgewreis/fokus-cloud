import { test, expect } from '@playwright/test';

const initialAdmin = { id: 'PAD_A', role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'] };

for (const outcome of ['igual', 'outra-identidade', 'negado']) {
    test(`acesso direto a interesses revalida antes de restaurar: ${outcome}`, async ({ page }) => {
        let authCount = 0, optionsCount = 0, listCount = 0;
        let allowAuth;
        let revalidationStarted;
        const gate = new Promise(resolve => { allowAuth = resolve; });
        const started = new Promise(resolve => { revalidationStarted = resolve; });
        await page.route('**/api/backoffice/auth/me', async route => {
            authCount++;
            if (authCount === 1) return route.fulfill({ json: { admin: initialAdmin } });
            revalidationStarted();
            await gate;
            if (outcome === 'negado') return route.fulfill({ status: 401, json: { message: 'Não autorizado' } });
            return route.fulfill({ json: { admin: outcome === 'igual' ? initialAdmin : { ...initialAdmin, id: 'PAD_B' } } });
        });
        await page.route('**/api/backoffice/product-interests-options?*', route => { optionsCount++; return route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }); });
        await page.route('**/api/backoffice/product-interests?*', route => { listCount++; return route.fulfill({ json: { data: [{ id:'OLD', name:'Pessoa antiga', email:'test@example.test', products:[], profiles:[], status:'novo', last_submitted_at:'2026-01-01T00:00:00Z' }], meta: { total: 1, current_page: 1, last_page: 1 } } }); });
        await page.goto('/backoffice/interesses');
        await expect(page.locator('#interest-list')).toContainText('Pessoa antiga');
        await expect.poll(() => optionsCount).toBe(1);
        const previousCalls = { optionsCount, listCount };
        let reloads = 0;
        if (outcome !== 'igual') await page.route('**/backoffice/interesses', route => { reloads++; return route.abort(); });
        await page.evaluate(() => {
            const root = document.querySelector('[data-backoffice-mount]');
            root.dataset.oldSessionSentinel = 'antigo';
            window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
            window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
            window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
        });
        await started;
        expect(authCount).toBe(2);
        await expect(page.locator('html')).toHaveCSS('visibility', 'hidden');
        await expect(page.locator('html')).toHaveAttribute('inert', '');
        expect({ optionsCount, listCount }).toEqual(previousCalls);
        allowAuth();
        if (outcome === 'igual') {
            await expect(page.locator('[data-backoffice-mount]')).not.toHaveAttribute('data-old-session-sentinel', 'antigo');
            await expect(page.locator('[data-backoffice-mount]')).toBeVisible();
            await expect.poll(() => listCount).toBe(2);
            await expect.poll(() => optionsCount).toBe(2);
        } else {
            await expect.poll(() => reloads).toBe(1);
            expect({ optionsCount, listCount }).toEqual(previousCalls);
        }
    });
}

test('coordenador existente de empresas é o único responsável pela restauração de interesses', async ({ page }) => {
    let authCount = 0, listCount = 0, releaseAuth, startedAuth;
    const gate = new Promise(resolve => { releaseAuth = resolve; });
    const started = new Promise(resolve => { startedAuth = resolve; });
    await page.route('**/api/backoffice/auth/me', async route => {
        authCount++;
        if (authCount > 1) { startedAuth(); await gate; }
        return route.fulfill({ json: { admin: initialAdmin } });
    });
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    await page.route('**/api/backoffice/product-interests?*', route => { listCount++; return route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } }); });
    await page.goto('/backoffice/empresas');
    await expect(page.locator('#company-create')).toBeVisible();
    await page.evaluate(() => window.__backofficeRouter.navigate('product-interests'));
    await expect(page.locator('#interest-list')).toBeVisible();
    await expect.poll(() => listCount).toBe(1);
    await page.evaluate(() => {
        document.querySelector('[data-backoffice-mount]').dataset.oldSessionSentinel = 'antigo';
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await started;
    await expect(page.locator('html')).toHaveCSS('visibility', 'hidden');
    expect(authCount).toBe(2);
    expect(listCount).toBe(1);
    releaseAuth();
    await expect(page.locator('html')).not.toHaveCSS('visibility', 'hidden');
    await expect(page.locator('[data-backoffice-mount]')).not.toHaveAttribute('data-old-session-sentinel', 'antigo');
    await expect.poll(() => listCount).toBe(2);
    expect(authCount).toBe(2);
});

test('restauração antiga não interfere após uma segunda suspensão', async ({ page }) => {
    let authCount = 0, firstStarted, releaseFirst, firstFinished;
    const finished = new Promise(resolve => { firstFinished = resolve; });
    const started = new Promise(resolve => { firstStarted = resolve; });
    const gate = new Promise(resolve => { releaseFirst = resolve; });
    await page.route('**/api/backoffice/auth/me', async route => {
        authCount++;
        if (authCount === 2) { firstStarted(); await gate; await route.fulfill({ json: { admin: initialAdmin } }); firstFinished(); return; }
        return route.fulfill({ json: { admin: initialAdmin } });
    });
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } }));
    await page.goto('/backoffice/interesses');
    await expect(page.locator('#interest-list')).toBeVisible();
    await page.evaluate(() => { window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })); window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); });
    await firstStarted;
    await page.evaluate(() => { window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })); window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); });
    await expect.poll(() => authCount).toBe(3);
    await expect(page.locator('html')).not.toHaveCSS('visibility', 'hidden');
    await expect(page.locator('#interest-list')).toBeVisible();
    releaseFirst();
    await finished;
    await expect(page.locator('#interest-list')).toBeVisible();
});
