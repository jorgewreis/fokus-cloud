import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const html = await readFile(new URL('../../public/styles/examples/cloud-offcanvas.html', import.meta.url), 'utf8');
const bundle = await readFile(new URL('../../public/styles/assets/fokus-3.0.0.min.js', import.meta.url), 'utf8');
const theme = await readFile(new URL('../../public/styles/assets/styles-theme.js', import.meta.url), 'utf8');

test('Offcanvas demonstra validação, cancelamento e confirmação local', async () => {
  const dom = new JSDOM(html, {runScripts:'outside-only', url:'http://localhost/', pretendToBeVisual:true});
  const {window} = dom, doc = window.document;
  try {
    window.eval(theme); window.eval(bundle); window.eval(doc.querySelector('script:not([src])').textContent);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const trigger = doc.getElementById('open-drawer'), drawer = doc.getElementById('example-drawer');
    const form = doc.getElementById('drawer-form'), input = doc.getElementById('module-name');
    trigger.focus(); trigger.click(); assert.equal(drawer.getAttribute('aria-hidden'), 'false');
    assert.equal(form.checkValidity(), false); input.value = 'Exemplo';
    drawer.querySelector('[data-fs-dismiss="offcanvas"]').click();
    assert.equal(drawer.getAttribute('aria-hidden'), 'true'); assert.equal(doc.activeElement, trigger);
    trigger.click(); assert.equal(input.value, 'Exemplo'); assert.equal(form.checkValidity(), true);
    form.dispatchEvent(new window.Event('submit', {bubbles:true, cancelable:true}));
    assert.equal(drawer.getAttribute('aria-hidden'), 'true');
    assert.match(doc.getElementById('drawer-status').textContent, /Nenhuma informação foi enviada/);
    trigger.click(); doc.dispatchEvent(new window.KeyboardEvent('keydown', {key:'Escape', bubbles:true}));
    assert.equal(drawer.getAttribute('aria-hidden'), 'true'); assert.equal(doc.activeElement, trigger);
    doc.querySelector('[data-theme-toggle]').click(); assert.equal(doc.documentElement.dataset.theme, 'light');
  } finally { window.close(); }
});
