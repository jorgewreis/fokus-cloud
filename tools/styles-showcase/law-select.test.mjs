import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE
  ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const html = await readFile(new URL('./fixtures/law-select.html', import.meta.url), 'utf8');
const bundle = await readFile(new URL('../../public/styles/assets/fokus-3.0.0.min.js', import.meta.url), 'utf8');
const adapter = await readFile(new URL('../../public/portal/assets/fokus-law-select.js', import.meta.url), 'utf8');

test('adaptador Law distingue Select simples de controles nativos', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const simpleLabel = window.document.querySelector('label[for="law-simple"]');
    window.eval(bundle);
    window.eval(adapter);
    if (window.document.readyState === 'loading') await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const doc = window.document, Select = window.FokusStyles.Select;
    const simple = doc.getElementById('law-simple');
    const instance = Select.getInstance(simple);
    assert.ok(instance);
    assert.equal(instance.menuEl.getAttribute('aria-labelledby'), instance.toggleEl.id);
    assert.equal(simpleLabel.htmlFor, instance.toggleEl.id);
    assert.equal(instance.toggleEl.getAttribute('aria-describedby'), 'law-simple-help');
    for (const id of ['law-multiple', 'law-modal-select']) {
      const select = doc.getElementById(id);
      assert.ok(Select.getInstance(select) == null);
      assert.equal(select.hasAttribute('data-fs'), false);
      assert.equal(select.labels[0].htmlFor, id);
    }
  } finally { window.close(); }
});

for (const mode of ['multiple', 'size', 'modal']) {
  test(`transição para ${mode} restaura vínculos e permite retorno ao widget`, async () => {
    const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
    const { window } = dom;
    try {
      const doc = window.document, select = doc.getElementById('law-simple');
      const label = doc.querySelector('label[for="law-simple"]');
      select.setAttribute('aria-label', 'Setor fictício');
      window.eval(bundle); window.eval(adapter);
      if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
      const oldToggle = window.FokusStyles.Select.getInstance(select).toggleEl;
      assert.equal(label.htmlFor, oldToggle.id);
      assert.equal(oldToggle.getAttribute('aria-describedby'), 'law-simple-help');
      if (mode === 'multiple') select.multiple = true;
      else if (mode === 'size') select.size = 3;
      else doc.querySelector('#law-demo-modal .fs-modal-body').append(select);
      await new Promise(resolve => setTimeout(resolve, 0));
      assert.ok(window.FokusStyles.Select.getInstance(select) == null);
      assert.equal(oldToggle.isConnected, false);
      assert.equal(label.htmlFor, select.id);
      assert.equal(select.getAttribute('aria-label'), 'Setor fictício');
      assert.equal(select.getAttribute('aria-describedby'), 'law-simple-help');
      if (mode === 'multiple') select.multiple = false;
      else if (mode === 'size') select.size = 1;
      else doc.querySelector('.demo-field').append(select);
      // Attribute changes are observed; moving the native select back needs refresh.
      if (mode === 'modal') window.FokusLawSelect.refresh(select);
      await new Promise(resolve => setTimeout(resolve, 0));
      assert.ok(window.FokusStyles.Select.getInstance(select));
      assert.equal(label.htmlFor, window.FokusStyles.Select.getInstance(select).toggleEl.id);
    } finally { window.close(); }
  });
}

test('restauração preserva label e atributos externos, inclusive valor vazio', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    const label = doc.querySelector('label[for="law-simple"]');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    label.htmlFor = 'outro-controle';
    select.setAttribute('aria-label', '');
    select.setAttribute('aria-describedby', 'ajuda-externa');
    select.multiple = true;
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(label.htmlFor, 'outro-controle');
    assert.equal(select.getAttribute('aria-label'), '');
    assert.equal(select.getAttribute('aria-describedby'), 'ajuda-externa');
  } finally { window.close(); }
});

test('refresh intermediário preserva ARIA externo e restaura label ao ID atualizado', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    const label = doc.querySelector('label[for="law-simple"]');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    select.id = 'setor-atualizado';
    select.setAttribute('aria-labelledby', 'rotulo-externo');
    select.setAttribute('aria-describedby', '');
    window.FokusLawSelect.refresh(select);
    select.size = 3;
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(label.htmlFor, 'setor-atualizado');
    assert.equal(select.getAttribute('aria-labelledby'), 'rotulo-externo');
    assert.equal(select.getAttribute('aria-describedby'), '');
  } finally { window.close(); }
});

test('foco do toggle é restaurado ao select nativo no DOM simulado', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const toggle = window.FokusStyles.Select.getInstance(select).toggleEl;
    toggle.focus();
    assert.equal(doc.activeElement, toggle);
    select.multiple = true;
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(toggle.isConnected, false);
    assert.equal(doc.activeElement, select);
  } finally { window.close(); }
});

test('transição não toma foco de outro controle', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const other = doc.querySelector('[data-fs="modal"]');
    other.focus(); select.multiple = true;
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(doc.activeElement, other);
  } finally { window.close(); }
});

test('refresh preserva foco no novo toggle quando o anterior tinha foco', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const oldToggle = window.FokusStyles.Select.getInstance(select).toggleEl;
    oldToggle.focus(); window.FokusLawSelect.refresh(select);
    const newToggle = window.FokusStyles.Select.getInstance(select).toggleEl;
    assert.notEqual(newToggle, oldToggle);
    assert.equal(doc.activeElement, newToggle);
  } finally { window.close(); }
});

test('foco numa opção do menu retorna ao select ao tornar-se múltiplo', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const option = window.FokusStyles.Select.getInstance(select).menuEl.querySelector('.fs-dropdown-item');
    option.focus(); assert.equal(doc.activeElement, option);
    select.multiple = true;
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(doc.activeElement, select);
  } finally { window.close(); }
});

test('transição para nativo desabilitado não desloca foco externo', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    const other = doc.querySelector('[data-fs="modal"]'); other.focus();
    select.disabled = true; select.multiple = true;
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(doc.activeElement, other);
    assert.equal(select.disabled, true);
  } finally { window.close(); }
});

test('toggle conserva aria-labelledby atualizado e aria-label anterior', async () => {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  try {
    const doc = window.document, select = doc.getElementById('law-simple');
    select.setAttribute('aria-label', 'Nome anterior');
    window.eval(bundle); window.eval(adapter);
    if (doc.readyState === 'loading') await new Promise(resolve => doc.addEventListener('DOMContentLoaded', resolve, {once:true}));
    select.setAttribute('aria-labelledby', 'law-simple-help');
    const instance = window.FokusLawSelect.refresh(select);
    assert.equal(instance.toggleEl.getAttribute('aria-labelledby'), 'law-simple-help');
    assert.equal(instance.toggleEl.getAttribute('aria-label'), 'Nome anterior');
  } finally { window.close(); }
});
