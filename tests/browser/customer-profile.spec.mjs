import { test, expect } from '@playwright/test';

const profile = {
    user: { id: 'USR_VISUAL', name: 'Érica Menezes', email: 'erica@example.test', phone: '71987654321', cpf: '52998224725', email_verified: true },
    companies: [{ id: 'CMP_VISUAL', name: 'Menezes Advocacia' }],
    active_company_id: 'CMP_VISUAL',
    support_mode: null,
};

async function openProfile(page, supportMode = null) {
    await page.route('**/api/auth/me', (route) => route.fulfill({ json: { ...profile, support_mode: supportMode } }));
    await page.route('**/api/csrf-token', (route) => route.fulfill({ json: { token: 'profile-test-token' } }));
    await page.route('**/api/auth/profile', async (route) => {
        const body = route.request().postDataJSON();
        await route.fulfill({ json: { message: 'Dados atualizados.', user: { ...profile.user, ...body, phone: '71987654321' } } });
    });
    await page.goto('/portal/profile.html');
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
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(overflow).toBe(false);
    }
});

test('perfil em acesso de suporte fica somente para leitura', async ({ page }) => {
    await openProfile(page, { active: true, company: 'Menezes Advocacia', reason: 'Atendimento' });
    await expect(page.getByText('Seu perfil está somente para leitura.')).toBeVisible();
    await expect(page.getByLabel('Nome completo')).toBeDisabled();
    await expect(page.getByLabel(/Telefone/)).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Alterar senha' })).toBeDisabled();
});
