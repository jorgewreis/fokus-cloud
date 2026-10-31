import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const {JSDOM} = await import(process.env.JSDOM_MODULE || 'jsdom');
const base = new URL('../../public/styles/next/', import.meta.url);
const catalog = JSON.parse(await readFile(new URL('catalog.json',base)));
const dom = new JSDOM(await readFile(new URL('index.html',base),'utf8'),{url:'https://styles.test/styles/next/',runScripts:'outside-only'});
const {window:w}=dom; let failure=false, copied;
w.fetch=async()=>({ok:!failure,json:async()=>catalog});
w.navigator.clipboard={writeText:async text=>{copied=text;}};
w.eval(await readFile(new URL('catalog.js',base),'utf8'));
const wait=()=>new Promise(resolve=>setTimeout(resolve,20)); await wait();
const $=s=>w.document.querySelector(s);
assert.equal(w.document.querySelectorAll('#catalog-list a').length,56);
$('#catalog-search').value='zzzzz-absent'; $('#catalog-search').dispatchEvent(new w.Event('input')); assert.equal(w.document.querySelectorAll('#catalog-list a').length,0);
$('#catalog-reset').click(); assert.equal(w.document.querySelectorAll('#catalog-list a').length,56);
$('#catalog-group').value='Formulários'; $('#catalog-group').dispatchEvent(new w.Event('change')); assert.equal(w.document.querySelectorAll('#catalog-list a').length,catalog.contracts.filter(c=>c.group==='Formulários').length);
$('#catalog-reset').click();
for (const c of catalog.contracts) {
 w.location.hash=c.id; await wait(); assert.equal($('#catalog-detail h2').textContent,c.title);
 assert.equal(w.document.querySelectorAll('#catalog-detail iframe').length,c.examples.length);
 assert.deepEqual([...w.document.querySelectorAll('.catalog-example-limitations li')].map(el=>el.textContent),c.examples.flatMap(e=>e.limitations));
 for(const frame of w.document.querySelectorAll('iframe')) {assert.equal(frame.getAttribute('sandbox'),'allow-scripts'); assert.match(frame.srcdoc,/form-action 'none'/); assert.match(frame.srcdoc,/img-src data: blob:/); assert.ok(!frame.srcdoc.includes('allow-same-origin'));}
 const docs=[...w.document.querySelectorAll('#catalog-detail details')].find(d=>d.querySelector('summary').textContent.startsWith('Ler documentação'));
 assert.equal(docs.querySelector('code').textContent,c.documentation.markdown); docs.querySelector('button').click(); await wait(); assert.equal(copied,c.documentation.markdown);
}
w.location.hash='nonexistent'; await wait(); assert.match($('#catalog-detail').textContent,/não encontrado/);
failure=true; $('#catalog-retry').click(); await wait(); assert.equal($('#catalog-retry').hidden,false);
failure=false; $('#catalog-retry').click(); await wait(); assert.equal($('#catalog-retry').hidden,true);
console.log('PASS DOM: 56 details, original documentation/copy, filters, empty/invalid states, preview isolation and retry.');
w.close();
