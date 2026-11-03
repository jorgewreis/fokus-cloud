import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { JSDOM } = await import(process.env.STYLES_JSDOM || 'jsdom');
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const guides = ['layout', 'forms', 'components', 'helpers', 'utilities'];
const markup = slug => read(`public/styles/docs/${slug ? `${slug}/` : ''}index.html`);
async function load(t, slug, runtime = false) {
  const dom = new JSDOM(markup(slug), { runScripts: 'outside-only', url: `https://styles.fokuscloud.com.br/${slug || 'docs'}`, pretendToBeVisual: true });
  t.after(() => dom.window.close());
  const w = dom.window;
  await new Promise(resolve => w.document.readyState === 'loading' ? w.document.addEventListener('DOMContentLoaded', resolve, { once: true }) : resolve());
  w.navigator.clipboard = { writeText: async value => { w.copied = value; } };
  if (runtime) { w.eval(read('public/styles/assets/fokus-2.7.0.min.js')); w.FokusStyles.Tabs.getOrCreateInstance(w.document.querySelector('[data-fs="tabs"]')); }
  w.eval(read('public/assets/js/styles-layout-doc.js'));
  return { w, d: w.document };
}

test('documentation hub lists exactly the five guides and all 56 catalog contracts, separating published and next', async t => {
  const { d } = await load(t, '');
  assert.deepEqual([...d.querySelectorAll('#guias .styles-doc-guide')].map(a => a.getAttribute('href')), guides.map(g => `/${g}`));
  const contracts = JSON.parse(read('public/styles/next/catalog.json')).contracts;
  const links = [...d.querySelectorAll('.styles-doc-contract-group a')];
  assert.equal(contracts.length, 56); assert.equal(links.length, 56);
  assert.deepEqual(links.map(a => a.hash.slice(1)).sort(), contracts.map(c => c.id).sort());
  for (const a of links) { assert.equal(a.pathname, '/styles/next/'); assert.ok(a.textContent.trim()); }
  assert.match(d.querySelector('#guias').textContent, /npm 2\.7\.0/);
  assert.match(d.querySelector('#componentes').textContent, /Em desenvolvimento.*next/s);
  assert.match(d.querySelector('#componentes .styles-doc-version').textContent, /separado do npm 2\.7\.0/);
  assert.equal(d.querySelectorAll('#versoes article').length, 2);
  assert.match(d.querySelector('#versoes article:last-child').textContent, /não representa uma versão estável/);
  assert.equal(d.querySelectorAll('#exemplos a[href$=".html"]').length, 3);
});

for (const slug of ['', ...guides]) test(`${slug || 'docs'}: one global navigation, semantic landmarks, valid anchors and guide return path`, async t => {
  const { d } = await load(t, slug);
  assert.equal(d.querySelectorAll('main').length, 1);
  assert.equal(d.querySelectorAll('main#main').length, 1);
  assert.equal(d.querySelectorAll('h1').length, 1);
  const navs = d.querySelectorAll('nav[aria-label="Navegação principal"]');
  assert.equal(navs.length, 1);
  assert.deepEqual([...navs[0].querySelectorAll('a')].map(a => a.getAttribute('href')), ['/', '/docs', 'https://github.com/jorgewreis/fokus-styles']);
  assert.ok(navs[0].querySelector('a[href="/docs"][aria-current]'));
  assert.equal(d.querySelector('a[href="#main"]').textContent.trim(), 'Ir para o conteúdo');
  assert.equal(d.querySelector('#main').getAttribute('tabindex'), '-1');
  const ids = [...d.querySelectorAll('[id]')].map(e => e.id);
  assert.equal(ids.length, new Set(ids).size);
  for (const a of d.querySelectorAll('a[href^="#"]')) if (a.hash) assert.ok(d.getElementById(decodeURIComponent(a.hash.slice(1))), a.outerHTML);
  if (slug) {
    assert.equal(d.querySelector('.styles-doc-sidebar-home').getAttribute('href'), '/docs');
    assert.deepEqual([...d.querySelectorAll('.styles-sidebar-page')].map(a => a.getAttribute('href')), guides.map(g => `/${g}`));
    assert.equal(d.querySelector('.styles-sidebar-page[aria-current="page"]').getAttribute('href'), `/${slug}`);
    assert.match(d.querySelector('.styles-doc-version').textContent, /npm 2\.7\.0/);
    assert.ok(d.querySelector('.styles-doc-version a[href="/docs#versoes"]'));
  }
});

