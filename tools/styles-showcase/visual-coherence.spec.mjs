import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
const guides = ['layout', 'forms', 'components', 'helpers', 'utilities'];
const models = ['projects', 'onboarding', 'records'];
const routes = ['/', '/docs', ...guides.map(x => `/${x}`), '/styles/next/', ...models.map(x => `/styles/examples/${x}.html`)];
const key = 'fokus-styles-theme';
const palette = {
  dark: { bg: 'rgb(5, 11, 9)', text: 'rgb(238, 245, 240)', accent: 'rgb(67, 241, 139)' },
  light: { bg: 'rgb(247, 250, 247)', text: 'rgb(20, 38, 27)', accent: 'rgb(21, 108, 67)' },
};
const editorialScope = ['.styles-topbar', '.styles-doc-breadcrumb', '.styles-doc-version', '.styles-sidebar', '.styles-hero', '.layout-doc-heading', '.forms-doc-heading', '.styles-component-heading', '.layout-doc-principles', '.layout-doc-guidance', '.forms-doc-guidance', '.styles-doc-pager', '.styles-footer'];
async function setTheme(page, theme) {
  await page.addInitScript(({ key, theme }) => localStorage.setItem(key, theme), { key, theme });
}
async function evidence(page, info, name) {
  const path = info.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true, animations: 'disabled' });
  await info.attach(name, { path, contentType: 'image/png' });
}
const colorProperties = ['color', 'backgroundColor', 'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor'];
async function componentColors(page, selector) {
  await page.locator('body').evaluate(async (body, selector) => {
    // Flush the loaded package styles before collecting transitions. Compare
    // their final colors, without disabling the framework's motion defaults.
    for (const node of body.querySelectorAll(selector)) {
      getComputedStyle(node).backgroundColor;
      getComputedStyle(node).color;
    }
    const finiteAnimations = body.getAnimations({ subtree: true }).filter(animation =>
      Number.isFinite(animation.effect.getComputedTiming().endTime));
    await Promise.all(finiteAnimations.map(animation => animation.finished));
  }, selector);
  return page.locator(selector).evaluateAll((nodes, properties) => nodes.map(node => {
    const css = getComputedStyle(node);
    return { tag: node.tagName, classes: node.className, colors: Object.fromEntries(properties.map(name => [name, css[name]])) };
  }), colorProperties);
}

