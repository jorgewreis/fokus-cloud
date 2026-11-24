import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const html = await readFile(new URL('../../public/styles/examples/law-select-pattern.html', import.meta.url), 'utf8');
const bundle = await readFile(new URL('../../public/styles/assets/fokus-3.0.0.min.js', import.meta.url), 'utf8');

for (const available of [true, false]) {
  test(`padrão de Select ${available ? 'com bundle' : 'sem bundle'}`, async () => {
    const dom = new JSDOM(html, {runScripts:'outside-only', url:'http://localhost/'});
    const {window} = dom, doc = window.document;
    try {
      if (available) window.eval(bundle);
      window.eval(doc.querySelector('script:not([src])').textContent);
      if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
      const select = doc.getElementById('sector');
      if (available) {
        const toggle = window.FokusStyles.Select.getInstance(select).toggleEl;
        const menu = window.FokusStyles.Select.getInstance(select).menuEl;
        assert.equal(menu.getAttribute('aria-labelledby'), toggle.id);
        assert.equal(toggle.getAttribute('aria-labelledby'), 'sector-label');
        assert.equal(toggle.getAttribute('aria-describedby'), 'sector-help');
        assert.equal(doc.getElementById('sector-label').htmlFor, toggle.id);
        const trigger = doc.getElementById('open-dialog'); trigger.focus(); trigger.click();
        assert.equal(doc.getElementById('example-dialog').getAttribute('aria-hidden'), 'false');
        doc.querySelector('[data-fs-dismiss="modal"]').click(); assert.equal(doc.activeElement, trigger);
      } else {
        assert.equal(doc.getElementById('sector-label').htmlFor, 'sector');
        assert.equal(doc.getElementById('open-dialog').disabled, true);
      }
      assert.equal(doc.getElementById('areas').multiple, true);
      assert.equal(doc.getElementById('category').hasAttribute('data-fs'), false);
    } finally { window.close(); }
  });
}
