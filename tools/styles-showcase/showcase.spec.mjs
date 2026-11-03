import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { PUBLISHED_ASSETS, PUBLISHED_CSS_PATH } from './published-assets.mjs';
const example = slug => readFileSync(new URL(`../../public/styles/examples/${slug}.html`, import.meta.url), 'utf8');

async function expectPublishedStylesLoaded(document) {
  await expect.poll(() => document.evaluate(path => {
    const sheet = [...window.document.styleSheets].find(sheet => sheet.href && new URL(sheet.href).pathname === path);
    return Boolean(sheet && !sheet.disabled && sheet.cssRules.length > 0);
  }, PUBLISHED_CSS_PATH), { message: 'Published stylesheet must be loaded and enabled, not merely linked' }).toBe(true);
}

const colors = locator => locator.evaluate(element => {
  const style = getComputedStyle(element);
  return {
    background: style.backgroundColor, color: style.color,
    borderColor: style.borderTopColor, borderStyle: style.borderTopStyle, borderWidth: style.borderTopWidth,
  };
});
const transparent = color => color === 'transparent' || /(?:rgba?\([^)]*,\s*0\)|\/\s*0\))$/.test(color);

test('published stylesheet and MIT license are served intact over HTTP', async ({ request }) => {
  for (const [asset, sha256] of Object.entries(PUBLISHED_ASSETS)) {
    const response = await request.get(asset);
    expect(response.status(), asset).toBe(200);
    if (asset.endsWith('.css')) expect(response.headers()['content-type']).toContain('text/css');
    expect(createHash('sha256').update(await response.body()).digest('hex'), asset).toBe(sha256);
  }
});

test('component cards use published button colors and real table styling in both themes', async ({ page, context }) => {
  await page.goto('/styles/index.html');
  await expectPublishedStylesLoaded(page);
  const reference = await context.newPage();
  try {
    // Independent control: same browser/theme, only the unmodified published
    // framework. Marketing CSS and its tokens must not redefine these demos.
    await reference.route('**/__styles_framework_reference__', route => route.fulfill({
      contentType: 'text/html', body: `<!doctype html><html lang="pt-BR" data-theme="dark"><head>
        <link rel="stylesheet" href="${PUBLISHED_CSS_PATH}"></head><body>
        <button id="primary" class="fs-btn fs-btn-primary">Primário</button>
        <button id="outline" class="fs-btn fs-btn-outline-primary">Outline</button>
        <button id="native">Sem framework</button>
        <table class="fs-table"><thead><tr><th>Projeto</th><th>Status</th></tr></thead><tbody>
        <tr><td>Portal</td><td><span class="fs-badge fs-badge-soft-success">Ativo</span></td></tr>
        <tr><td>Biblioteca</td><td><span class="fs-badge fs-badge-soft-warning">Revisão</span></td></tr>
        <tr><td>Interface</td><td><span class="fs-badge fs-badge-soft-info">Novo</span></td></tr>
        </tbody></table></body></html>`,
    }));
    await reference.goto(new URL('/__styles_framework_reference__', page.url()).href);
    await expectPublishedStylesLoaded(reference);
    for (const theme of ['dark', 'light']) {
      for (const document of [page, reference]) {
        await document.locator('html').evaluate((html, theme) => html.dataset.theme = theme, theme);
        await document.locator('body').evaluate(async body => {
          // Flush the theme change and wait for real CSS transitions before
          // measuring. The reference uses the framework's own motion defaults.
          getComputedStyle(body.querySelector('.fs-btn')).backgroundColor;
          await Promise.all(body.getAnimations({ subtree: true }).map(animation => animation.finished));
        });
      }
      const primary = page.locator('[data-component-demo="primary"]');
      const outline = page.locator('[data-component-demo="outline"]');
      await expect(primary).toBeVisible();
      await expect(outline).toBeVisible();
      const expectedPrimary = await colors(reference.locator('#primary'));
      const expectedOutline = await colors(reference.locator('#outline'));
      await expect.poll(() => colors(primary), { message: `${theme}: primary must match published defaults` }).toEqual(expectedPrimary);
      await expect.poll(() => colors(outline), { message: `${theme}: outline must match published defaults` }).toEqual(expectedOutline);
      expect(transparent(expectedPrimary.background)).toBe(false);
      expect(transparent(expectedOutline.background)).toBe(true);
      expect(expectedPrimary.background).not.toBe((await colors(reference.locator('#native'))).background);
      expect(expectedPrimary.background).not.toBe(expectedOutline.background);
      expect(Number.parseFloat(expectedOutline.borderWidth)).toBeGreaterThan(0);
      expect(expectedOutline.borderStyle).toBe('solid');

      const table = page.locator('.showcase-component .fs-table');
      await expect(table).toBeVisible();
      await expect(table.locator('tbody tr')).toHaveCount(3);
      const layout = await table.evaluate(element => {
        const parent = element.parentElement;
        const parentStyle = getComputedStyle(parent);
        return {
          tableWidth: element.getBoundingClientRect().width,
          availableWidth: parent.clientWidth - parseFloat(parentStyle.paddingLeft) - parseFloat(parentStyle.paddingRight),
          borderCollapse: getComputedStyle(element).borderCollapse,
        };
      });
      expect(Math.abs(layout.tableWidth - layout.availableWidth), `${theme}: table must fill the card`).toBeLessThanOrEqual(1);
      expect(layout.borderCollapse).toBe('collapse');
      for (const selector of ['thead th', 'tbody td']) {
        const border = locator => locator.evaluate(element => {
          const style = getComputedStyle(element);
          return { color: style.borderBottomColor, style: style.borderBottomStyle, width: style.borderBottomWidth };
        });
        const actual = await border(table.locator(selector).first());
        expect(actual).toEqual(await border(reference.locator(`.fs-table ${selector}`).first()));
        expect(actual.style).toBe('solid');
        expect(Number.parseFloat(actual.width)).toBeGreaterThan(0);
        expect(transparent(actual.color)).toBe(false);
      }
      const backgrounds = [];
      for (const variant of ['success', 'warning', 'info']) {
        const badge = table.locator(`.fs-badge-soft-${variant}`);
        await expect(badge).toBeVisible();
        const actual = await colors(badge);
        expect(actual).toEqual(await colors(reference.locator(`.fs-badge-soft-${variant}`)));
        expect(transparent(actual.background)).toBe(false);
        expect(actual.color).not.toBe(actual.background);
        expect(await badge.evaluate(element => getComputedStyle(element).display)).toBe('inline-flex');
        backgrounds.push(actual.background);
      }
      expect(new Set(backgrounds).size).toBe(3);
    }
  } finally { await reference.close(); }
});