for (const width of [1440, 390, 320]) for (const theme of ['light', 'dark']) for (const route of routes) {
  test(`shared visual language ${route} ${width}px ${theme}`, async ({ page }, info) => {
    test.setTimeout(60_000);
    await page.setViewportSize({ width, height: 900 });
    await setTheme(page, theme);
    await page.goto(route);
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    const model = route.includes('/examples/');
    const guide = guides.some(x => route === `/${x}`);
    const values = await page.evaluate(() => {
      const body = getComputedStyle(document.body);
      const probe = document.createElement('i'); document.body.append(probe);
      const colors = {};
      for (const token of ['bg', 'text', 'accent', 'on-accent', 'code-bg', 'code-text']) {
        probe.style.color = `var(--site-${token})`; colors[token] = getComputedStyle(probe).color;
      }
      probe.remove();
      const heading = getComputedStyle(document.querySelector('h1'));
      const header = document.querySelector('.showcase-nav, .styles-topbar-inner');
      const wrap = document.querySelector('.showcase-wrap, .styles-shell');
      const accent = document.querySelector('.styles-eyebrow, .showcase-kicker, .demo-kicker');
      return { colors, bg: body.backgroundColor, text: body.color, font: body.fontFamily, headingFont: heading.fontFamily, headingSize: parseFloat(heading.fontSize), overflow: document.documentElement.scrollWidth - innerWidth, headerHeight: header?.getBoundingClientRect().height, padding: wrap && parseFloat(getComputedStyle(wrap).paddingLeft), accent: accent && getComputedStyle(accent).color };
    });
    for (const token of ['bg', 'text', 'accent']) expect(values.colors[token], `${route}: shared ${token}`).toBe(palette[theme][token]);
    expect(values.bg, 'Body background uses site palette').toBe(values.colors.bg);
    expect(values.text, 'Body foreground uses site palette').toBe(values.colors.text);
    expect(values.colors.bg).not.toBe(values.colors.text);
    expect(values.overflow, `${route} horizontal overflow`).toBeLessThanOrEqual(1);
    expect(values.font).toMatch(model ? /system-ui/ : /Google Sans/);
    if (values.accent) expect(values.accent, 'Visible accent belongs to shared palette').toBe(values.colors.accent);
    if (!model) {
      expect(values.headingFont).toContain('Google Sans');
      expect(values.headingFont).not.toContain('Bebas');
      expect(values.padding).toBe(width === 1440 ? 42 : 20);
      if (width === 1440) expect(values.headerHeight).toBe(78);
    }
    if (guide) {
      if (width === 1440) { expect(values.headingSize).toBeGreaterThanOrEqual(42); expect(values.headingSize).toBeLessThanOrEqual(64); }
      const code = page.locator('pre.styles-code, .styles-component-code pre, pre.styles-lab-code').first();
      await expect(code).toHaveCSS('background-color', values.colors['code-bg']);
      await expect(code).toHaveCSS('color', values.colors['code-text']);
      if (await code.locator('code').count()) await expect(code.locator('code')).toHaveCSS('color', values.colors['code-text']);
    }
    if (model) {
      await expect(page.locator('.demo-site-nav')).toBeVisible();
      await expect(page.locator('[data-theme-toggle]')).toHaveAttribute('aria-pressed', String(theme === 'dark'));
    }
    if (route === '/' || route === '/docs') {
      const ctas = page.locator('.styles-site-cta');
      await expect(ctas).toHaveCount(3);
      await componentColors(page, '.styles-site-cta');
      for (const cta of await ctas.all()) {
        const outline = await cta.evaluate(node => node.classList.contains('fs-btn-outline-primary'));
        await expect(cta).toHaveCSS('border-top-color', values.colors.accent);
        await expect(cta).toHaveCSS('color', values.colors[outline ? 'accent' : 'on-accent']);
        await expect(cta).toHaveCSS('background-color', outline ? 'rgba(0, 0, 0, 0)' : values.colors.accent);
      }
      const audit = await new AxeBuilder({ page }).include('.styles-site-cta').withRules(['color-contrast']).analyze();
      await info.attach('editorial-cta-contrast.json', { body: JSON.stringify(audit, null, 2), contentType: 'application/json' });
      expect(audit.violations, 'Editorial CTA text contrast').toEqual([]);
    }
    if (route.endsWith('/projects.html') || route.endsWith('/records.html')) {
      const scroll = page.locator('.demo-scroll');
      const hintId = await scroll.getAttribute('aria-describedby');
      expect(hintId).toMatch(/^(projects|records)-scroll-hint$/);
      const hint = page.locator(`#${hintId}`);
      await expect(hint).toContainText('Deslize a tabela');
      await expect(scroll).toHaveAttribute('tabindex', '0');
      await expect(scroll).toHaveAttribute('role', 'region');
      if (width <= 390) {
        await expect(hint).toBeVisible();
        const hintBox = await hint.boundingBox();
        expect(hintBox.x).toBeGreaterThanOrEqual(0);
        expect(hintBox.x + hintBox.width).toBeLessThanOrEqual(width + 1);
        await expect(scroll).toHaveCSS('overflow-x', 'auto');
      }
      if (route.endsWith('/records.html')) await expect(hint).toContainText('botão Ver');
    }
    if (route.endsWith('/projects.html')) {
      const chart = page.locator('.desk-chart svg');
      await expect(chart).toHaveAttribute('preserveAspectRatio', 'xMidYMid meet');
      const scale = await chart.evaluate(node => {
        const matrix = node.getScreenCTM();
        return { x: Math.hypot(matrix.a, matrix.b), y: Math.hypot(matrix.c, matrix.d) };
      });
      expect(scale.x).toBeGreaterThan(0);
      expect(scale.x, 'Chart must not stretch differently along its axes').toBeCloseTo(scale.y, 6);
      const labels = page.locator('.desk-chart-labels span');
      await expect(labels).toHaveText(['S1', 'S2', 'S3', 'S4', 'S5', 'S6']);
      const metrics = await labels.evaluateAll(nodes => nodes.map(node => ({ font: parseFloat(getComputedStyle(node).fontSize), left: node.getBoundingClientRect().left, right: node.getBoundingClientRect().right })));
      for (const [index, metric] of metrics.entries()) {
        expect(metric.font, 'HTML chart labels remain readable on mobile').toBeGreaterThanOrEqual(12);
        if (index) expect(metric.left).toBeGreaterThanOrEqual(metrics[index - 1].right);
      }
    }
    // This gate covers the newly styled editorial structure, not the unchanged
    // legacy labs/component fixtures. Existing showcase and component gates remain.
    if (guide) {
      let builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']);
      const included = [];
      for (const selector of editorialScope) if (await page.locator(selector).count()) { builder = builder.include(selector); included.push(selector); }
      expect(included.length).toBeGreaterThan(5);
      const audit = await builder.analyze();
      await info.attach('editorial-axe-scope.json', { body: JSON.stringify({ included, excluded: 'Unchanged component demonstrations and interactive laboratory stages', violations: audit.violations }, null, 2), contentType: 'application/json' });
      expect.soft(audit.violations, 'New editorial structure only').toEqual([]);
    }
    await evidence(page, info, `coherence-${route.replace(/\W+/g, '-')}-${width}-${theme}`);
  });
}

