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

test('a paginação mantém controles limitados mesmo com muitas páginas', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    await page.route('**/api/backoffice/product-interests?*', route => {
        const current = Number(new URL(route.request().url()).searchParams.get('page') || 1);
        return route.fulfill({ json: { data: [], meta: { total: 1500000, current_page: current, last_page: 100000 } } });
    });
    await page.goto('/backoffice/interesses');
    await expect(page.locator('#interest-pagination button')).toHaveCount(7);
    await page.getByRole('button', { name: '100000', exact: true }).click();
    await expect(page.locator('#interest-pagination [aria-current="page"]')).toHaveText('100000');
    await expect(page.locator('#interest-pagination [aria-current="page"]')).toBeFocused();
    await expect(page.locator('#interest-pagination button')).toHaveCount(7);
    await page.getByRole('button', { name: 'Anterior', exact: true }).click();
    await expect(page.locator('#interest-pagination [aria-current="page"]')).toHaveText('99999');
});

test('respostas atrasadas não substituem o filtro nem atravessam suspensão', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'PAD_TEST', role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let resolveOld;
    const old = new Promise(resolve => { resolveOld = resolve; });
    let oldFinished;
    const oldDone = new Promise(resolve => { oldFinished = resolve; });
    let oldStarted;
    const started = new Promise(resolve => { oldStarted = resolve; });
    const row = (id, name) => ({ id, name, email: 'teste@example.test', products: [], profiles: [], status: 'novo', last_submitted_at: '2026-01-01T00:00:00Z' });
    await page.route('**/api/backoffice/product-interests?*', async route => {
        const product = new URL(route.request().url()).searchParams.get('product');
        if (product === 'antigo') { oldStarted(); await old; }
        await route.fulfill({ json: { data: [row('1', product || 'Inicial')], meta: { total: 1, current_page: 1, last_page: 1 } } });
        if (product === 'antigo') oldFinished();
    });
    let resolveDetail;
    const pendingDetail = new Promise(resolve => { resolveDetail = resolve; });
    let detailFinished;
    const finished = new Promise(resolve => { detailFinished = resolve; });
    let detailStarted;
    const detailReady = new Promise(resolve => { detailStarted = resolve; });
    await page.route('**/api/backoffice/product-interests/1', async route => {
        detailStarted(); await pendingDetail;
        await route.fulfill({ json: { interest: { ...row('1', 'Detalhe antigo'), consented_at: '2026-01-01T00:00:00Z' } } }); detailFinished();
    });
    await page.goto('/backoffice/interesses');
    await expect(page.locator('#interest-list')).toContainText('Inicial');
    await page.locator('#interest-product-filter').fill('antigo');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await started;
    await page.locator('#interest-product-filter').fill('novo');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page.locator('#interest-list')).toContainText('novo');
    resolveOld();
    await oldDone;
    await expect(page.locator('#interest-list')).toContainText('novo');
    await page.getByRole('button', { name: 'Detalhes' }).click();
    await detailReady;
    await page.evaluate(() => { window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })); window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); });
    resolveDetail();
    await finished;
    await expect(page.locator('#interest-detail')).toBeHidden();
});

test('detalhe pendente não gera erro após remoção da página', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    const row = { id: '1', name: 'Cliente fictício', email: 'teste@example.test', products: [], profiles: [], status: 'novo', last_submitted_at: '2026-01-01T00:00:00Z' };
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [row], meta: { total: 1, current_page: 1, last_page: 1 } } }));
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    let finished;
    const completed = new Promise(resolve => { finished = resolve; });
    let begun;
    const started = new Promise(resolve => { begun = resolve; });
    await page.route('**/api/backoffice/product-interests/1', async route => { begun(); await pending; await route.fulfill({ json: { interest: { ...row, name: 'Resposta antiga', consented_at: '2026-01-01T00:00:00Z' } } }); finished(); });
    await page.goto('/backoffice/interesses');
    await page.getByRole('button', { name: 'Detalhes' }).click();
    await started;
    await page.evaluate(() => document.querySelector('[data-backoffice-mount]').remove());
    release();
    await completed;
    await expect.poll(() => errors).toEqual([]);
});

test('filtro e paginação permanecem visíveis em larguras estreitas', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [{code: 'futuro', name: 'Produto futuro'}], meta: { current_page: 1, has_more: true }, historical_index_ready: true } }));
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [], meta: { total: 1500, current_page: 1, last_page: 100 } } }));
    for (const width of [320, 375, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/backoffice/interesses');
        const input = page.locator('#interest-product-filter');
        await expect(input).toBeVisible();
        const box = await input.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
        await input.fill('futuro');
        await expect(input).toHaveValue('futuro');
        if (width === 320 || width === 1440) await page.screenshot({ path: `test-results/interest-${width}.png`, fullPage: true });
    }
});

