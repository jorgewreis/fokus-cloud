import { test, expect } from '@playwright/test';

// Local fixture, real 3.0.0 bundle and Law adapter; no authenticated product data.
const fixture = 'http://127.0.0.1:8765/law-select.html';

test.beforeEach(async ({ page }) => {
  await page.goto(fixture);
  await expect.poll(() => page.evaluate(() => Boolean(
    window.FokusStyles?.Select?.getInstance(document.querySelector('#law-simple'))
  ))).toBe(true);
});

for (const mode of ['multiple', 'size', 'modal']) {
  test(`Law Select: ${mode} usa controle nativo e retorna ao componente`, async ({ page }, info) => {
    const native = page.locator('#law-simple');
    const label = page.locator('label.fs-form-label').filter({ hasText: 'Setor fictício' });
    const toggle = page.getByRole('button', { name: 'Setor fictício' });
    await expect(toggle).toHaveAccessibleDescription('A seleção permanece apenas nesta página.');
    await expect(label).toHaveAttribute('for', await toggle.getAttribute('id'));
    await toggle.focus();
    await page.keyboard.press('ArrowDown');
    const menuId = await toggle.getAttribute('aria-controls');
    expect(menuId).toBeTruthy();
    const menu = page.locator(`[id="${menuId}"]`);
    await expect(menu).toHaveAttribute('aria-labelledby', await toggle.getAttribute('id'));
    await expect(menu.getByRole('option', { name: 'Administrativo' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();

    if (mode === 'modal') {
      await page.getByRole('button', { name: 'Abrir modal de exemplo' }).click();
      await expect(page.getByRole('dialog', { name: 'Escolher categoria fictícia' })).toBeVisible();
      // Move the labeled field as a whole; the added select is observed by the adapter.
      await page.evaluate(() => {
        const field = document.querySelector('#law-simple').closest('.demo-field');
        field.dataset.testOriginalParent = 'true';
        document.querySelector('#law-demo-modal .fs-modal-body').append(field);
      });
    } else {
      await native.evaluate((select, kind) => {
        if (kind === 'multiple') select.multiple = true;
        else select.size = 3;
      }, mode);
    }
    await expect.poll(() => page.evaluate(() => window.FokusStyles.Select.getInstance(document.querySelector('#law-simple')) == null)).toBe(true);
    await expect(native).toBeVisible();
    await expect(native).toHaveAccessibleName('Setor fictício');
    await expect(native).toHaveAccessibleDescription('A seleção permanece apenas nesta página.');
    await expect(label).toHaveAttribute('for', 'law-simple');
    if (mode !== 'modal') await expect(native).toBeFocused();
    await info.attach(`law-select-native-${mode}`, { body: await page.screenshot({ fullPage: true, animations: 'disabled' }), contentType: 'image/png' });

    if (mode === 'modal') {
      await page.evaluate(() => {
        const field = document.querySelector('[data-test-original-parent]');
        document.querySelector('[aria-labelledby="simple-title"]').append(field);
        window.FokusLawSelect.refresh(field.querySelector('select'));
      });
      await page.getByRole('dialog', { name: 'Escolher categoria fictícia' }).getByRole('button', { name: 'Fechar' }).last().click();
    } else {
      await native.evaluate((select, kind) => {
        if (kind === 'multiple') select.multiple = false;
        else select.size = 1;
      }, mode);
    }
    await expect.poll(() => page.evaluate(() => Boolean(window.FokusStyles.Select.getInstance(document.querySelector('#law-simple'))))).toBe(true);
    const restored = page.getByRole('button', { name: 'Setor fictício' });
    await expect(restored).toBeVisible();
    await expect(restored).toHaveAccessibleDescription('A seleção permanece apenas nesta página.');
    await expect(label).toHaveAttribute('for', await restored.getAttribute('id'));
    await restored.focus();
    await page.keyboard.press('ArrowDown');
    const restoredMenuId = await restored.getAttribute('aria-controls');
    const restoredMenu = page.locator(`[id="${restoredMenuId}"]`);
    await expect(restoredMenu).toHaveAttribute('aria-labelledby', await restored.getAttribute('id'));
    await expect(restoredMenu.getByRole('option', { name: 'Atendimento' })).toBeVisible();
    await restoredMenu.getByRole('option', { name: 'Atendimento' }).click();
    await expect(native).toHaveValue('atendimento');
    await expect(restored).toBeFocused();
  });
}
