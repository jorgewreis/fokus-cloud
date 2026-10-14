import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const template = await readFile(new URL('../../resources/views/portal/fokus-lead.blade.php', import.meta.url), 'utf8');
const dashboard = template.replace("{{ $initialPage ?? 'dashboard' }}", 'dashboard');
const profile = template.replace("{{ $initialPage ?? 'dashboard' }}", 'profile');
const context = {
  user: { id: 'USR_LEAD', name: 'Ana Pessoa', email: 'ana@example.test' },
  company: { id: 'CMP_LEAD', name: 'Empresa Lead', legal_name: 'Empresa Lead Ltda.', role: 'admin' },
  active_company_id: 'CMP_LEAD', subscription: { label: 'Fokus Lead · Essencial', status: 'lead' },
  companies: [{ id: 'CMP_LEAD', name: 'Empresa Lead', role: 'admin' }, { id: 'CMP_SECOND', name: 'Outra Empresa', role: 'usuario' }],
  modules: [{ id: 'MOD_PEOPLE', code: 'pessoas', family: 'pessoas', name: 'Pessoas' }],
};

async function installMocks(page) {
  await page.route('**/api/csrf-token', (route) => route.fulfill({ json: { token: 'lead-visual-token' } }));
  await page.route('**/api/lead/shell-context', (route) => route.fulfill({ json: context }));
  await page.route('**/api/auth/me', (route) => route.fulfill({ json: { user: { ...context.user, phone: '71987654321', cpf: '52998224725', email_verified: true }, support_mode: null } }));
  await page.route('**/api/auth/select-company', (route) => route.fulfill({ json: { active_company_id: 'CMP_SECOND' } }));
  await page.route('**/api/auth/logout', (route) => route.fulfill({ json: { message: 'Sessão encerrada.' } }));
}

test('dashboard Lead usa contexto real sem indicadores simulados e troca empresa elegível', async ({ page }) => {
  await installMocks(page);
  await page.route('**/portal/fokus-lead', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: dashboard }));
  await page.goto('/portal/fokus-lead');
  for (const viewport of [{ width: 1366, height: 900 }, { width: 768, height: 1024 }, { width: 390, height: 844 }]) {
    await page.setViewportSize(viewport);
    await expect(page.getByRole('heading', { name: 'Bem-vindo ao Fokus Lead' })).toBeVisible();
    await expect(page.locator('#lead-dashboard-company')).toHaveText('Empresa Lead');
    await expect(page.locator('#lead-dashboard-subscription')).toHaveText('Fokus Lead · Essencial');
    await expect(page.getByText(/clientes cadastrados|oportunidades abertas|atividades recentes/i)).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  }
  await page.setViewportSize({ width: 1366, height: 900 });
  await expect(page.locator('#lead-dashboard-modules')).toBeVisible();
  await expect(page.locator('#lead-module-navigation').getByRole('button', { name: 'Pessoas' })).toBeVisible();
  await page.locator('#lead-module-navigation').getByRole('button', { name: 'Pessoas' }).click();
  await expect(page.getByRole('heading', { name: 'Pessoas', exact: true })).toBeVisible();
  await expect(page.locator('#lead-module-plan')).toHaveText('Fokus Lead · Essencial');
  await page.locator('#lead-module-back').click();
  await page.locator('#lead-top-company-button').click();
  await expect(page.getByRole('option', { name: 'Outra Empresa' })).toBeVisible();
  await page.getByRole('option', { name: 'Outra Empresa' }).click();
  await expect(page).toHaveURL(/\/portal\/fokus-lead$/);
  const palette = await page.locator('body').evaluate((element) => getComputedStyle(element).getPropertyValue('--lead-terracotta').trim());
  expect(palette).toBe('#c96f4a');
  await page.locator('#lead-logout').click();
  await expect(page).toHaveURL(/\?acesso=cliente$/);
});

test('perfil Lead mantém formulários compartilhados e navegação móvel com teclado', async ({ page }) => {
  await installMocks(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route('**/portal/fokus-lead/perfil', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: profile }));
  await page.goto('/portal/fokus-lead/perfil');
  await expect(page.getByRole('heading', { name: 'Meu perfil' })).toBeVisible();
  await expect(page.getByLabel('Nome completo')).toHaveValue('Ana Pessoa');
  await expect(page.getByRole('heading', { name: 'Segurança' })).toBeVisible();
  const menu = page.getByRole('button', { name: 'Abrir menu' });
  await menu.focus(); await page.keyboard.press('Enter');
  await expect(page.locator('#lead-sidebar')).toHaveClass(/is-open/);
  await expect(page.locator('#lead-sidebar nav a').first()).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.locator('#lead-menu-button')).toBeFocused();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBe(false);
});