test('Components uses official 2.7.0 tabs: buttons, click, keyboard, selected state and associated panels', async t => {
  const { w, d } = await load(t, 'components', true);
  assert.ok(d.querySelector('script[src="/styles/assets/fokus-2.7.0.min.js"]'));
  const tabs = [...d.querySelectorAll('[data-fs="tabs"] button')];
  assert.equal(tabs.length, 3);
  const check = selected => tabs.forEach((tab, i) => {
    assert.equal(tab.type, 'button'); assert.equal(tab.getAttribute('role'), 'tab');
    assert.equal(tab.getAttribute('aria-selected'), String(i === selected));
    assert.equal(tab.tabIndex, i === selected ? 0 : -1);
    const panel = d.querySelector(tab.dataset.fsTarget);
    assert.equal(tab.getAttribute('aria-controls'), panel.id);
    assert.equal(panel.getAttribute('role'), 'tabpanel'); assert.equal(panel.getAttribute('aria-labelledby'), tab.id);
    assert.equal(panel.hidden, i !== selected);
  });
  check(0); tabs[1].click(); check(1); tabs[1].focus();
  for (const [key, index] of [['ArrowRight', 2], ['ArrowRight', 0], ['ArrowLeft', 2], ['Home', 0], ['End', 2]]) {
    d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
    check(index); assert.equal(d.activeElement, tabs[index]);
  }
});

test('Helpers toggles gaps exclusively and updates snippet and pressed states', async t => {
  const { d } = await load(t, 'helpers'); const stage = d.querySelector('[data-helpers-stage]');
  assert.ok(stage.classList.contains('fs-stack-gap-3'));
  for (const gap of ['1', '4', '1']) {
    d.querySelector(`[data-helpers-gap="${gap}"]`).click();
    assert.deepEqual([...stage.classList].filter(c => c.startsWith('fs-stack-gap-')), [`fs-stack-gap-${gap}`]);
    assert.match(stage.querySelector('code').textContent, new RegExp(`fs-stack-gap-${gap}`));
    for (const button of d.querySelectorAll('[data-helpers-gap]')) assert.equal(button.getAttribute('aria-pressed'), String(button.dataset.helpersGap === gap));
  }
});

test('Utilities border has a style and every state updates colors, status, code and pressed controls', async t => {
  const { d } = await load(t, 'utilities');
  assert.ok(d.querySelector('.styles-utility-surface.fs-u-border-1.fs-u-border-solid'));
  const card = d.querySelector('[data-utilities-card]');
  for (const [state, label] of [['success', 'Concluído'], ['warning', 'Em atenção'], ['danger', 'Com erro'], ['success', 'Concluído']]) {
    d.querySelector(`[data-utilities-state="${state}"]`).click();
    assert.deepEqual([...card.classList].filter(c => /^fs-u-(bg|text)-/.test(c)).sort(), [`fs-u-bg-${state}-subtle`, `fs-u-text-${state}-emphasis`].sort());
    const status = card.querySelector('[data-utilities-status]'); assert.equal(status.textContent, label);
    assert.deepEqual([...status.classList].filter(c => c.startsWith('fs-badge-soft-')), [`fs-badge-soft-${state}`]);
    assert.equal(card.closest('.styles-lab-stage').querySelector('code').textContent, card.outerHTML);
    for (const button of d.querySelectorAll('[data-utilities-state]')) assert.equal(button.getAttribute('aria-pressed'), String(button.dataset.utilitiesState === state));
  }
});

test('Forms error feedback outside input group survives copied snippet and restore cancels pending success', async t => {
  const { w, d } = await load(t, 'forms');
  d.querySelector('[data-forms-demo="error"]').click();
  const email = d.querySelector('#lab-email'), feedback = d.querySelector('#lab-email-error');
  assert.equal(feedback.hidden, false); assert.equal(email.getAttribute('aria-invalid'), 'true');
  assert.ok(email.closest('.fs-input-group').classList.contains('is-invalid'));
  assert.equal(email.closest('.fs-input-group').nextElementSibling, feedback);
  assert.equal(d.activeElement, email);
  d.querySelector('[data-copy-target="forms-lab-code"]').click(); await Promise.resolve();
  assert.equal(w.copied, d.querySelector('#forms-lab-code code').textContent);
  const snippet = JSDOM.fragment(w.copied), copiedEmail = snippet.querySelector('#example-email');
  const copiedFeedback = snippet.querySelector('#example-email-error');
  assert.ok(copiedFeedback); assert.equal(copiedFeedback.hidden, false);
  assert.equal(copiedEmail.getAttribute('aria-describedby'), copiedFeedback.id);
  assert.equal(copiedEmail.closest('.fs-input-group.is-invalid').nextElementSibling, copiedFeedback);
  d.querySelector('[data-forms-demo="success"]').click();
  const form = d.querySelector('[data-forms-form]'); assert.equal(form.checkValidity(), true);
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  assert.equal(form.getAttribute('aria-busy'), 'true');
  assert.equal(d.querySelector('[data-forms-submit]').disabled, true);
  d.querySelector('[data-forms-reset]').click();
  await new Promise(resolve => setTimeout(resolve, 750));
  assert.equal(d.querySelector('[data-forms-success]').hidden, true);
  assert.equal(form.hasAttribute('aria-busy'), false);
  assert.equal(d.querySelector('[data-forms-submit]').disabled, false);
  assert.equal(d.querySelector('[data-forms-submit]').textContent, 'Criar cadastro');
  assert.equal(d.querySelector('[data-forms-status]').textContent, 'Demonstração restaurada.');
  assert.equal(email.value, ''); assert.equal(feedback.hidden, true);
  assert.equal(email.closest('.fs-input-group').classList.contains('is-invalid'), false);
});