test('published CSS is applied in each standalone example and the next catalog shell', async ({ page }) => {
  for (const path of ['/styles/examples/projects.html', '/styles/examples/onboarding.html',
    '/styles/examples/records.html', '/styles/next/']) {
    await page.goto(path);
    await expectPublishedStylesLoaded(page);
    const button = page.locator('.fs-btn').first();
    await expect(button).toBeVisible();
    const style = await button.evaluate(element => {
      const computed = getComputedStyle(element);
      return { display: computed.display, alignItems: computed.alignItems,
        borderStyle: computed.borderTopStyle, backgroundToken: computed.getPropertyValue('--fs-btn-bg').trim() };
    });
    // Flex/grid items are blockified by layout, so inline-flex computes to flex.
    // Both still require the framework's flex alignment, border and color token.
    expect(['inline-flex', 'flex'], path).toContain(style.display);
    expect(style.alignItems, path).toBe('center');
    expect(style.borderStyle, path).toBe('solid');
    expect(style.backgroundToken, path).not.toBe('');
  }
});

for (const width of [1440, 1165, 768, 390]) {
  test(`showcase real screenshots, theme and no overflow at ${width}`, async ({ page }, info) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/styles/index.html');
    await expectPublishedStylesLoaded(page);
    await page.evaluate(() => document.fonts.ready);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/[↗→➜➔]/u);
    expect(await page.locator('.showcase-strip span').evaluateAll(elements =>
      elements.every(el => ['none', 'normal', '""'].includes(getComputedStyle(el, '::before').content))
    )).toBe(true);
    expect(await page.locator('.showcase-wordmark').first().evaluate(el => getComputedStyle(el).fontFamily)).toContain('Bebas Neue');
    expect(await page.locator('body').evaluate(el => getComputedStyle(el).fontFamily)).toContain('Google Sans');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    for (const preview of await page.locator('.showcase-mini iframe').all()) {
      await preview.scrollIntoViewIfNeeded();
      await expect(preview.contentFrame().locator('h1')).toBeVisible();
      const frame = await (await preview.elementHandle()).contentFrame();
      await expectPublishedStylesLoaded(frame);
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: info.outputPath(`showcase-B-${width}-dark.png`), fullPage: true });
    await page.getByRole('button', { name: 'Ativar tema claro' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    // Wait for nested-frame painting after the parent theme switch, not only DOM state.
    await page.locator('.showcase-preview iframe').contentFrame().locator('body').evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await page.screenshot({ path: info.outputPath(`showcase-B-${width}-light.png`), fullPage: true, animations: 'disabled' });
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  });
}
test('exact source, keyboard, form validation and reset, search and no-results', async ({ page }) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => { Object.defineProperty(navigator, 'clipboard', { value: { writeText: async value => { window.copied = value; } } }); });
  await page.goto('/styles/index.html');
  await page.getByRole('button', { name: 'Botão primário', exact: true }).click();
  await expect(page.locator('#component-feedback')).toContainText('acionado');
  await page.getByRole('textbox', { name: 'E-mail', exact: true }).fill('teste@example.com');
  await expect(page.locator('#component-email')).toHaveValue('teste@example.com');
  for (const slug of ['projects', 'onboarding', 'records']) {
    await page.locator(`[data-example="${slug}"]`).focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#example-source')).toHaveText(example(slug));
    await page.getByRole('button', { name: 'Copiar HTML' }).click();
    expect(await page.evaluate(() => window.copied)).toBe(example(slug));
    await page.getByRole('button', { name: 'Fechar código' }).click();
    await expect(page.locator(`[data-example="${slug}"]`)).toBeFocused();
  }
  await page.goto('/styles/examples/projects.html');
  await page.getByRole('searchbox', { name: 'Buscar projeto ou responsável' }).fill('Ana');
  await expect(page.locator('#project-rows tr:visible')).toHaveCount(1);
  await page.getByRole('searchbox').fill('zzzz');
  await expect(page.locator('#project-empty')).toBeVisible();
  await page.getByRole('searchbox').fill('');
  await expect(page.locator('#project-rows tr:visible')).toHaveCount(3);
  await page.goto('/styles/examples/onboarding.html');
  await page.getByRole('button', { name: 'Experimentar cadastro' }).click();
  await expect(page.locator('#name')).toBeFocused();
  await expect(page.locator('[aria-invalid=true]')).toHaveCount(2);
  await page.getByLabel('Nome completo').fill('Ana Lima');
  await page.getByLabel('E-mail (obrigatório)').fill('ana@example.com');
  await page.getByRole('button', { name: 'Experimentar cadastro' }).click();
  await expect(page.getByRole('status')).toContainText('Nenhum dado foi enviado');
  await page.getByRole('button', { name: 'Limpar' }).click();
  await expect(page.getByLabel('Nome completo')).toHaveValue('');
  await page.goto('/styles/examples/records.html');
  await page.getByRole('searchbox').fill('LÍA');
  await expect(page.locator('[data-record]:visible')).toHaveCount(1);
  await page.getByRole('button', { name: 'Ver detalhes de Lia Alves' }).click();
  await expect(page.locator('#detail')).toContainText('lia@example.com');
  await page.getByRole('searchbox').fill('zzzzz');
  await expect(page.locator('#empty')).toBeVisible();
  expect(errors).toEqual([]);
});

