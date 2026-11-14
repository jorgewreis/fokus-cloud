import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const html = readFileSync(new URL('../../public/backoffice/pages/users.html', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../public/backoffice/assets/js/users-page.js', import.meta.url), 'utf8')
    .replace('import { createRecordsDrawer } from "./records-drawer.js";', 'const createRecordsDrawer = () => ({ show() { window.drawerShown = (window.drawerShown || 0) + 1; }, close() {}, dispose() {}, setState() {}, getState() { return {}; } });');
const result = (name) => ({ data: [{ id: name, name, type: 'plataforma', status: 'ativo' }], meta: { total: 30, current_page: 1, last_page: 2, per_page: 15 } });

test('usuários: consultas e detalhes antigos não substituem o estado atual ou reabrem após sair', async ({ page }) => {
    await page.setContent(html);
    await page.evaluate(async (source) => {
        const module = await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(source)))}`);
        window.pending = [];
        window.__backofficePermissions = new Set(["platform.security.manage"]);
        window.FokusStyles = { Modal: { getOrCreateInstance: () => ({ show() {}, hide() {}, dispose() {} }) } };
        window.abortController = new AbortController();
        window.disposeUsers = await module.mount(document.querySelector('main'), { signal: window.abortController.signal, admin: { role: 'superadministrador' }, api: { request: (url) => new Promise((resolve, reject) => window.pending.push({ url, resolve, reject })) } });
    }, source);
    const row = page.locator('#users-list');
    await page.evaluate((value) => window.pending[0].resolve(value), result('Inicial'));
    await expect(row).toContainText('Inicial');
    await page.locator('#users-query').fill('antigo');
    await page.locator('#users-filter-form').evaluate((form) => form.requestSubmit());
    await page.locator('#users-query').fill('atual');
    await page.locator('#users-filter-form').evaluate((form) => form.requestSubmit());
    await expect(row).not.toContainText('Inicial');
    await page.evaluate((value) => window.pending[2].resolve(value), result('Atual'));
    await page.evaluate((value) => window.pending[1].resolve(value), result('Antigo'));
    await expect(row).toContainText('Atual');
    await expect(row).not.toContainText('Antigo');
    await row.locator('[data-user-action="details"]').click();
    await row.locator('[data-user-action="details"]').click();
    await page.evaluate(() => window.pending[4].resolve({ name: 'Detalhe atual', type: 'plataforma' }));
    await page.evaluate(() => window.pending[3].resolve({ name: 'Detalhe antigo', type: 'plataforma' }));
    await expect(page.locator('#user-drawer-title')).toHaveText('Detalhe atual');
    await page.locator('#users-filter-form').evaluate((form) => form.requestSubmit());
    await page.evaluate(() => window.pending[5].reject(new Error('Erro atual')));
    await expect(row).not.toContainText('Atual');
    await expect(page.locator('#users-table-summary')).toBeEmpty();
    await expect(page.locator('#users-pagination')).toBeEmpty();
    await expect(page.locator('#users-message')).toHaveText('Erro atual');
    await page.getByRole('button', { name: 'Tentar novamente' }).click();
    await page.evaluate((value) => window.pending[6].resolve(value), result('Recuperado'));
    await expect(row).toContainText('Recuperado');
    await row.locator('[data-user-action="details"]').click();
    await page.keyboard.press('Escape');
    await page.evaluate(() => window.pending[7].resolve({ name: 'Fechado por Escape', type: 'plataforma' }));
    await expect(page.locator('#user-drawer-title')).not.toHaveText('Fechado por Escape');
    await row.locator('[data-user-action="details"]').click();
    await page.locator('#user-drawer-trigger').dispatchEvent('fs:hide');
    await page.evaluate(() => window.pending[8].resolve({ name: 'Fechado pelo backdrop', type: 'plataforma' }));
    await expect(page.locator('#user-drawer-title')).not.toHaveText('Fechado pelo backdrop');
    await row.locator('[data-user-action="block"]').click();
    await page.locator('#user-action-reason').fill('Motivo sintético');
    await page.locator('#user-action-form').evaluate((form) => form.requestSubmit());
    await page.evaluate(() => window.pending[9].resolve({}));
    await expect(page.locator('#user-invite')).toBeDisabled();
    await expect.poll(() => page.evaluate(() => window.pending.length)).toBe(11);
    await page.evaluate((value) => window.pending[10].resolve(value), result('Recuperado'));
    await expect(page.locator('#users-message')).toHaveText('Ação de segurança concluída.');
    await row.locator('[data-user-action="details"]').click();
    await page.evaluate(() => { window.abortController.abort(); window.disposeUsers(); window.pending[11].resolve({ name: 'Após sair', type: 'plataforma' }); });
    await expect(page.locator('#user-drawer-title')).not.toHaveText('Após sair');
    expect(await page.evaluate(() => window.drawerShown)).toBe(1);
});

test('usuários: respostas de empresas e ações não alteram tela após desmontagem', async ({ page }) => {
    await page.setContent(html);
    await page.evaluate(async (source) => {
        const module = await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(source)))}`);
        window.pending = [];
        window.__backofficePermissions = new Set(['platform.security.manage']);
        window.FokusStyles = { Modal: { getOrCreateInstance: () => ({ show() {}, hide() {}, dispose() {} }) } };
        window.abortController = new AbortController();
        window.disposeUsers = await module.mount(document.querySelector('main'), { signal: window.abortController.signal, admin: { role: 'superadministrador' }, api: { request: (url) => new Promise((resolve, reject) => window.pending.push({ url, resolve, reject })) } });
    }, source);
    await page.evaluate((value) => window.pending[0].resolve(value), result('Inicial'));
    await page.locator('#user-invite').click();
    await page.locator('#user-account-type').selectOption('empresa');
    await page.locator('#users-list [data-user-action="block"]').click();
    await page.locator('#user-action-reason').fill('Motivo sintético');
    await page.locator('#user-action-form').evaluate((form) => form.requestSubmit());
    await expect(page.locator('#user-invite')).toBeDisabled();
    await page.evaluate(() => { window.abortController.abort(); window.disposeUsers(); window.pending[1].resolve({ data: [{ id: 1, label: 'Empresa tardia' }] }); window.pending[2].resolve({}); });
    await expect(page.locator('#user-company')).not.toContainText('Empresa tardia');
    await expect(page.locator('#users-message')).not.toHaveText('Ação de segurança concluída.');
    expect(await page.evaluate(() => window.pending.length)).toBe(3);
});
