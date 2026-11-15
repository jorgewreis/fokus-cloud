import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const jsdomPath = process.env.REI54_JSDOM_MODULE;
const { JSDOM } = jsdomPath ? await import(pathToFileURL(jsdomPath).href) : require('jsdom');
const fixture = await readFile(new URL('./fixtures/law-pagination.html', import.meta.url), 'utf8');
const source = await readFile(new URL('../../public/portal/assets/law-record-ui.js', import.meta.url), 'utf8');

test('fixture exercita paginação Law, falha, recuperação e vazio', async () => {
  const dom = new JSDOM(fixture, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    window.eval(source);
    const scripts = [...window.document.querySelectorAll('script:not([src])')];
    assert.equal(scripts.length, 1);
    window.eval(scripts[0].textContent);
    const doc = window.document;
    const click = element => { assert.ok(element); element.click(); };
    const tick = () => new Promise(resolve => setImmediate(resolve));
    const page = number => doc.querySelector(`#pagination button[aria-label^="Página ${number} de"]`);
    assert.equal(doc.querySelectorAll('#rows tr').length, 5);
    assert.match(doc.querySelector('#pagination [role=status]').textContent, /Mostrando 1 a 5 de 23/);
    click(page(2)); await tick();
    assert.match(doc.querySelector('#rows').textContent, /Registro 6/);
    assert.equal(page(2).getAttribute('aria-current'), 'page');
    click(doc.querySelector('#failure')); click(page(3)); await tick();
    assert.match(doc.querySelector('#error').textContent, /Falha simulada/);
    assert.match(doc.querySelector('#rows').textContent, /Registro 6/);
    click(page(3)); await tick();
    assert.equal(doc.querySelector('#error').textContent, '');
    assert.match(doc.querySelector('#rows').textContent, /Registro 11/);
    click(doc.querySelector('#empty'));
    assert.match(doc.querySelector('#rows').textContent, /Nenhum registro/);
    assert.match(doc.querySelector('#pagination [role=status]').textContent, /Nenhum registro/);
    click(doc.querySelector('#empty'));
    assert.match(doc.querySelector('#rows').textContent, /Registro 1/);
    click(doc.querySelector('#theme'));
    assert.equal(doc.documentElement.dataset.theme, 'dark');
  } finally { window.close(); }
});

test('remontagem devolve foco à página atual no DOM simulado', async () => {
  const dom = new JSDOM(fixture, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    window.eval(source);
    window.eval(window.document.querySelector('script:not([src])').textContent);
    const doc = window.document;
    const trigger = doc.querySelector('#pagination button[aria-label^="Página 2 de"]');
    trigger.focus();
    assert.equal(doc.activeElement, trigger);
    trigger.click();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(trigger.isConnected, false);
    assert.equal(doc.activeElement.getAttribute('aria-current'), 'page');
    assert.match(doc.querySelector('#pagination [aria-current=page]').textContent, /2/);
  } finally { window.close(); }
});

test('reconstrução de contêiner usa destino explícito sem tomar foco externo', async () => {
  const dom = new JSDOM('<!doctype html><main><div id="host"></div><button id="outside">Outro</button></main>', { runScripts: 'outside-only' });
  const { window } = dom, doc = window.document;
  try {
    window.eval(source);
    const host = doc.getElementById('host'), outside = doc.getElementById('outside');
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    const draw = (page, callback) => {
      const row = doc.createElement('div'); host.replaceChildren(row);
      window.FokusLawRecordUI.renderPagination(row, { page, per_page: 5, total: 15 }, callback, 'itens', null, () => host.firstElementChild);
    };
    draw(1, async () => { await pending; draw(2, async () => {}); });
    const trigger = host.querySelector('button[aria-label^="Página 2 de"]');
    trigger.focus(); trigger.click(); outside.focus(); finish();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(doc.activeElement, outside);
    assert.equal(host.querySelector('button[aria-current="page"]').textContent, '2');
  } finally { window.close(); }
});

test('reconstrução de contêiner restaura foco quando não houve interação externa', async () => {
  const dom = new JSDOM('<!doctype html><main><div id="host"></div></main>', { runScripts: 'outside-only' });
  const { window } = dom, doc = window.document;
  try {
    window.eval(source);
    const host = doc.getElementById('host');
    const draw = (page, callback) => {
      const row = doc.createElement('div'); host.replaceChildren(row);
      window.FokusLawRecordUI.renderPagination(row, { page, per_page: 5, total: 15 }, callback, 'itens', null, () => host.firstElementChild);
    };
    draw(1, async () => { draw(2, async () => {}); });
    const trigger = host.querySelector('button[aria-label^="Página 2 de"]'); trigger.focus(); trigger.click();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(doc.activeElement, host.querySelector('button[aria-current="page"]'));
  } finally { window.close(); }
});

