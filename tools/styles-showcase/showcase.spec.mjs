import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const example = slug => readFileSync(new URL(`../../public/styles/examples/${slug}.html`, import.meta.url), 'utf8');
for (const width of [1440, 1165, 768, 390]) {
  test(`showcase real screenshots, theme and no overflow at ${width}`, async ({ page }, info) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/styles/index.html');
    await page.evaluate(() => document.fonts.ready);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    expect(await page.locator('.showcase-wordmark').first().evaluate(el => getComputedStyle(el).fontFamily)).toContain('Bebas Neue');
    expect(await page.locator('body').evaluate(el => getComputedStyle(el).fontFamily)).toContain('Google Sans');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    for (const preview of await page.locator('.showcase-mini iframe').all()) {
      await preview.scrollIntoViewIfNeeded();
      await expect(preview.contentFrame().locator('h1')).toBeVisible();
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: info.outputPath(`showcase-B-${width}-dark.png`), fullPage: true });
    await page.getByRole('button', { name: 'Ativar tema claro' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.screenshot({ path: info.outputPath(`showcase-B-${width}-light.png`), fullPage: true });
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  });
}
test('exact source, keyboard, form validation and reset, search and no-results', async ({ page }) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => { Object.defineProperty(navigator, 'clipboard', { value: { writeText: async value => { window.copied = value; } } }); });
  await page.goto('/styles/index.html');
  for (const slug of ['projects', 'onboarding', 'records']) {
    await page.locator(`[data-example="${slug}"]`).focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#example-source')).toHaveText(example(slug));
    await page.getByRole('button', { name: 'Copiar HTML' }).click();
    expect(await page.evaluate(() => window.copied)).toBe(example(slug));
    await page.getByRole('button', { name: 'Fechar código' }).click();
    await expect(page.locator(`[data-example="${slug}"]`)).toBeFocused();
  }
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
