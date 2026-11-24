import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE
  ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const html = await readFile(new URL('../../public/styles/examples/confirmation-modal.html', import.meta.url), 'utf8');
const bundle = await readFile(new URL('../../public/styles/assets/fokus-3.0.0.min.js', import.meta.url), 'utf8');
const theme = await readFile(new URL('../../public/styles/assets/styles-theme.js', import.meta.url), 'utf8');

test('Modal 3.0.0 abre, valida e confirma localmente', async () => {
  const dom = new JSDOM(html, {runScripts:'outside-only', url:'http://localhost/'});
  const {window} = dom, doc = window.document;
  try {
    window.eval(theme); window.eval(bundle); window.eval(doc.querySelector('script:not([src])').textContent);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const trigger = doc.getElementById('confirmation-trigger'), modal = doc.getElementById('confirmation-modal');
    assert.equal(doc.querySelectorAll('a[href*="358b1b39d2ba8b216fe4cffb22e450e537807a5e/"]').length, 2);
    const form = doc.getElementById('confirmation-form'), select = doc.getElementById('confirmation-reason');
    trigger.focus(); trigger.click();
    assert.equal(modal.getAttribute('aria-hidden'), 'false');
    assert.equal(form.checkValidity(), false);
    select.value = 'revisao'; assert.equal(form.checkValidity(), true);
    form.dispatchEvent(new window.Event('submit', {bubbles:true, cancelable:true}));
    assert.equal(modal.getAttribute('aria-hidden'), 'true');
    assert.equal(doc.activeElement, trigger);
    assert.match(doc.getElementById('confirmation-status').textContent, /Nenhuma informação foi enviada/);
    trigger.click();
    assert.equal(modal.getAttribute('aria-hidden'), 'false');
    modal.querySelector('[data-fs-dismiss="modal"]').click();
    assert.equal(modal.getAttribute('aria-hidden'), 'true');
    assert.equal(doc.activeElement, trigger);
    trigger.click();
    doc.dispatchEvent(new window.KeyboardEvent('keydown', {key:'Escape', bubbles:true}));
    assert.equal(modal.getAttribute('aria-hidden'), 'true');
    assert.equal(doc.activeElement, trigger);
    doc.querySelector('[data-theme-toggle]').click();
    assert.equal(doc.documentElement.dataset.theme, 'light');
  } finally { window.close(); }
});
