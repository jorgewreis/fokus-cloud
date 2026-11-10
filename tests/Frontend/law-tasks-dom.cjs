// DOM scenarios in real Chromium with a synthetic document/API; no HTTP integration.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true, chromiumSandbox: true });
  try {
    const context = await browser.newContext();
    await context.route('**/*', route => route.request().url() === 'https://isolated.test/law'
      ? route.fulfill({ status: 200, contentType: 'text/html', body: '<main id="root"></main>' }) : route.abort());
    const page = await context.newPage();
    page.on('console', message => console.log(message.text()));
    await page.goto('https://isolated.test/law');
    const source = fs.readFileSync(path.join(__dirname, '../../public/portal/assets/fokus-law-tasks.js'), 'utf8');
    await page.evaluate(async source => {
      const assert = (condition, message = 'Assertion failed') => { if (!condition) throw Error(message); };
      assert.equal = (actual, expected, message = '') => assert(actual === expected, `${message}: ${JSON.stringify(actual)} !== ${JSON.stringify(expected)}`);
      const w = window, root = document.getElementById('root');
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const fixture = { id: 'synthetic-task', title: '<img src=x onerror=alert(1)>', status: 'pending', due_on: '2026-11-10', version: 1, responsible_membership_id: 'unavailable-member' };
const calls = []; let writePromise = null, readPromise = null, refsPromise = null;
w.FokusApi = { request: async (url, options) => {
  calls.push([url, options]);
  if (options) return writePromise ? await writePromise : { task: fixture };
  if (url.startsWith('/law/tasks/references')) return refsPromise ? await refsPromise : { responsibles: [{ id: 'eligible-member', name: 'Elegível' }], cases: { data: [] } };
  if (url === '/law/tasks/synthetic-task') return readPromise ? await readPromise : { task: fixture, events: [] };
  return { total: 206, last_page: 11, data: [fixture] };
} };
w.eval(source);
const button = text => [...root.querySelectorAll('button')].find(el => el.textContent === text);
const submit = form => form.dispatchEvent(new w.Event('submit', { cancelable: true }));
const writes = () => calls.filter(([, options]) => options);
const responsible = form => [...form.querySelectorAll('label')].find(el => el.textContent.startsWith('Responsável')).querySelector('select');
async function editor() { button('Abrir').click(); await tick(); button('Editar').click(); await tick(); return root.querySelector('dialog form'); }

  await w.FokusLawTasks.render(root, { company: { role: 'admin' } });
  assert(!root.querySelector('img')); assert(root.textContent.includes('10/11/2026'));
  button('Próxima').click(); await tick(); assert(root.textContent.includes('Página 2 de 11'));
  let form = await editor(); assert.equal(responsible(form).value, 'unavailable-member'); assert.equal(responsible(form).selectedOptions[0].textContent,'Responsável atual indisponível');
  responsible(form).value=''; button('Fechar').click(); assert.equal(writes().length,0);
  button('Editar').click(); await tick(); form=root.querySelector('dialog form'); assert.equal(responsible(form).value,'unavailable-member');
  let reject; writePromise = new Promise((_,r)=>{reject=r;}); form.querySelector('input').value='Only title'; submit(form); submit(form);
  assert.equal(writes().length,1); assert(!Object.hasOwn(writes()[0][1].body,'responsible_membership_id'));
  reject(Error('Tarefa alterada. Reabra antes de salvar.')); await tick(); writePromise=null;
  assert(root.querySelector('dialog').textContent.includes('Reabra antes de salvar')); assert.equal(responsible(form).value,'unavailable-member');
  submit(form); await tick(); assert(!root.querySelector('dialog'));
  button('Editar').click(); await tick(); form=root.querySelector('dialog form'); responsible(form).value=''; submit(form); await tick(); assert.equal(writes().at(-1)[1].body.responsible_membership_id,null);
  button('Editar').click(); await tick(); form=root.querySelector('dialog form'); responsible(form).value='eligible-member'; submit(form); await tick(); assert.equal(writes().at(-1)[1].body.responsible_membership_id,'eligible-member');
  button('Voltar').click(); await tick(); button('Nova tarefa').click(); await tick(); form=root.querySelector('dialog form');
  assert(![...responsible(form).options].some(o=>o.value==='unavailable-member'));
  form.querySelector('input').value='New'; writePromise=Promise.reject(Error('Network')); writePromise.catch(()=>{}); submit(form); await tick(); const key=writes().at(-1)[1].body.request_key; writePromise=null; submit(form); await tick(); assert.equal(writes().at(-1)[1].body.request_key,key);
  let resolve; refsPromise=new Promise(r=>{resolve=r;}); button('Nova tarefa').click(); button('Fechar').click(); resolve({responsibles:[]}); await tick(); refsPromise=null; assert(!root.querySelector('dialog'));
  readPromise=new Promise(r=>{resolve=r;}); button('Abrir').click(); await tick();
  w.history.pushState({},'', '/law?law_tasks_page=3'); w.dispatchEvent(new w.PopStateEvent('popstate')); await tick();
  resolve({task:{...fixture,title:'STALE'},events:[]}); await tick(); readPromise=null; assert(!root.textContent.includes('STALE')); assert(root.textContent.includes('Página 3'));
  button('Abrir').click(); await tick(); let finish; writePromise=new Promise(r=>{finish=r;}); const done=button('Concluir'); done.click(); done.click(); assert.equal(calls.filter(([url,opt])=>url.endsWith('/status')&&opt).length,1);
  w.history.pushState({},'', '/law?law_tasks_page=4'); w.dispatchEvent(new w.PopStateEvent('popstate')); await tick(); finish({task:fixture}); await tick(); writePromise=null; assert(root.textContent.includes('Página 4'));
  w.history.pushState({}, '', '/law?law_tasks_page=5'); w.dispatchEvent(new w.PopStateEvent('popstate')); await tick();
  w.history.back(); await new Promise(resolve => setTimeout(resolve, 100)); assert(root.textContent.includes('Página 4'));
  w.history.forward(); await new Promise(resolve => setTimeout(resolve, 100)); assert(root.textContent.includes('Página 5'));
  console.log('PASS: escaping, pt-BR dates, pagination, close/reopen, unavailable assignment, explicit changes, optimistic conflict, retry key, repeated mutations, late responses and browser Back/Forward (synthetic DOM in real Chromium).');

    }, source);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
