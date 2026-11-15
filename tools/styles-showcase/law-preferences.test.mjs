import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const html = await readFile(new URL('../../public/styles/examples/law-preferences.html', import.meta.url), 'utf8');
const key = 'fokus-styles-example-law-preferences-v1';
const open = () => new JSDOM(html, {runScripts:'outside-only', url:'http://localhost/'});
const start = dom => dom.window.eval(dom.window.document.querySelector('script:not([src])').textContent);

test('regra de movimento reduzido tem a mesma especificidade da animação aberta', () => {
  const dom = open();
  try {
    const rules = [...dom.window.document.querySelector('style').sheet.cssRules];
    const media = rules.find(rule => rule.conditionText === '(prefers-reduced-motion: reduce)');
    assert.ok(media);
    const rule = [...media.cssRules].find(item => item.selectorText === '.example-preview-panel.is-open');
    assert.ok(rule);
    assert.equal(rule.style.animation, 'none');
  } finally { dom.window.close(); }
});

test('preferências fictícias persistem e afetam somente a prévia', () => {
  const first = open(), second = open();
  try {
    start(first); const d = first.window.document;
    const remember = d.getElementById('remember-section'), reduced = d.getElementById('reduce-motion'), section = d.getElementById('example-section');
    remember.checked = true; remember.dispatchEvent(new first.window.Event('change'));
    reduced.checked = true; reduced.dispatchEvent(new first.window.Event('change'));
    section.value = 'tarefas'; section.dispatchEvent(new first.window.Event('change'));
    assert.equal(d.getElementById('preview').dataset.reduced, 'true');
    second.window.localStorage.setItem(key, first.window.localStorage.getItem(key)); start(second);
    assert.equal(second.window.document.getElementById('example-section').value, 'tarefas');
    assert.equal(second.window.document.getElementById('reduce-motion').checked, true);
    assert.equal(second.window.document.documentElement.classList.contains('law-pref-reduced-motion'), false);
  } finally { first.window.close(); second.window.close(); }
});

test('armazenamento indisponível mantém controles utilizáveis', () => {
  const dom = open();
  try {
    Object.defineProperty(dom.window, 'localStorage', {value:{getItem(){throw Error('blocked');}, setItem(){throw Error('blocked');}}});
    start(dom); const d = dom.window.document, checkbox = d.getElementById('reduce-motion');
    checkbox.checked = true; checkbox.dispatchEvent(new dom.window.Event('change'));
    assert.equal(d.getElementById('preview').dataset.reduced, 'true');
    assert.match(d.getElementById('storage-status').textContent, /Não foi possível salvar/);
  } finally { dom.window.close(); }
});
