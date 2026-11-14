import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const html = readFileSync(new URL('../../public/backoffice/pages/modules.html', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../public/backoffice/assets/js/modules-page.js', import.meta.url), 'utf8').replace('import { createRecordsDrawer } from "./records-drawer.js";', `const createRecordsDrawer = () => { let state = {}; return { setState(value) { state = value; }, getState() { return state; }, show() {}, close() {}, dispose() {} }; };`);
const catalog = { products: [{ id:'p1', name:'Produto', code:'law', plans:[], modules:[{ id:'m1', product_id:'p1', name:'Módulo', code:'mod', status:'ativo', publication_state:'rascunho' }] }], options:{} };
async function setup(page) {
    await page.setContent(html);
    await page.evaluate(async ({source, catalog}) => {
        window.pending = []; window.FokusStyles = { Modal: { getOrCreateInstance: () => ({show(){},hide(){},dispose(){}}) }, Offcanvas:{getOrCreateInstance:()=>({show(){},hide(){},dispose(){}})} };
        window.FokusForm = { validate: () => true, mapServerErrors(form) { form.elements.namedItem('name').focus(); } };
        window.controller = new AbortController();
        const mod = await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(source)))}`);
        window.moduleUnderTest = mod; window.catalogFixture = catalog;
        window.dispose = await mod.mount(document.querySelector('main'), { admin: { id: 'admin1' }, signal: window.controller.signal, permissions: new Set(['platform.catalog.publish']), api:{ request: (url, options) => options ? new Promise((resolve,reject)=>window.pending.push({url,resolve,reject})) : window.holdCatalog ? new Promise((resolve, reject) => { window.resolveCatalog = resolve; window.rejectCatalog = reject; }) : Promise.resolve(catalog) } });
    }, {source, catalog});
}
const submit = (page, selector) => page.locator(selector).evaluate((form) => form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
test('bloqueia envios duplicados e permite correção após rejeição conhecida', async ({ page }) => {
    await setup(page);
    await page.locator('#module-new').evaluate(el=>el.click());
    await submit(page, '#module-form'); await submit(page, '#module-form');
    expect(await page.evaluate(()=>window.pending.length)).toBe(1);
    await expect(page.locator('#module-form-submit')).toBeDisabled();
    await expect(page.locator('#module-form')).toHaveJSProperty('inert', true);
    await expect(page.locator('#module-form')).toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('[data-module-progress="module-form"]')).toHaveText('Processando alteração do módulo.');
    await expect(page.locator('[data-module-progress="module-destructive-form"]')).toHaveText('Processando alteração do módulo.');
    await page.evaluate(()=>window.pending[0].reject(Object.assign(new Error('Dados inválidos'), {status:422})));
    await expect(page.locator('#modules-message')).toHaveText('Dados inválidos');
    await expect(page.locator('#module-form-submit')).toBeEnabled();
    await expect(page.locator('#module-form')).toHaveJSProperty('inert', false);
    await expect(page.locator('#module-name')).toBeFocused();
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

test('resultado incerto continua bloqueado ao retornar e permite consulta e reconhecimento', async ({ page }) => {
    await setup(page);
    await page.locator('#module-list [data-module-action="publish"]').evaluate(el=>el.click());
    await submit(page, '#module-destructive-form');
    await page.evaluate(() => { window.controller.abort(); window.dispose(); window.pending[0].reject(new Error('Rede indisponível')); });
    await page.evaluate(async () => {
        await Promise.resolve();
        window.controller = new AbortController();
        window.dispose = await window.moduleUnderTest.mount(document.querySelector('main'), { admin:{id:'admin1'}, signal:window.controller.signal, permissions:new Set(['platform.catalog.publish']), api:{request:(url,options)=>options?new Promise((resolve,reject)=>window.pending.push({url,resolve,reject})):Promise.resolve(window.catalogFixture)} });
    });
    await expect(page.locator('#module-form-submit')).toBeDisabled();
    await expect(page.locator('#modules-message')).toContainText('Não foi possível confirmar');
    await page.locator('#module-list [data-module-action="view"]').evaluate(el=>el.click());
    await expect(page.locator('#module-view-panel')).toBeVisible();
    await expect(page.getByRole('button', {name:'Já conferi o catálogo'})).toBeDisabled();
    await page.getByRole('button', {name:'Recarregar catálogo'}).click();
    await page.getByRole('button', {name:'Já conferi o catálogo'}).click();
    await expect(page.locator('#module-form-submit')).toBeEnabled();
});

test('retorno tardio não fecha ou apaga um novo formulário', async ({ page }) => {
    await setup(page);
    await page.locator('#module-new').evaluate(el=>el.click());
    await submit(page, '#module-form');
    await page.locator('#module-drawer-close').evaluate(el=>el.click());
    await page.locator('#module-new').evaluate(el=>el.click());
    await expect(page.locator('#module-form')).toHaveJSProperty('inert', true);
    await page.locator('#module-name').evaluate(el => { el.value = 'Novo rascunho'; });
    await page.evaluate(()=>window.pending[0].resolve({}));
    await expect(page.locator('#modules-message')).toContainText('A alteração foi concluída');
    await expect(page.locator('#module-name')).toHaveValue('Novo rascunho');
    await expect(page.locator('#module-form-submit')).toBeDisabled();
});

test('catálogo lento e validação antiga não apagam ou contaminam novo formulário', async ({ page }) => {
    await setup(page);
    await page.evaluate(() => { window.FokusForm.mapServerErrors = () => { window.mapped = (window.mapped || 0) + 1; }; });
    await page.locator('#module-new').evaluate(el=>el.click());
    await submit(page, '#module-form');
    await page.locator('#module-drawer-close').evaluate(el=>el.click());
    await page.locator('#module-new').evaluate(el=>el.click());
    await expect(page.locator('#module-form')).toHaveJSProperty('inert', true);
    await page.locator('#module-name').evaluate(el => { el.value = 'Formulário B'; });
    await page.evaluate(() => window.pending[0].reject(Object.assign(new Error('Rejeitado'), {status:422, errors:{name:['inválido']}})));
    await expect(page.locator('#modules-message')).toHaveText('Rejeitado');
    expect(await page.evaluate(()=>window.mapped || 0)).toBe(0);
    await expect(page.locator('#module-name')).toHaveValue('Formulário B');
});

test('carregamento tardio do catálogo preserva novo formulário', async ({ page }) => {
    await setup(page);
    await page.locator('#module-new').evaluate(el=>el.click());
    await submit(page, '#module-form');
    await page.evaluate(()=>{window.holdCatalog = true; window.pending[0].resolve({});});
    await expect.poll(()=>page.evaluate(()=>typeof window.resolveCatalog)).toBe('function');
    await page.locator('#module-new').evaluate(el=>el.click());
    await expect(page.locator('#module-form')).toHaveJSProperty('inert', true);
    await page.locator('#module-name').evaluate(el => { el.value = 'Formulário novo'; });
    await page.evaluate(()=>window.resolveCatalog(window.catalogFixture));
    await expect(page.locator('#modules-message')).toContainText('Módulo cadastrado');
    await expect(page.locator('#module-name')).toHaveValue('Formulário novo');
});

test('falha na atualização após sucesso exige consulta antes de outra alteração', async ({ page }) => {
    await setup(page);
    await page.locator('#module-new').evaluate(el=>el.click());
    await submit(page, '#module-form');
    await page.evaluate(()=>{window.holdCatalog = true; window.pending[0].resolve({});});
    await expect.poll(()=>page.evaluate(()=>typeof window.rejectCatalog)).toBe('function');
    await page.evaluate(()=>window.rejectCatalog(new Error('Catálogo indisponível')));
    await expect(page.locator('#modules-message')).toContainText('A alteração foi concluída');
    await expect(page.locator('#module-form-submit')).toBeDisabled();
    await expect(page.getByRole('button', {name:'Já conferi o catálogo'})).toBeDisabled();
});

test('ação confirmada após sair informa resultado e exige atualização ao retornar', async ({ page }) => {
    await setup(page);
    await page.locator('#module-list [data-module-action="publish"]').evaluate(el=>el.click());
    await submit(page, '#module-destructive-form');
    await page.evaluate(() => { window.controller.abort(); window.dispose(); window.pending[0].resolve({release_version:'v1.0'}); });
    await page.evaluate(async () => {
        await Promise.resolve(); window.controller = new AbortController();
        window.dispose = await window.moduleUnderTest.mount(document.querySelector('main'), { admin:{id:'admin1'}, signal:window.controller.signal, permissions:new Set(['platform.catalog.publish']), api:{request:()=>Promise.resolve(window.catalogFixture)} });
    });
    await expect(page.locator('#modules-message')).toContainText('A alteração foi concluída');
    await expect(page.locator('#module-form-submit')).toBeDisabled();
});
