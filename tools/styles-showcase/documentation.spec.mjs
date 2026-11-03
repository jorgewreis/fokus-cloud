import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
const guides = ['layout', 'forms', 'components', 'helpers', 'utilities'];
const catalog = JSON.parse(readFileSync(new URL('../../public/styles/next/catalog.json', import.meta.url), 'utf8'));
const runtimePath = '/styles/assets/fokus-2.7.0.min.js';

async function theme(page, value) {
  await page.addInitScript(value => localStorage.setItem('fokus-styles-theme', value), value);
}
async function navigation(page) {
  const nav = page.getByRole('navigation', { name: 'Navegação principal', exact: true });
  await expect(nav).toHaveCount(1);
  expect(await nav.locator('a').evaluateAll(nodes => nodes.map(a => a.getAttribute('href')))).toEqual(['/', '/docs', 'https://github.com/jorgewreis/fokus-styles']);
  await expect(nav.locator('a[href="/docs"]')).toHaveAttribute('aria-current', /^(page|true)$/);
}
async function screenshot(page, info, name) {
  const path = info.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true, animations: 'disabled' });
  await info.attach(name, { path, contentType: 'image/png' });
}

// HTTP route checks use the same Laravel route map as production, via serve.py.
test('hub and five guide routes, contract destinations and official tabs runtime are served intact', async ({ request }) => {
  for (const path of ['/docs', ...guides.map(slug => `/${slug}`), '/styles/next/']) {
    const response = await request.get(path);
    expect(response.status(), path).toBe(200);
    expect(response.headers()['content-type'], path).toContain('text/html');
  }
  const response = await request.get('/styles/next/catalog.json');
  expect(response.ok()).toBe(true);
  expect((await response.json()).contracts.map(c => c.id).sort()).toEqual(catalog.contracts.map(c => c.id).sort());
  const runtime = await request.get(runtimePath);
  expect(runtime.ok()).toBe(true);
  const hash = data => createHash('sha256').update(data).digest('hex');
  expect(hash(await runtime.body())).toBe(hash(readFileSync(new URL(`../../public${runtimePath}`, import.meta.url))));
});

