import { test, expect } from '@playwright/test';

// These tests exercise portable examples, not the authenticated Cloud/Law apps.
// No external requests or real data are needed.
async function openExample(page, path, width, theme) {
  const failures = [];
  await page.setViewportSize({ width, height: 900 });
  await page.addInitScript(theme => localStorage.setItem('fokus-styles-theme', theme), theme);
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.hostname === '127.0.0.1' && url.port === '8874') return route.continue();
    return route.abort();
  });
  page.on('pageerror', error => failures.push(`pageerror: ${error.message}`));
  page.on('console', message => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('response', response => {
    const url = new URL(response.url());
    if (url.hostname === '127.0.0.1' && response.status() >= 400 && url.pathname !== '/favicon.ico') {
      failures.push(`HTTP ${response.status()}: ${url.pathname}`);
    }
  });
  await page.goto(path);
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
  return failures;
}

async function evidence(page, info, name, failures) {
  const screenshot = await page.screenshot({ fullPage: true, animations: 'disabled' });
  await info.attach(name, { body: screenshot, contentType: 'image/png' });
  expect(failures).toEqual([]);
}
async function captureState(page, info, name) {
  await info.attach(name, { body: await page.screenshot({fullPage:true, animations:'disabled'}), contentType:'image/png' });
}

async function expectFitsViewport(page, element) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
  await expect.poll(() => element.evaluate(node => {
    const rect = node.getBoundingClientRect();
    return Math.max(0, -rect.left, rect.right - innerWidth);
  })).toBeLessThanOrEqual(1);
}