test('WCAG AA automated checks of homepage and complete examples', async ({ page }, info) => {
  for (const path of ['/styles/index.html', '/styles/examples/projects.html', '/styles/examples/onboarding.html', '/styles/examples/records.html']) {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    await info.attach(path.replaceAll('/', '_') + '-axe.json', { body: JSON.stringify(results, null, 2), contentType: 'application/json' });
    expect.soft(results.violations, path).toEqual([]);
    if (path === '/styles/index.html') {
      await page.getByRole('button', { name: 'Ativar tema claro' }).click();
      await expect.poll(() => page.locator('.showcase-hero .fs-btn-primary').evaluate(el => ({ color: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor }))).toEqual({ color: 'rgb(255, 255, 255)', background: 'rgb(21, 108, 67)' });
      const light = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
      await info.attach('homepage-light-axe.json', { body: JSON.stringify(light, null, 2), contentType: 'application/json' });
      expect.soft(light.violations, 'homepage light').toEqual([]);
    }
  }
});

test('next catalog keyboard focus and shell accessibility in both themes', async ({ page }, info) => {
  await page.goto('/styles/next/');
  const first = page.locator('#catalog-list a').first();
  await expect(first).toBeVisible();
  await first.focus(); await page.keyboard.press('Enter');
  await expect(page.locator('#catalog-detail h2')).toBeFocused();
  // Repeat activation of the same hash must still reach the loaded content.
  await first.focus(); await page.keyboard.press('Enter');
  await expect(page.locator('#catalog-detail h2')).toBeFocused();
  await expect(page.locator('#catalog-detail')).toHaveAttribute('aria-labelledby', 'catalog-detail-title');
  const names = await page.locator('#catalog-detail button').evaluateAll(nodes => nodes.map(node => node.getAttribute('aria-label')));
  expect(names.every(Boolean)).toBe(true); expect(new Set(names).size).toBe(names.length);
  for (const theme of ['dark', 'light']) {
    await page.locator('html').evaluate((html, theme) => html.dataset.theme = theme, theme);
    // Scope is the catalog shell; opaque sandbox previews require separate audits.
    const result = await new AxeBuilder({ page }).exclude('iframe').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
    await info.attach(`catalog-shell-${theme}-axe.json`, {body:JSON.stringify(result,null,2),contentType:'application/json'});
    expect.soft(result.violations, `catalog shell ${theme}`).toEqual([]);
  }
});