test('erro mantém foco no controle acionado e permite tentar novamente', async () => {
  const dom = new JSDOM(fixture, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom, doc = window.document;
  try {
    window.eval(source); window.eval(doc.querySelector('script:not([src])').textContent);
    doc.getElementById('failure').click();
    const trigger = doc.querySelector('#pagination button[aria-label^="Página 2 de"]');
    trigger.focus(); trigger.click(); await new Promise(resolve => setImmediate(resolve));
    assert.equal(doc.activeElement, trigger);
    assert.equal(trigger.disabled, false);
    assert.match(doc.getElementById('error').textContent, /Falha simulada/);
    trigger.click(); await new Promise(resolve => setImmediate(resolve));
    assert.equal(doc.querySelector('#pagination button[aria-current="page"]').textContent, '2');
  } finally { window.close(); }
});

test('desmontagem libera listeners mesmo com requisição pendente', async () => {
  const dom = new JSDOM('<!doctype html><main id="host"></main>', {runScripts:'outside-only'});
  const {window} = dom, doc = window.document;
  try {
    window.eval(source);
    const host = doc.getElementById('host');
    const added = [], removed = [];
    const add = doc.addEventListener.bind(doc), remove = doc.removeEventListener.bind(doc);
    doc.addEventListener = (type, listener, options) => { if (['focusin', 'pointerdown'].includes(type)) added.push([type, listener]); return add(type, listener, options); };
    doc.removeEventListener = (type, listener, options) => { if (['focusin', 'pointerdown'].includes(type)) removed.push([type, listener]); return remove(type, listener, options); };
    window.FokusLawRecordUI.renderPagination(host, {page:1, per_page:5, total:10}, () => new Promise(() => {}));
    const trigger = host.querySelector('button[aria-label^="Página 2 de"]'); trigger.focus(); trigger.click();
    assert.equal(added.length, 2);
    host.remove(); await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(removed.length, 2);
    assert.ok(added.every(([type, listener]) => removed.some(([removedType, removedListener]) => removedType === type && removedListener === listener)));
  } finally { window.close(); }
});

test('aguarda reconstrução assíncrona enquanto contexto permanece ativo', async () => {
  const dom = new JSDOM('<!doctype html><main id="host"></main>', {runScripts:'outside-only'});
  const {window} = dom, doc = window.document;
  try {
    window.eval(source);
    const host = doc.getElementById('host'); let resume;
    const delay = new Promise(resolve => { resume = resolve; });
    const draw = (page, callback) => {
      const row = doc.createElement('div'); host.replaceChildren(row);
      window.FokusLawRecordUI.renderPagination(row, {page, per_page:5, total:10}, callback, 'itens', null,
        () => host.firstElementChild, () => host.isConnected);
    };
    draw(1, async () => { host.replaceChildren(); await delay; draw(2, async () => {}); });
    const trigger = host.querySelector('button[aria-label^="Página 2 de"]'); trigger.focus(); trigger.click();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(host.children.length, 0);
    resume(); await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(doc.activeElement, host.querySelector('button[aria-current="page"]'));
  } finally { window.close(); }
});

test('contexto invalidado libera listeners mesmo com contêiner conectado', async () => {
  const dom = new JSDOM('<!doctype html><main id="host"></main>', {runScripts:'outside-only'});
  const {window} = dom, doc = window.document;
  try {
    window.eval(source);
    const host = doc.getElementById('host'); let active = true;
    const removed = [];
    const remove = doc.removeEventListener.bind(doc);
    doc.removeEventListener = (type, listener, options) => { if (['focusin', 'pointerdown'].includes(type)) removed.push(type); return remove(type, listener, options); };
    window.FokusLawRecordUI.renderPagination(host, {page:1, per_page:5, total:10}, () => new Promise(() => {}), 'itens', null, null, () => active);
    const trigger = host.querySelector('button[aria-label^="Página 2 de"]'); trigger.focus(); trigger.click();
    active = false; host.append(doc.createElement('span'));
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(host.isConnected, true);
    assert.deepEqual(removed.sort(), ['focusin', 'pointerdown']);
  } finally { window.close(); }
});