for (const width of [390, 1280]) for (const theme of ['light', 'dark']) {
  test(`REI-54 Notificações ${width}px ${theme}`, async ({ page }, info) => {
    const failures = await openExample(page, '/styles/examples/law-notifications.html', width, theme);
    const trigger = page.getByRole('button', { name: 'Notificações, 2 não lidas' });
    await trigger.focus(); await page.keyboard.press('Enter');
    const panel = page.getByRole('region', { name: 'Notificações' });
    await expect(panel).toBeVisible();
    await expectFitsViewport(page, panel);
    await captureState(page, info, `notifications-open-${width}-${theme}`);
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden(); await expect(trigger).toBeFocused();
    await page.getByRole('button', { name: 'Mostrar lista vazia' }).click();
    await page.getByRole('button', { name: 'Notificações', exact: true }).click();
    await expect(panel).toContainText('Nenhuma notificação por enquanto.');
    await evidence(page, info, `notifications-empty-${width}-${theme}`, failures);
  });

  test(`REI-54 Preferências ${width}px ${theme}`, async ({ page }, info) => {
    const failures = await openExample(page, '/styles/examples/law-preferences.html', width, theme);
    const remember = page.getByLabel('Lembrar seção'), reduce = page.getByLabel('Reduzir movimento');
    await page.locator('label[for="remember-section"]').click();
    await expect(remember).toBeChecked();
    await reduce.focus(); await page.keyboard.press('Space');
    await expect(reduce).toBeChecked();
    await page.getByLabel('Seção fictícia').selectOption('tarefas');
    await page.reload();
    await expect(remember).toBeChecked(); await expect(reduce).toBeChecked();
    await expect(page.getByLabel('Seção fictícia')).toHaveValue('tarefas');
    await expect(page.locator('#preview')).toHaveAttribute('data-reduced', 'true');
    await page.getByRole('button', {name:'Abrir prévia de movimento'}).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#preview-panel')).toBeVisible();
    await expectFitsViewport(page, page.locator('#preview-panel'));
    await evidence(page, info, `preferences-${width}-${theme}`, failures);
  });

  test(`REI-54 Offcanvas ${width}px ${theme}`, async ({ page }, info) => {
    const failures = await openExample(page, '/styles/examples/cloud-offcanvas.html', width, theme);
    const trigger = page.getByRole('button', { name: 'Abrir painel de edição' });
    await trigger.focus(); await page.keyboard.press('Enter');
    const drawer = page.getByRole('dialog', { name: 'Editar módulo fictício' });
    await expect(drawer).toBeVisible();
    await expectFitsViewport(page, drawer);
    await captureState(page, info, `offcanvas-open-${width}-${theme}`);
    const name = drawer.getByLabel('Nome da funcionalidade');
    await expect(name).toHaveJSProperty('required', true);
    await name.fill('Exemplo');
    await drawer.getByRole('button', { name: 'Cancelar' }).click();
    await expect(drawer).toBeHidden(); await expect(trigger).toBeFocused();
    await trigger.click(); await expect(name).toHaveValue('Exemplo');
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden(); await expect(trigger).toBeFocused();
    await evidence(page, info, `offcanvas-${width}-${theme}`, failures);
  });

  test(`REI-54 DataTable ${width}px ${theme}`, async ({ page }, info) => {
    const failures = await openExample(page, '/styles/examples/cloud-datatable.html', width, theme);
    const table = page.getByRole('table', { name: 'Módulos fictícios' });
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await page.getByRole('button', { name: 'Simular carregamento' }).click();
    await expect(page.locator('[data-fs-datatable-loading]')).toBeVisible();
    await page.getByRole('button', { name: 'Mostrar lista vazia' }).click();
    await expect(page.locator('[data-fs-datatable-empty]')).toBeVisible();
    await page.getByRole('button', { name: 'Simular erro' }).click();
    await expect(page.locator('[data-fs-datatable-error]')).toBeVisible();
    await page.getByRole('button', { name: 'Mostrar registros' }).focus();
    await page.keyboard.press('Enter');
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await expect(page.locator('[data-fs-datatable-error]')).toBeHidden();
    const region = page.getByRole('region', { name: 'Tabela de módulos fictícios' });
    await region.focus(); await expect(region).toBeFocused();
    await evidence(page, info, `datatable-${width}-${theme}`, failures);
  });

  test(`REI-54 Modal ${width}px ${theme}`, async ({ page }, info) => {
    const failures = await openExample(page, '/styles/examples/confirmation-modal.html', width, theme);
    const trigger = page.getByRole('button', { name: 'Abrir confirmação' });
    const dialog = page.getByRole('dialog', { name: 'Confirmar ação fictícia' });
    await trigger.focus(); await page.keyboard.press('Enter');
    await expect(dialog).toBeVisible();
    await expectFitsViewport(page, dialog.locator('.fs-modal-dialog'));
    await captureState(page, info, `modal-open-${width}-${theme}`);
    const reason = dialog.getByLabel('Motivo obrigatório');
    await expect(reason).toHaveValue('');
    expect(await reason.evaluate(element => element.validity.valid)).toBe(false);
    await reason.selectOption('revisao');
    await dialog.getByRole('button', { name: 'Confirmar exemplo' }).click();
    await expect(dialog).toBeHidden(); await expect(trigger).toBeFocused();
    await expect(page.locator('#confirmation-status')).toContainText('Nenhuma informação foi enviada');
    await trigger.click(); await expect(dialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden(); await expect(trigger).toBeFocused();
    await evidence(page, info, `modal-${width}-${theme}`, failures);
  });

  test(`REI-54 Select ${width}px ${theme}`, async ({ page }, info) => {
    const failures = await openExample(page, '/styles/examples/law-select-pattern.html', width, theme);
    const toggle = page.getByRole('button', { name: 'Setor fictício' });
    await expect(toggle).toHaveAccessibleDescription('A seleção não é enviada ou salva.');
    const menuId = await toggle.getAttribute('aria-controls');
    expect(menuId).toBeTruthy();
    const menu = page.locator(`[id="${menuId}"]`);
    // The listbox must name its actual toggle even if page code assigns an ID.
    await expect(menu).toHaveAttribute('aria-labelledby', await toggle.getAttribute('id'));
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    const first = menu.getByRole('option', { name: 'Administrativo' });
    await expect(first).toBeFocused();
    await expect(menu).toBeVisible();
    await expectFitsViewport(page, menu);
    await captureState(page, info, `select-menu-open-${width}-${theme}`);
    await expect(menu.getByRole('option', { name: 'Indisponível' })).toHaveAttribute('aria-disabled', 'true');
    await menu.getByRole('option', { name: 'Atendimento' }).click();
    await expect(page.locator('#sector')).toHaveValue('atendimento');
    await expect(page.locator('#sector-status')).toContainText('Atendimento');
    await expect(toggle).toBeFocused();
    const multiple = page.getByLabel('Áreas fictícias');
    await expect(multiple).toHaveJSProperty('multiple', true);
    await multiple.selectOption(['arquivo', 'apoio']);
    await expect(multiple).toHaveValues(['arquivo', 'apoio']);
    const trigger = page.getByRole('button', { name: 'Abrir diálogo de exemplo' });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Categoria fictícia' });
    await expect(dialog.getByLabel('Categoria')).toBeVisible();
    await expectFitsViewport(page, dialog.locator('.fs-modal-dialog'));
    await captureState(page, info, `select-modal-open-${width}-${theme}`);
    await dialog.getByRole('button', { name: 'Fechar' }).click();
    await expect(dialog).toBeHidden(); await expect(trigger).toBeFocused();
    await evidence(page, info, `select-${width}-${theme}`, failures);
  });
}
