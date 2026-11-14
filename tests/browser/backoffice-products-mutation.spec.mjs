import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const html = readFileSync(new URL('../../public/backoffice/pages/products.html', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../public/backoffice/assets/js/products-page.js', import.meta.url), 'utf8').replace('import { createRecordsDrawer } from "./records-drawer.js";', `const createRecordsDrawer = () => { let state = {}; return { setState(v){state=v}, getState(){return state}, show(){}, close(){}, dispose(){} }; };`);
async function setup(page) {
  await page.setContent(html);
  await page.evaluate(async source => {
    window.pending=[]; window.FokusStyles={Modal:{getOrCreateInstance:()=>({show(){},hide(){},dispose(){}})}};
    window.FokusForm={validate:()=>true,mapServerErrors(){}};
    window.mod=await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(source)))}`);
    window.mountPage=async()=>{window.controller=new AbortController(); window.dispose=await window.mod.mount(document.querySelector('main'),{admin:{id:'a'},signal:window.controller.signal,api:{request:(url,options)=>options?new Promise((resolve,reject)=>window.pending.push({resolve,reject})):Promise.resolve({products:[]})}})};
    await window.mountPage();
  },source);
}
const submit=page=>page.locator('#product-form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
test('envios concorrentes e rejeição conhecida',async({page})=>{
 await setup(page); await page.locator('#product-new').evaluate(el=>el.click()); await submit(page); await submit(page);
 expect(await page.evaluate(()=>window.pending.length)).toBe(1); await expect(page.locator('#product-form-submit')).toBeDisabled();
 await page.evaluate(()=>window.pending[0].reject(Object.assign(new Error('Inválido'),{status:422}))); await expect(page.locator('#product-form-submit')).toBeEnabled();
 await submit(page); expect(await page.evaluate(()=>window.pending.length)).toBe(2);
});
test('incerteza sobrevive à navegação e exige catálogo atualizado',async({page})=>{
 await setup(page); await page.locator('#product-new').evaluate(el=>el.click()); await submit(page);
 await page.evaluate(()=>{window.controller.abort();window.dispose();window.pending[0].reject(new Error('rede'));});
 await page.evaluate(async()=>{await Promise.resolve();await window.mountPage()});
 await expect(page.locator('#product-form-submit')).toBeDisabled(); await expect(page.locator('[data-product-mutation-status]')).toContainText('Não foi possível confirmar');
 await page.getByRole('button',{name:'Recarregar catálogo'}).click(); await page.getByRole('button',{name:'Já conferi o catálogo'}).click();
 await expect(page.locator('#product-form-submit')).toBeEnabled();
});
test('retorno tardio preserva o novo formulário',async({page})=>{
 await setup(page); await page.locator('#product-new').evaluate(el=>el.click()); await submit(page);
 await page.locator('#product-drawer-close').evaluate(el=>el.click()); await page.locator('#product-new').evaluate(el=>el.click());
 await page.locator('#product-name').evaluate(el=>el.value='Rascunho novo'); await page.evaluate(()=>window.pending[0].resolve({}));
 await expect(page.locator('#product-name')).toHaveValue('Rascunho novo'); await expect(page.locator('#product-form-submit')).toBeDisabled();
});
