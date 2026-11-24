import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE
  ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const bundle = await readFile(new URL('../../public/styles/assets/fokus-3.0.0.min.js', import.meta.url), 'utf8');
const theme = await readFile(new URL('../../public/styles/assets/styles-theme.js', import.meta.url), 'utf8');

for (const [name, file] of [['fixture', './fixtures/cloud-datatable.html'], ['vitrine', '../../public/styles/examples/cloud-datatable.html']]) {
test(`${name}: DataTable 3.0.0 demonstra dados, carregamento, vazio, erro e recuperação`, async () => {
  const html = await readFile(new URL(file, import.meta.url), 'utf8');
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    if (name === 'vitrine') window.eval(theme);
    window.eval(bundle);
    window.eval(window.document.querySelector('script:not([src])').textContent);
    if (window.document.readyState === 'loading') await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const doc = window.document;
    if (name === 'vitrine') assert.ok(doc.querySelector('a[href*="358b1b39d2ba8b216fe4cffb22e450e537807a5e/public/backoffice/pages/modules.html"]'));
    const click = id => doc.getElementById(id).click();
    const marker = name => doc.querySelector(`[data-fs-datatable-${name}]`);
    assert.equal(doc.querySelectorAll('tbody tr').length, 2);
    click('show-loading'); assert.equal(marker('loading').hidden, false);
    click('show-empty'); assert.equal(marker('empty').hidden, false);
    click('show-error'); assert.equal(marker('error').hidden, false);
    assert.match(marker('error').textContent, /Falha simulada/);
    click('show-data');
    for (const name of ['error', 'empty', 'loading']) assert.equal(marker(name).hidden, true);
    assert.equal(doc.querySelector('#module-table table').hidden, false);
    assert.equal(doc.querySelectorAll('tbody tr').length, 2);
    if (name === 'fixture') { click('theme'); assert.equal(doc.documentElement.dataset.theme, 'dark'); }
    else {
      const toggle = doc.querySelector('[data-theme-toggle]');
      assert.equal(doc.documentElement.dataset.theme, 'dark'); toggle.click();
      assert.equal(doc.documentElement.dataset.theme, 'light');
      assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    }
  } finally { window.close(); }
});
}