for (const width of [1440, 390]) for (const appearance of ['dark', 'light']) {
  test(`documentation hub, navigation and new-shell accessibility: ${width}px ${appearance}`, async ({ page }, info) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width, height: 900 });
    await theme(page, appearance);
    await page.goto('/docs');
    await expect(page.locator('html')).toHaveAttribute('data-theme', appearance);
    await navigation(page);
    await expect(page.locator('main#main h1')).toHaveCount(1);
    await expect(page.locator('#guias .styles-doc-guide')).toHaveCount(5);
    const links = page.locator('.styles-doc-contract-group a');
    await expect(links).toHaveCount(56);
    expect(await links.evaluateAll(nodes => nodes.map(a => a.getAttribute('href')).sort())).toEqual(catalog.contracts.map(c => `/styles/next/#${c.id}`).sort());
    await expect(page.locator('#guias')).toContainText('npm 2.7.0');
    await expect(page.locator('#componentes .styles-doc-status')).toHaveText('Em desenvolvimento · next');
    await expect(page.locator('#componentes .styles-doc-version')).toContainText('separado do npm 2.7.0');
    await expect(page.locator('#versoes article')).toHaveCount(2);
    await expect(page.locator('#versoes article').last()).toContainText('não representa uma versão estável');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Hub must not overflow horizontally').toBe(true);
    const skip = page.locator('a[href="#main"]').first();
    await skip.focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#main')).toBeFocused();
    const hubAudit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    await info.attach(`hub-${appearance}-${width}-axe.json`, { body: JSON.stringify(hubAudit, null, 2), contentType: 'application/json' });
    expect.soft(hubAudit.violations, 'New documentation hub WCAG checks').toEqual([]);
    await screenshot(page, info, `documentation-hub-${appearance}-${width}`);

    // Exercise actual guide links and return navigation rather than only hrefs.
    for (const slug of guides) {
      await page.locator(`#guias .styles-doc-guide[href="/${slug}"]`).click();
      await expect(page).toHaveURL(new RegExp(`/${slug}$`));
      await expect(page.locator('html')).toHaveAttribute('data-theme', appearance);
      await navigation(page);
      await expect(page.locator('main#main h1')).toBeVisible();
      await expect(page.locator(`.styles-sidebar-page[href="/${slug}"]`)).toHaveAttribute('aria-current', 'page');
      await expect(page.locator('.styles-doc-version')).toContainText('npm 2.7.0');
      await expect(page.locator('.styles-doc-version a')).toHaveAttribute('href', '/docs#versoes');
      // Scope to new navigation/version structure. Legacy demo contrast and
      // legacy demo markup are intentionally not a new release gate.
      const audit = await new AxeBuilder({ page })
        .include('.styles-topbar').include('.styles-doc-breadcrumb')
        .include('.styles-doc-version').include('.styles-doc-sidebar-home')
        .include('.styles-doc-sidebar-more').include('.styles-doc-skip')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
      await info.attach(`${slug}-${appearance}-${width}-shell-axe.json`, { body: JSON.stringify(audit, null, 2), contentType: 'application/json' });
      expect.soft(audit.violations, `${slug}: new navigation and version notice`).toEqual([]);
      await screenshot(page, info, `guide-${slug}-${appearance}-${width}`);
      await page.locator('.styles-doc-sidebar-home').click();
      await expect(page).toHaveURL(/\/docs$/);
    }
    await page.locator('.styles-doc-contract-group a[href="/styles/next/#tabs"]').click();
    await expect(page).toHaveURL(/\/styles\/next\/#tabs$/);
    await expect(page.locator('#catalog-detail h2')).toContainText(/Tabs/i);
    await page.goto('/docs');
    const toggle = page.locator('[data-theme-toggle]');
    await expect(toggle).toHaveAttribute('aria-pressed', String(appearance === 'dark'));
    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', appearance === 'dark' ? 'light' : 'dark');
  });

  test(`guide laboratories: tabs, helpers, utilities and form reset at ${width}px ${appearance}`, async ({ page }, info) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width, height: 900 }); await theme(page, appearance);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async value => { window.copied = value; } } }));
    await page.goto('/components');
    const tabs = page.locator('[data-fs="tabs"] button');
    await expect(tabs).toHaveCount(3);
    const selected = async index => {
      for (let i = 0; i < 3; i++) {
        const tab = tabs.nth(i);
        await expect(tab).toHaveAttribute('role', 'tab');
        await expect(tab).toHaveAttribute('aria-selected', String(i === index));
        await expect(tab).toHaveAttribute('tabindex', i === index ? '0' : '-1');
        const target = await tab.getAttribute('data-fs-target');
        await expect(tab).toHaveAttribute('aria-controls', target.slice(1));
        await expect(page.locator(target)).toHaveAttribute('aria-labelledby', await tab.getAttribute('id'));
        if (i === index) await expect(page.locator(target)).toBeVisible();
        else await expect(page.locator(target)).toBeHidden();
      }
    };
    await selected(0); await tabs.nth(1).click(); await selected(1);
    for (const [key, index] of [['ArrowRight', 2], ['ArrowRight', 0], ['ArrowLeft', 2], ['Home', 0], ['End', 2]]) {
      await page.keyboard.press(key); await selected(index); await expect(tabs.nth(index)).toBeFocused();
    }
    await tabs.nth(0).focus(); await page.keyboard.press('Enter'); await selected(0);
    await tabs.nth(1).focus(); await page.keyboard.press('Space'); await selected(1);
    await screenshot(page, info, `components-tabs-${appearance}-${width}`);

    await page.goto('/helpers');
    const stage = page.locator('[data-helpers-stage]'); const gaps = [];
    for (const gap of ['1', '4', '1']) {
      await page.locator(`[data-helpers-gap="${gap}"]`).click();
      expect(await stage.evaluate(el => [...el.classList].filter(c => c.startsWith('fs-stack-gap-')))).toEqual([`fs-stack-gap-${gap}`]);
      await expect(stage.locator('code')).toContainText(`fs-stack-gap-${gap}`);
      for (const value of ['1', '4']) await expect(page.locator(`[data-helpers-gap="${value}"]`)).toHaveAttribute('aria-pressed', String(value === gap));
      gaps.push(await stage.evaluate(el => parseFloat(getComputedStyle(el).rowGap)));
    }
    expect(gaps[1]).toBeGreaterThan(gaps[0]); expect(gaps[2]).toBe(gaps[0]);

    await page.goto('/utilities');
    const border = page.locator('.styles-utility-surface.fs-u-border-1.fs-u-border-solid');
    await expect(border).toHaveCSS('border-top-style', 'solid');
    expect(await border.evaluate(el => parseFloat(getComputedStyle(el).borderTopWidth))).toBeGreaterThan(0);
    const card = page.locator('[data-utilities-card]'), backgrounds = [];
    for (const [state, text] of [['success', 'Concluído'], ['warning', 'Em atenção'], ['danger', 'Com erro']]) {
      await page.locator(`[data-utilities-state="${state}"]`).click();
      await expect(card).toHaveClass(new RegExp(`fs-u-bg-${state}-subtle`));
      await expect(card).toHaveClass(new RegExp(`fs-u-text-${state}-emphasis`));
      const status = card.locator('[data-utilities-status]');
      await expect(status).toHaveText(text); await expect(status).toHaveClass(new RegExp(`fs-badge-soft-${state}`));
      await expect(card.locator('..').locator('code')).toHaveText(await card.evaluate(el => el.outerHTML));
      for (const value of ['success', 'warning', 'danger']) await expect(page.locator(`[data-utilities-state="${value}"]`)).toHaveAttribute('aria-pressed', String(value === state));
      backgrounds.push(await card.evaluate(el => getComputedStyle(el).backgroundColor));
    }
    expect(new Set(backgrounds).size).toBe(3);
    await screenshot(page, info, `utilities-danger-${appearance}-${width}`);

    await page.goto('/forms');
    await page.locator('[data-forms-demo="error"]').click();
    const email = page.locator('#lab-email'), feedback = page.locator('#lab-email-error');
    await expect(email).toBeFocused(); await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('.fs-input-group.is-invalid > #lab-email')).toHaveCount(1);
    await expect(page.locator('.fs-input-group.is-invalid + #lab-email-error')).toBeVisible();
    await expect(feedback).toHaveText('Informe um email válido.');
    await page.locator('[data-copy-target="forms-lab-code"]').click();
    const snippet = await page.locator('#forms-lab-code code').textContent();
    await expect.poll(() => page.evaluate(() => window.copied)).toBe(snippet);
    expect(await page.evaluate(html => {
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const field = doc.querySelector('#example-email'), message = doc.querySelector('#example-email-error');
      return Boolean(field && message && !message.hidden && field.getAttribute('aria-describedby') === message.id && field.closest('.fs-input-group.is-invalid')?.nextElementSibling === message);
    }, snippet)).toBe(true);
    const isolated = await page.context().newPage();
    try {
      const css = new URL('/styles/assets/fokus-2.7.0.min.css', page.url()).href;
      await isolated.setContent(`<!doctype html><html lang="pt-BR" data-theme="${appearance}"><head><link rel="stylesheet" href="${css}"></head><body>${snippet}</body></html>`);
      await expect(isolated.locator('#example-email-error')).toBeVisible();
      await expect(isolated.locator('#example-email-error')).toHaveCSS('display', 'block');
    } finally { await isolated.close(); }
    await screenshot(page, info, `forms-visible-error-${appearance}-${width}`);
    await page.locator('[data-forms-demo="success"]').click();
    await page.clock.install({ time: new Date('2026-11-03T12:00:00Z') });
    await page.clock.pauseAt(new Date('2026-11-03T12:00:01Z'));
    await page.locator('[data-forms-submit]').click();
    await expect(page.locator('[data-forms-form]')).toHaveAttribute('aria-busy', 'true');
    await page.locator('[data-forms-reset]').first().click();
    // Deliberately cross the original 650ms completion deadline after reset.
    await page.clock.runFor(750);
    await expect(page.locator('[data-forms-success]')).toBeHidden();
    await expect(page.locator('[data-forms-status]')).toHaveText('Demonstração restaurada.');
    await expect(page.locator('[data-forms-submit]')).toBeEnabled();
    await expect(page.locator('[data-forms-submit]')).toHaveText('Criar cadastro');
    await expect(page.locator('[data-forms-form]')).not.toHaveAttribute('aria-busy', 'true');
    await expect(email).toHaveValue(''); await expect(feedback).toBeHidden();
    expect(errors).toEqual([]);
  });
}
