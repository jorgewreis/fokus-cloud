import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const shellView = await readFile(new URL('../../resources/views/portal/fokus-law.blade.php', import.meta.url), 'utf8');
const profileView = await readFile(new URL('../../resources/views/portal/partials/fokus-law-profile.blade.php', import.meta.url), 'utf8');
const renderedShell = shellView
    .replace("{{ $initialPage ?? 'overview' }}", 'profile')
    .replace("@include('portal.partials.fokus-law-profile')", profileView);

const profile = {
    user: { id: 'USR_VISUAL', name: 'Érica Menezes', email: 'erica@example.test', phone: '71987654321', cpf: '52998224725', email_verified: true },
    companies: [{ id: 'CMP_VISUAL', name: 'Menezes Advocacia' }],
    active_company_id: 'CMP_VISUAL',
    support_mode: null,
};

async function openProfile(page, supportMode = null) {
    await page.route('**/portal/fokus-law/perfil', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: renderedShell }));
    await page.route('**/api/law/shell-context', (route) => route.fulfill({ json: {
        user: { id: profile.user.id, name: profile.user.name, email: profile.user.email },
        company: { id: 'CMP_VISUAL', name: 'Menezes Advocacia', role: 'admin' },
        active_company_id: 'CMP_VISUAL', companies: profile.companies,
        subscription: null, permissions: { manage_company_users: true, manage_settings: true },
        modules: [], support_mode: supportMode,
    } }));
    await page.route('**/api/auth/me', (route) => route.fulfill({ json: { ...profile, support_mode: supportMode } }));
    await page.route('**/api/csrf-token', (route) => route.fulfill({ json: { token: 'profile-test-token' } }));
    await page.route('**/api/auth/profile', async (route) => {
        const body = route.request().postDataJSON();
        await route.fulfill({ json: { message: 'Dados atualizados.', user: { ...profile.user, ...body, phone: '71987654321' } } });
    });
    await page.goto('/portal/fokus-law/perfil');
    await expect(page.getByRole('heading', { name: 'Dados pessoais' })).toBeVisible();
}

test('perfil do cliente adapta as três seções a desktop, tablet e celular', async ({ page }) => {
    for (const viewport of [{ width: 1365, height: 900 }, { width: 768, height: 1024 }, { width: 375, height: 812 }]) {
        await page.setViewportSize(viewport);
        await openProfile(page);
        await expect(page.getByLabel('Nome completo')).toHaveValue('Érica Menezes');
        await expect(page.getByLabel(/Telefone/)).toHaveValue('(71) 98765-4321');
        await expect(page.getByLabel('CPF')).toHaveAttribute('readonly', '');
        await expect(page.getByRole('heading', { name: 'E-mail' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Segurança' })).toBeVisible();
        await expect(page.locator('#law-sidebar')).toBeVisible();
        await expect(page.locator('.law-topbar')).toBeVisible();
        await expect(page.locator('.law-rail')).toBeVisible();
        await expect(page.locator('#page-items a[aria-current="page"]')).toHaveAttribute('href', '/portal/fokus-law/perfil');
        expect(await page.locator('.law-shell').evaluate((element) => getComputedStyle(element).display)).toBe(viewport.width <= 720 ? 'block' : 'grid');
        expect(await page.locator('body').evaluate((element) => getComputedStyle(element).fontFamily)).toContain('Google Sans');
        const personalFieldWidths = await page.locator('#profile-name').evaluate((element) => [element.getBoundingClientRect().width, element.parentElement.getBoundingClientRect().width]);
        expect(personalFieldWidths[0]).toBeGreaterThan(personalFieldWidths[1] * 0.8);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(overflow).toBe(false);
        if (viewport.width <= 720) await page.getByRole('button', { name: 'Abrir menu' }).click();
        await page.locator('#rail-items [data-group="settings"]').click();
        await expect(page.locator('#content-region h2')).toHaveText('Configurações');
        await expect(page.locator('.law-settings-link[href="/portal/fokus-law/perfil"]')).toBeVisible();
    }
});

test('perfil em acesso de suporte fica somente para leitura', async ({ page }) => {
    await openProfile(page, { active: true, company: 'Menezes Advocacia', reason: 'Atendimento' });
    await expect(page.locator('#profile-support-notice')).toBeVisible();
    await expect(page.getByLabel('Nome completo')).toBeDisabled();
    await expect(page.getByLabel(/Telefone/)).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Alterar senha' })).toBeDisabled();
});
