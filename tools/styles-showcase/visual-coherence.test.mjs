import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const { JSDOM } = await import(process.env.STYLES_JSDOM || 'jsdom');
const root = fileURLToPath(new URL('../..', import.meta.url));
const read = path => readFileSync(`${root}/${path}`, 'utf8');
const guides = ['layout', 'forms', 'components', 'helpers', 'utilities'];
const examples = ['projects', 'onboarding', 'records'];
const pages = ['public/styles/index.html', 'public/styles/docs/index.html', ...guides.map(x => `public/styles/docs/${x}/index.html`), 'public/styles/next/index.html', ...examples.map(x => `public/styles/examples/${x}.html`)];
const tokens = ['bg', 'surface', 'border', 'text', 'muted', 'accent', 'accent-soft', 'on-accent', 'code-bg', 'code-text', 'radius', 'font-sans'];

test('all eleven pages load one shared palette and the canonical or portable theme runtime', () => {
  assert.equal(pages.length, 11);
  for (const path of pages) {
    const d = new JSDOM(read(path)).window.document;
    const palette = [...d.querySelectorAll('link[rel="stylesheet"]')].filter(x => x.getAttribute('href').split('?')[0].endsWith('/site-palette.css'));
    assert.equal(palette.length, 1, path);
    const scripts = [...d.querySelectorAll('script[src]')].filter(x => x.getAttribute('src').split('?')[0].endsWith('/styles-theme.js'));
    assert.equal(scripts.length, 1, path);
    assert.equal(scripts[0].getAttribute('src').split('?')[0], path.includes('/examples/') ? '../assets/styles-theme.js' : '/assets/js/styles-theme.js', path);
  }
  assert.equal(read('public/styles/assets/styles-theme.js'), read('public/assets/js/styles-theme.js'));
});