for (const width of [701, 768, 1280, 1440, 1919, 1920]) test(`controles acessíveis com a barra lateral aberta em ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 720 });
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { role: 'superadministrador', name: 'Teste', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => {
        const current = Number(new URL(route.request().url()).searchParams.get('page'));
        return route.fulfill({ json: { products: [{ code: `p${current}`, name: `Produto ${current}` }], meta: { current_page: current, has_more: current === 1 }, historical_index_ready: true } });
    });
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } }));
    await page.goto('/backoffice/interesses');
    await page.locator('#sidebar [data-sidebar-item="companies"]').hover();
    await expect(page.locator('#sidebar')).toHaveClass(/expanded/);
    const search = await page.locator('.admin-global-search').boundingBox();
    const account = await page.locator('.admin-header-user').boundingBox();
    expect(search.x + search.width).toBeLessThanOrEqual(account.x + 1);
    expect(account.x + account.width).toBeLessThanOrEqual(width + 1);
    const button = page.getByRole('button', { name: 'Mais produtos' });
    await expect(button).toBeVisible();
    await page.screenshot({ path: `test-results/sidebar-open-${width}.png` });
    await button.focus();
    await expect(button).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#interest-product-options option')).toHaveAttribute('value', 'p2');
    await page.getByRole('button', { name: 'Produtos anteriores' }).click();
    await expect(page.locator('#interest-product-options option')).toHaveAttribute('value', 'p1');
    await page.locator('#sidebar [data-sidebar-item="companies"]').hover();
    await expect(page.locator('#sidebar')).toHaveClass(/expanded/);
    await button.click({ timeout: 5000 });
    await expect(page.locator('#interest-product-options option')).toHaveAttribute('value', 'p2');
});

test('falha do novo filtro limpa resultado anterior e permite repetir', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let attempts = 0;
    await page.route('**/api/backoffice/product-interests?*', route => {
        const product = new URL(route.request().url()).searchParams.get('product');
        if (product === 'novo' && ++attempts === 1) return route.fulfill({ status: 503, json: { message: 'Falha de consulta' } });
        return route.fulfill({ json: { data: [{ id: 1, name: product || 'Resultado anterior', email: 'teste@example.test', products: [], profiles: [], status: 'novo', last_submitted_at: '2026-01-01T00:00:00Z' }], meta: { total: 1, current_page: 1, last_page: 1 } } });
    });
    await page.goto('/backoffice/interesses');
    await expect(page.locator('#interest-list')).toContainText('Resultado anterior');
    await page.locator('#interest-product-filter').fill('novo');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page.locator('#interest-list')).not.toContainText('Resultado anterior');
    await page.getByRole('button', { name: 'Tentar novamente' }).click();
    await expect(page.locator('#interest-list')).toContainText('novo');
});

test('falha de paginação mantém foco na nova tentativa e recupera', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let fail = true;
    await page.route('**/api/backoffice/product-interests?*', route => {
        const current = Number(new URL(route.request().url()).searchParams.get('page') || 1);
        if (current === 2 && fail) { fail = false; return route.fulfill({ status: 503, json: { message: 'Falha' } }); }
        return route.fulfill({ json: { data: [], meta: { total: 30, current_page: current, last_page: 2 } } });
    });
    await page.goto('/backoffice/interesses');
    const next = page.getByRole('button', { name: 'Próxima' });
    await next.focus(); await page.keyboard.press('Enter');
    const retry = page.getByRole('button', { name: 'Tentar novamente' });
    await expect(retry).toBeFocused(); await page.keyboard.press('Enter');
    await expect(page.locator('#interest-pagination [aria-current="page"]')).toHaveText('2');
    await expect(page.locator('#interest-pagination [aria-current="page"]')).toBeFocused();
});

test('atualização mantém confirmação durante recarga da lista', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    const row = { id: '1', name: 'Cliente', email: 'teste@example.test', products: [], profiles: [], status: 'novo', last_submitted_at: '2026-01-01T00:00:00Z', consented_at: '2026-01-01T00:00:00Z' };
    await page.route('**/api/backoffice/product-interests?*', route => route.fulfill({ json: { data: [row], meta: { total: 1, current_page: 1, last_page: 1 } } }));
    await page.route('**/api/backoffice/product-interests/1', route => route.request().method() === 'PATCH' ? route.fulfill({ json: {} }) : route.fulfill({ json: { interest: row } }));
    await page.goto('/backoffice/interesses'); await page.getByRole('button', { name: 'Detalhes' }).click();
    await page.getByRole('button', { name: 'Salvar status' }).click();
    await expect(page.locator('#interest-message')).toContainText('Status atualizado.');
    await expect(page.locator('#interest-list')).toContainText('Cliente');
    await expect(page.locator('#interest-message')).toContainText('Status atualizado.');
});

test('consulta pendente respeita mudança de foco e página única mantém destino', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let release, started; const pending = new Promise(resolve => { release = resolve; }); const begun = new Promise(resolve => { started = resolve; }); let count = 0;
    await page.route('**/api/backoffice/product-interests?*', async route => {
        const current = Number(new URL(route.request().url()).searchParams.get('page') || 1);
        if (current === 2 && count++ === 0) { started(); await pending; }
        await route.fulfill({ json: { data: [], meta: { total: 1, current_page: current, last_page: current === 2 ? 1 : 2 } } });
    });
    await page.goto('/backoffice/interesses');
    await page.getByRole('button', { name: 'Próxima' }).focus(); await page.keyboard.press('Enter'); await begun;
    await page.locator('#interest-product-filter').focus(); release();
    await expect(page.locator('#interest-pagination')).toContainText('1 interesses');
    await expect(page.locator('#interest-product-filter')).toBeFocused();
});

test('página única devolve foco ao resumo após paginação', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    await page.route('**/api/backoffice/product-interests?*', route => {
        const current = Number(new URL(route.request().url()).searchParams.get('page') || 1);
        return route.fulfill({ json: { data: [], meta: { total: 1, current_page: current, last_page: current === 2 ? 1 : 2 } } });
    });
    await page.goto('/backoffice/interesses');
    await page.getByRole('button', { name: 'Próxima' }).focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#interest-pagination')).toContainText('1 interesses');
    await expect(page.locator('#interest-pagination')).toBeFocused();
});

test('carregamento acessível acompanha somente consulta mais recente', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let release, started; const pending = new Promise(resolve => { release = resolve; }); const begun = new Promise(resolve => { started = resolve; });
    await page.route('**/api/backoffice/product-interests?*', async route => {
        if (new URL(route.request().url()).searchParams.get('product') === 'espera') { started(); await pending; }
        await route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } });
    });
    await page.goto('/backoffice/interesses'); await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'false');
    await page.locator('#interest-product-filter').fill('espera'); await page.getByRole('button', { name: 'Filtrar' }).click(); await begun;
    await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('#interest-loading')).toHaveText('Carregando interesses...');
    release(); await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'false');
});

test('resposta antiga não encerra carregamento ou erro da consulta nova', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let releaseOld, releaseNew, oldStart, newStart; const old = new Promise(r => { releaseOld = r; }); const newer = new Promise(r => { releaseNew = r; }); const begunOld = new Promise(r => { oldStart = r; }); const begunNew = new Promise(r => { newStart = r; });
    await page.route('**/api/backoffice/product-interests?*', async route => {
        const p = new URL(route.request().url()).searchParams.get('product');
        if (p === 'antigo') { oldStart(); await old; }
        if (p === 'novo') { newStart(); await newer; return route.fulfill({ status: 503, json: { message: 'Erro novo' } }); }
        return route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } });
    });
    await page.goto('/backoffice/interesses'); await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'false');
    await page.locator('#interest-product-filter').fill('antigo'); await page.getByRole('button', { name: 'Filtrar' }).click(); await begunOld;
    await page.locator('#interest-product-filter').fill('novo'); await page.getByRole('button', { name: 'Filtrar' }).click(); await begunNew;
    const oldResponse = page.waitForResponse(response => response.url().includes("product=antigo")); releaseOld(); await oldResponse; await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 0))); await expect(page.locator('#interest-loading')).toBeVisible(); await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'true');
    releaseNew(); await expect(page.locator('#interest-loading')).toBeHidden(); await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'false'); await expect(page.getByRole('button', { name: 'Tentar novamente' })).toBeVisible();
});

test('resposta antiga após erro novo não apaga erro nem retry', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', route => route.fulfill({ json: { admin: { id: 'TEST', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } }));
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    let release, started; const pending = new Promise(r => { release = r; }); const begun = new Promise(r => { started = r; });
    await page.route('**/api/backoffice/product-interests?*', async route => {
        const p = new URL(route.request().url()).searchParams.get('product');
        if (p === 'antigo') { started(); await pending; }
        if (p === 'novo') return route.fulfill({ status: 503, json: { message: 'Erro novo' } });
        return route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } });
    });
    await page.goto('/backoffice/interesses'); await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'false');
    await page.locator('#interest-product-filter').fill('antigo'); await page.getByRole('button', { name: 'Filtrar' }).click(); await begun;
    await page.locator('#interest-product-filter').fill('novo'); await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page.getByRole('button', { name: 'Tentar novamente' })).toBeVisible();
    const response = page.waitForResponse(result => result.url().includes('product=antigo')); release(); await response; await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 0)));
    await expect(page.getByRole('button', { name: 'Tentar novamente' })).toBeVisible(); await expect(page.locator('#backoffice-toast-container')).toContainText('Erro novo'); await expect(page.locator('#interest-list')).toHaveAttribute('aria-busy', 'false');
});
