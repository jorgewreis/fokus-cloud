import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const html = readFileSync(new URL('../../public/backoffice/pages/modules.html', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../public/backoffice/assets/js/modules-page.js', import.meta.url), 'utf8').replace('import { createRecordsDrawer } from "./records-drawer.js";', `const createRecordsDrawer = () => { let state = {}; return { setState(value) { state = value; }, getState() { return state; }, show() {}, close() {}, dispose() {} }; };`);
const catalog = { products: [{ id:'p1', name:'Produto', code:'law', plans:[], modules:[{ id:'m1', product_id:'p1', name:'Módulo', code:'mod', status:'ativo', publication_state:'rascunho' }] }], options:{} };
async function setup(page) {
    await page.setContent(html);
    await page.evaluate(async ({source, catalog}) => {
        window.pending = []; window.FokusStyles = { Modal: { getOrCreateInstance: () => ({show(){},hide(){},dispose(){}}) }, Offcanvas:{getOrCreateInstance:()=>({show(){},hide(){},dispose(){}})} };
        window.FokusForm = { validate: () => true, mapServerErrors() {} };
        window.controller = new AbortController();
        const mod = await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(source)))}`);
        window.dispose = await mod.mount(document.querySelector('main'), { signal: window.controller.signal, permissions: new Set(['platform.catalog.publish']), api:{ request: (url, options) => options ? new Promise((resolve,reject)=>window.pending.push({url,resolve,reject})) : Promise.resolve(catalog) } });
    }, {source, catalog});
}
const submit = (page, selector) => page.locator(selector).evaluate((form) => form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
test('bloqueia envios duplicados e permite correção após rejeição conhecida', async ({ page }) => {
    await setup(page);
    await page.locator('#module-new').evaluate(el=>el.click());
    await submit(page, '#module-form'); await submit(page, '#module-form');
    expect(await page.evaluate(()=>window.pending.length)).toBe(1);
    await expect(page.locator('#module-form-submit')).toBeDisabled();
    await page.evaluate(()=>window.pending[0].reject(Object.assign(new Error('Dados inválidos'), {status:422})));
    await expect(page.locator('#modules-message')).toHaveText('Dados inválidos');
    await expect(page.locator('#module-form-submit')).toBeEnabled();
    await submit(page, '#module-form');
    expect(await page.evaluate(()=>window.pending.length)).toBe(2);
    await page.evaluate(()=>window.pending[1].reject(new Error('Falha de rede')));
    await expect(page.locator('#modules-message')).toContainText('Não foi possível confirmar');
    await expect(page.locator('#module-form-submit')).toBeDisabled();
    await submit(page, '#module-form'); expect(await page.evaluate(()=>window.pending.length)).toBe(2);
});
test('ação confirmada é enviada uma vez e retorno após desmontagem não atualiza tela', async ({ page }) => {
    await setup(page);
    await page.locator('#module-list [data-module-action="publish"]').evaluate(el=>el.click());
    await submit(page, '#module-destructive-form'); await submit(page, '#module-destructive-form');
    expect(await page.evaluate(()=>window.pending.length)).toBe(1);
    await expect(page.locator('#module-destructive-form [type="submit"]')).toBeDisabled();
    await page.evaluate(()=>{window.controller.abort();window.dispose();window.pending[0].resolve({release_version:'v1.0'});});
    await expect(page.locator('#modules-message')).not.toContainText('Módulo publicado');
});
