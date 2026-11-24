import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE
  ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const html = await readFile(new URL('../../public/backoffice/pages/modules.html', import.meta.url), 'utf8');

test('paginação de módulos preserva foco na ida e volta sem tomar foco externo', async () => {
  const dom = new JSDOM(html, {url:'http://localhost/', pretendToBeVisual:true});
  const {window} = dom, doc = window.document;
  const saved = new Map();
  const globals = {
    window, document:doc,
    requestAnimationFrame:window.requestAnimationFrame.bind(window),
    cancelAnimationFrame:window.cancelAnimationFrame.bind(window),
    getComputedStyle:window.getComputedStyle.bind(window),
  };
  for (const [key, value] of Object.entries(globals)) {
    saved.set(key, Object.getOwnPropertyDescriptor(globalThis, key));
    Object.defineProperty(globalThis, key, {value, configurable:true, writable:true});
  }
  let dispose;
  try {
    const {mount} = await import(new URL('../../public/backoffice/assets/js/modules-page.js', import.meta.url));
    const modules = Array.from({length:20}, (_, index) => ({
      id:String(index + 1), name:`Módulo fictício ${index + 1}`, code:`m${index + 1}`,
      product_id:'p1', status:'ativo', segments:[], capabilities:[],
    }));
    const api = {request:async path => {
      assert.equal(path, '/backoffice/catalog');
      return {products:[{id:'p1', name:'Produto fictício', code:'law', modules, plans:[]}], options:{}};
    }};
    dispose = await mount(doc.querySelector('main'), {api, permissions:new Set()});
    const pagination = doc.getElementById('module-pagination');
    const current = () => pagination.querySelector('button[aria-current="page"]');
    const next = () => pagination.querySelector('button[aria-label="Próxima página"]');
    const previous = () => pagination.querySelector('button[aria-label="Página anterior"]');

    assert.equal(current().textContent, '1'); assert.equal(previous().disabled, true);
    next().focus(); next().click();
    assert.equal(current().textContent, '2'); assert.equal(doc.activeElement, current());
    assert.equal(next().disabled, true); assert.match(doc.getElementById('module-list').textContent, /Módulo fictício 16/);
    previous().focus(); previous().click();
    assert.equal(current().textContent, '1'); assert.equal(doc.activeElement, current());

    const outside = doc.getElementById('module-query'); outside.focus();
    next().click();
    assert.equal(current().textContent, '2'); assert.equal(doc.activeElement, outside);
    next().click(); assert.equal(current().textContent, '2');
    window.refreshFokusDataTables = () => outside.focus();
    previous().focus(); previous().click();
    assert.equal(current().textContent, '1'); assert.equal(doc.activeElement, outside);
  } finally {
    dispose?.(); dom.window.close();
    for (const [key, descriptor] of saved) {
      if (descriptor) Object.defineProperty(globalThis, key, descriptor);
      else delete globalThis[key];
    }
  }
});

test('versão do roteador e importação de módulo compartilham o novo cache-buster', async () => {
  const index = await readFile(new URL('../../public/backoffice/index.html', import.meta.url), 'utf8');
  const router = await readFile(new URL('../../public/backoffice/assets/js/backoffice-router.js', import.meta.url), 'utf8');
  const version = router.match(/const ASSET_VERSION = "([^"]+)"/)?.[1];
  assert.ok(version);
  assert.notEqual(version, '20261008-primary-outline-buttons-v1');
  assert.ok(index.includes(`backoffice-router.js?v=${version}`));
  assert.ok(router.includes('import(`${page.module}?v=${ASSET_VERSION}`)'));
});
