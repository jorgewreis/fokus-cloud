// Run: STYLES_JSDOM=/absolute/path/to/jsdom/lib/api.js node tools/styles-showcase/test-dom.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const { JSDOM } = await import(process.env.STYLES_JSDOM || 'jsdom');
const root = fileURLToPath(new URL('../../public/', import.meta.url));
const read = path => readFileSync(root + path, 'utf8');
const home = read('styles/index.html');
// The showcase uses clear labels, not decorative arrow glyphs.
assert.doesNotMatch(home, /[↗→➜➔]/u, 'No decorative arrows in showcase markup');
assert.doesNotMatch(read('assets/css/modules/styles-showcase.css'), /[↗→➜➔]/u, 'No CSS-generated decorative arrows');
const stylesheet = read('styles/vendor/fokus-2.7.0.min.css');
for (const slug of ['projects', 'onboarding', 'records']) {
  const html = read(`styles/examples/${slug}.html`);
  const dom = new JSDOM(html, { runScripts: 'dangerously' });
  const d = dom.window.document;
  const ids = [...d.querySelectorAll('[id]')].map(e => e.id);
  assert.equal(new Set(ids).size, ids.length, slug + ': duplicate IDs');
  for (const element of d.querySelectorAll('[class]')) for (const cls of element.classList) {
    if (cls.startsWith('fs-')) assert.ok(stylesheet.includes('.' + cls), `Unpublished class ${cls}`);
  }
  for (const label of d.querySelectorAll('label[for]')) assert.ok(d.getElementById(label.htmlFor));
  for (const el of d.querySelectorAll('[aria-describedby]')) for (const id of el.getAttribute('aria-describedby').split(' ')) assert.ok(d.getElementById(id));
  if (slug === 'onboarding') {
    const form = d.querySelector('form');
    form.dispatchEvent(new dom.window.Event('submit', { cancelable: true }));
    assert.equal(d.querySelectorAll('[aria-invalid=true]').length, 2);
    assert.equal(d.activeElement.id, 'name');
    d.querySelector('#name').value = 'Ana'; d.querySelector('#email').value = 'ana@example.com';
    form.dispatchEvent(new dom.window.Event('submit', { cancelable: true }));
    assert.equal(d.querySelectorAll('[aria-invalid=true]').length, 0);
    assert.match(d.querySelector('#form-status').textContent, /Nenhum dado/);
    form.reset(); assert.equal(d.querySelector('#form-status').textContent, '');
    assert.equal(d.querySelector('#name').value, '');
  }
  if (slug === 'records') {
    const search = d.querySelector('#search');
    for (const [term, count] of [['design', 1], ['zzzzz', 0], ['', 3], ['LÍA', 1]]) {
      search.value = term; search.dispatchEvent(new dom.window.Event('input'));
      assert.equal(d.querySelectorAll('[data-record]:not([hidden])').length, count);
    }
    d.querySelector('[data-name="Lia Alves"]').click();
    assert.match(d.querySelector('#detail').textContent, /lia@example.com/);
  }
  dom.window.close();
}
const dom = new JSDOM(home, { runScripts: 'outside-only', url: 'https://styles.fokuscloud.com.br/' });
const w = dom.window, d = w.document;
w.HTMLElement.prototype.scrollIntoView = () => {};
w.navigator.clipboard = { writeText: async value => { w.copied = value; } };
let resolvers = [];
w.fetch = url => new Promise(resolve => resolvers.push({ url, resolve }));
w.eval(read('assets/js/styles-showcase.js'));
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
for (const slug of ['projects', 'onboarding', 'records']) {
  d.querySelector(`[data-example="${slug}"]`).click();
  const pending = resolvers.shift();
  assert.equal(pending.url, `/styles/examples/${slug}.html`);
  pending.resolve({ ok: true, text: async () => read(`styles/examples/${slug}.html`) }); await tick();
  assert.equal(d.querySelector('#example-source').textContent, read(`styles/examples/${slug}.html`));
  d.querySelector('#copy-source').click(); await tick(); assert.equal(w.copied, read(`styles/examples/${slug}.html`));
}
// Newer navigation wins; dismissal invalidates pending fetches.
d.querySelector('[data-example="projects"]').click();
d.querySelector('[data-example="records"]').click();
const old = resolvers.shift(), latest = resolvers.shift();
latest.resolve({ ok: true, text: async () => 'LATEST' }); await tick();
old.resolve({ ok: true, text: async () => 'STALE' }); await tick();
assert.equal(d.querySelector('#example-source').textContent, 'LATEST');
d.querySelector('[data-example="onboarding"]').click();
d.querySelector('#close-source').click();
resolvers.shift().resolve({ ok: true, text: async () => 'AFTER CLOSE' }); await tick();
assert.equal(d.querySelector('#source-panel').hidden, true);
assert.equal(d.activeElement.dataset.example, 'onboarding');
d.querySelector('[data-example="projects"]').click();
resolvers.shift().resolve({ ok: false }); await tick();
assert.equal(d.querySelector('#copy-source').disabled, true);
assert.match(d.querySelector('#copy-status').textContent, /Não foi possível/);
w.close();
console.log('PASS: published classes, IDs/labels, validation/reset, normalized search/empty/details, 3 exact source copies, request race, dismissal/focus, failed fetch.');