test('shared palette defines its complete public token set and composition does not redefine framework colors', () => {
  const palette = read('public/styles/assets/site-palette.css');
  for (const token of tokens) assert.match(palette, new RegExp(`--site-${token}\\s*:`), token);
  assert.match(palette, /\[data-theme=["']?light/);
  assert.doesNotMatch(palette, /--fs-[\w-]+\s*:/);
  assert.doesNotMatch(read('public/styles/examples/composition.css'), /--fs-[\w-]+\s*:/);
  const showcase = read('public/assets/css/modules/styles-showcase.css');
  for (const token of ['bg', 'surface', 'border', 'text', 'muted', 'accent']) assert.match(showcase, new RegExp(`--showcase-${token}:\\s*var\\(--site-${token}\\)`));
});

test('portable ZIP contains byte-identical dependencies, HTML and offline documentation', () => {
  const result = spawnSync('python3', ['-c', `
from pathlib import Path
from zipfile import ZipFile
import re
root = Path('public/styles')
with ZipFile(root/'examples/fokus-styles-examples.zip') as z:
    expected = ['assets/site-palette.css','assets/styles-theme.js','assets/fokus-2.7.0.min.css','assets/LICENSE-fokus-styles.txt','examples/composition.css','examples/README.md'] + ['examples/'+s+'.html' for s in ['projects','onboarding','records']]
    for name in expected:
        assert z.read(name) == (root/name).read_bytes(), name+' differs from preview source'
    for name in expected:
        if not name.endswith('.html'): continue
        html = z.read(name).decode()
        for reference in re.findall(r'<(?:link|script)\\b[^>]*(?:href|src)=["\\\']([^"\\\']+)', html):
            assert not reference.startswith(('http:', 'https:', '//')), 'External dependency: '+reference
            from posixpath import normpath, join, dirname
            target = normpath(join(dirname(name), reference.split('?')[0]))
            assert target in z.namelist(), target+' absent from ZIP'
`], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || result.error?.message);
  const readme = read('public/styles/examples/README.md');
  for (const fragment of ['site-palette.css', 'styles-theme.js', 'composition.css', 'offline']) {
    if (fragment === 'offline') assert.match(readme, /offline|sem dependência|não precisam.*rede/s);
    else assert.ok(readme.includes(fragment), fragment);
  }
});

async function runtime(t, saved, blocked = false) {
  const dom = new JSDOM('<!doctype html><html data-theme="dark"><body><button data-theme-toggle>Theme</button><iframe src="/styles/examples/projects.html"></iframe><iframe src="/styles/next/"></iframe></body></html>', { url: 'https://styles.test/', runScripts: 'outside-only' });
  t.after(() => dom.window.close());
  const w = dom.window;
  await new Promise(resolve => w.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  if (saved) w.localStorage.setItem('fokus-styles-theme', saved);
  if (blocked) Object.defineProperty(w, 'localStorage', { get() { throw new Error('Storage blocked'); } });
  const messages = [[], []];
  [...w.document.querySelectorAll('iframe')].forEach((f, i) => { f.contentWindow.postMessage = data => messages[i].push(data); });
  w.eval(read('public/assets/js/styles-theme.js'));
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  return { w, messages };
}

for (const saved of ['light', 'dark', 'invalid']) test(`theme preference ${saved}: accessible controls and only portable frames receive updates`, async t => {
  const { w, messages } = await runtime(t, saved);
  const initial = saved === 'light' ? 'light' : 'dark';
  const button = w.document.querySelector('button');
  assert.equal(w.document.documentElement.dataset.theme, initial);
  assert.equal(button.getAttribute('aria-pressed'), String(initial === 'dark'));
  button.click();
  const next = initial === 'dark' ? 'light' : 'dark';
  assert.equal(w.document.documentElement.dataset.theme, next);
  assert.equal(w.localStorage.getItem('fokus-styles-theme'), next);
  assert.deepEqual(messages[0].map(x => x.theme), [initial, initial, next]);
  assert.equal(messages[1].length, 0, 'next must never receive site theme messages');
});

test('theme toggle remains functional when browser storage is denied', async t => {
  const { w } = await runtime(t, null, true);
  w.document.querySelector('button').click();
  assert.equal(w.document.documentElement.dataset.theme, 'light');
});

test('static same-origin thumbnails receive theme without allowing scripts', async t => {
  const { w } = await runtime(t, 'dark');
  const preview = w.document.querySelector('iframe');
  preview.setAttribute('sandbox', 'allow-same-origin');
  // jsdom does not fetch the external iframe document in this fixture.
  preview.contentDocument.open();
  preview.contentDocument.write('<!doctype html><html><body>Static preview</body></html>');
  preview.contentDocument.close();
  w.document.querySelector('button').click();
  assert.equal(preview.getAttribute('sandbox'), 'allow-same-origin');
  assert.equal(preview.contentDocument.documentElement.dataset.theme, 'light');
  assert.equal(preview.contentDocument.documentElement.dataset.embedded, 'true');
  assert.equal(preview.contentDocument.documentElement.style.colorScheme, 'light');
});


test('six editorial CTAs are explicitly scoped and keep framework demonstrations unbranded', () => {
  for (const path of ['public/styles/index.html', 'public/styles/docs/index.html']) {
    const dom = new JSDOM(read(path));
    try {
      const d = dom.window.document;
      const ctas = [...d.querySelectorAll('.styles-site-cta')];
      assert.equal(ctas.length, 3, path);
      assert.ok(ctas.every(node => node.matches('a.fs-btn[href]')), path);
      assert.equal(d.querySelectorAll('[data-component-demo].styles-site-cta').length, 0);
      assert.equal(ctas.filter(node => node.classList.contains('fs-btn-outline-primary')).length, path.endsWith('/docs/index.html') ? 0 : 2);
    } finally { dom.window.close(); }
  }
});

test('portable tables explain horizontal scrolling and the chart preserves its proportions', () => {
  for (const model of ['projects', 'records']) {
    const dom = new JSDOM(read(`public/styles/examples/${model}.html`));
    try {
      const d = dom.window.document;
      const region = d.querySelector('.demo-scroll');
      assert.equal(region.getAttribute('tabindex'), '0');
      assert.equal(region.getAttribute('role'), 'region');
      const hint = d.getElementById(region.getAttribute('aria-describedby'));
      assert.ok(hint?.matches('.demo-scroll-hint'));
      assert.match(hint.textContent, /Deslize a tabela/);
      if (model === 'records') assert.match(hint.textContent, /botão Ver/);
      if (model === 'projects') {
        assert.equal(d.querySelector('.desk-chart svg').getAttribute('preserveAspectRatio'), 'xMidYMid meet');
        assert.deepEqual([...d.querySelectorAll('.desk-chart-labels span')].map(node => node.textContent), ['S1', 'S2', 'S3', 'S4', 'S5', 'S6']);
      }
    } finally { dom.window.close(); }
  }
});
