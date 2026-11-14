import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../public/backoffice/assets/js/audit-page.js', import.meta.url), 'utf8');

for (const mode of ['access', 'activity']) {
    test(`${mode}: somente a consulta atual altera a tela, inclusive após erro e desmontagem`, async ({ page }) => {
        await page.setContent(`<main id="root"><div data-audit-mode="${mode}"></div>
            <form id="access-filters"><input name="search"><button>Filtrar</button></form><button id="access-clear">Limpar</button>
            <div id="access-message" hidden></div><div id="access-summary"></div><div id="access-page-summary"></div><table><tbody id="access-body"></tbody></table><div id="access-pagination"></div>
            <div id="audit-activity-summary"></div><div id="audit-activity-page-summary"></div><table><tbody id="audit-events-body"></tbody></table><div id="audit-activity-pagination"></div></main>`);
        await page.evaluate(async ({ source }) => {
            const module = await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(source)))}`);
            window.pending = [];
            window.abortController = new AbortController();
            window.mountPromise = module.mount(document.querySelector('#root'), { signal: window.abortController.signal, api: { request: () => new Promise((resolve, reject) => window.pending.push({ resolve, reject })) } });
        }, { source });
        const data = (name, current = 1) => ({ data: mode === 'access' ? [{ user: name, user_type: 'interno', status: 'success' }] : [{ action: name, actor_name: name }], meta: { total: 40, current_page: current, last_page: 2, per_page: 20 } });
        const body = page.locator(mode === 'access' ? '#access-body' : '#audit-events-body');
        const summary = page.locator(mode === 'access' ? '#access-summary' : '#audit-activity-summary');
        const pageSummary = page.locator(mode === 'access' ? '#access-page-summary' : '#audit-activity-page-summary');
        const pagination = page.locator(mode === 'access' ? '#access-pagination' : '#audit-activity-pagination');
        await page.evaluate((value) => window.pending[0].resolve(value), data('Primeiro'));
        await expect(body).toContainText('Primeiro');
        await page.evaluate(async () => { window.disposeAudit = await window.mountPromise; });
        if (mode === 'access') {
            await page.locator('#access-filters').evaluate((form) => form.requestSubmit());
            await page.locator('#access-filters').evaluate((form) => form.requestSubmit());
        } else {
            await pagination.locator('[data-audit-page="2"]').click();
            // Simulate an already queued second page request after the first response clears pagination.
            await pagination.evaluate((node) => { node.innerHTML = '<button data-audit-page="1">1</button>'; node.querySelector('button').click(); });
        }
        await expect(body).not.toContainText('Primeiro');
        await expect(summary).toBeEmpty();
        await expect(pageSummary).toBeEmpty();
        await page.evaluate((value) => window.pending[2].resolve(value), data('Atual'));
        await expect(body).toContainText('Atual');
        await page.evaluate(() => window.pending[1].reject(new Error('Erro antigo')));
        await expect(body).toContainText('Atual');
        if (mode === 'access') await expect(page.locator('#access-message')).toBeHidden();
        if (mode === 'access') await page.locator('#access-filters').evaluate((form) => form.requestSubmit());
        else await pagination.locator('[data-audit-page="2"]').click();
        if (mode === 'access') await page.locator('#access-filters').evaluate((form) => form.requestSubmit());
        else await pagination.evaluate((node) => { node.innerHTML = '<button data-audit-page="1">1</button>'; node.querySelector('button').click(); });
        await page.evaluate(() => window.pending[4].reject(new Error('Erro atual')));
        await page.evaluate((value) => window.pending[3].resolve(value), data('Sucesso antigo'));
        await expect(body).not.toContainText('Atual');
        await expect(summary).toBeEmpty();
        await expect(pageSummary).toBeEmpty();
        await expect(pagination).toBeEmpty();
        if (mode === 'access') await expect(page.locator('#access-message')).toHaveText('Erro atual');
        else await expect(body).toContainText('Erro atual');
        if (mode === 'access') await page.locator('#access-filters').evaluate((form) => form.requestSubmit());
        else await page.getByRole('button', { name: 'Tentar novamente' }).click();
        await page.evaluate((value) => window.pending[5].resolve(value), data('Recuperado'));
        await expect(body).toContainText('Recuperado');
        if (mode === 'access') await page.locator('#access-filters').evaluate((form) => form.requestSubmit());
        else await pagination.locator('[data-audit-page="2"]').click();
        await page.evaluate(() => { window.abortController.abort(); window.disposeAudit(); window.pending[6].resolve({ data: [], meta: {} }); });
        await expect(body).toContainText(mode === 'access' ? 'Carregando acessos' : 'Carregando eventos');
    });
}