for (const theme of ['light', 'dark']) {
  test(`guide component colors match identical HTML without editorial stylesheet: ${theme}`, async ({ page, context }) => {
    test.setTimeout(90_000);
    const control = await context.newPage();
    await setTheme(page, theme); await setTheme(control, theme);
    await control.route('**/styles-documentation.css*', route => route.fulfill({ contentType: 'text/css', body: '/* Control: editorial stylesheet intentionally absent. */' }));
    const selector = 'main .fs-btn, main .fs-input, main .fs-select, main .fs-badge, main .fs-alert, main .fs-table, main [role="tab"]';
    try {
      for (const slug of guides) {
        await page.goto(`/${slug}`); await control.goto(`/${slug}`);
        const actual = await componentColors(page, selector);
        expect(actual.length, `${slug}: comparison must include real components`).toBeGreaterThan(0);
        expect(actual, `${slug}: editorial CSS must not recolor package components`).toEqual(await componentColors(control, selector));
        if (slug === 'helpers') {
          for (const document of [page, control]) {
            const link = document.locator('main a.fs-btn.fs-btn-primary');
            await expect(link).toHaveCount(1);
            const foreground = await link.evaluate(node => {
              const css = getComputedStyle(node);
              const token = css.getPropertyValue('--fs-btn-color').trim();
              const probe = document.createElement('span');
              probe.style.color = 'var(--fs-btn-color)';
              node.append(probe);
              const expected = getComputedStyle(probe).color;
              probe.remove();
              return { actual: css.color, expected, token, body: getComputedStyle(document.body).color };
            });
            expect(foreground.token, 'Helpers link must expose its framework foreground token').not.toBe('');
            expect(foreground.actual, 'Helpers link uses --fs-btn-color').toBe(foreground.expected);
            expect(foreground.actual, 'The editorial anchor reset must not replace the button foreground with body text').not.toBe(foreground.body);
          }
        }
      }
    } finally { await control.close(); }
  });

  test(`portable model buttons retain pure npm colors: ${theme}`, async ({ page, context }) => {
    const control = await context.newPage();
    await setTheme(page, theme);
    try {
      for (const model of models) {
        await page.goto(`/styles/examples/${model}.html`);
        const buttons = await page.locator('.fs-btn').evaluateAll(nodes => nodes.map(n => n.outerHTML));
        expect(buttons.length).toBeGreaterThan(0);
        const css = new URL('/styles/assets/fokus-2.7.0.min.css', page.url()).href;
        await control.setContent(`<!doctype html><html data-theme="${theme}"><head><link rel="stylesheet" href="${css}"></head><body>${buttons.join('')}</body></html>`);
        expect(await componentColors(page, '.fs-btn'), model).toEqual(await componentColors(control, '.fs-btn'));
      }
    } finally { await control.close(); }
  });
}

test('theme controls persist through navigation/reload and portable frames follow the parent', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => localStorage.setItem('fokus-styles-theme', 'dark'));
  await page.reload();
  // Check every preview, including sandboxed miniature cards: they must not stay dark.
  const frames = page.locator('iframe[src^="/styles/examples/"]');
  expect(await frames.count()).toBeGreaterThan(0);
  for (const target of ['light', 'dark', 'light']) {
    await page.locator('[data-theme-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', target);
    for (let i = 0; i < await frames.count(); i++) {
      const frame = await (await frames.nth(i).elementHandle()).contentFrame();
      await expect(frame.locator('html')).toHaveAttribute('data-theme', target);
      await expect(frame.locator('html')).toHaveAttribute('data-embedded', 'true');
      await expect(frame.locator('.demo-site-nav')).toBeHidden();
    }
  }
  await page.goto('/docs'); await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.reload(); await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.goto('/styles/examples/projects.html');
  await expect(page.locator('.demo-site-nav')).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
});

test('next sandbox inherits neither parent theme nor site shell CSS', async ({ page }) => {
  await setTheme(page, 'dark');
  await page.goto('/styles/next/#tabs');
  const preview = page.locator('#catalog-detail iframe').first();
  await expect(preview).toBeVisible();
  const frame = await (await preview.elementHandle()).contentFrame();
  const initial = await frame.locator('html').getAttribute('data-theme');
  expect(await frame.locator('link[href*="site-palette"], link[href*="styles-documentation"], link[href*="styles-showcase"], script[src*="styles-theme"]').count()).toBe(0);
  expect(await frame.locator('body').evaluate(el => getComputedStyle(el).getPropertyValue('--site-bg').trim())).toBe('');
  await page.locator('[data-theme-toggle]').click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  expect(await frame.locator('html').getAttribute('data-theme')).toBe(initial);
});
