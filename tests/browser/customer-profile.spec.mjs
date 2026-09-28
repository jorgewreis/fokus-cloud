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
        await expect(page.locator('#content-region .law-settings-link[href="/portal/fokus-law/perfil"]')).toHaveCount(0);
    }
});

test('perfil em acesso de suporte fica somente para leitura', async ({ page }) => {
    await openProfile(page, { active: true, company: 'Menezes Advocacia', reason: 'Atendimento' });
    await expect(page.locator('#profile-support-notice')).toBeVisible();
    await expect(page.getByLabel('Nome completo')).toBeDisabled();
    await expect(page.getByLabel(/Telefone/)).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Alterar senha' })).toBeDisabled();
});

const usersShellView = shellView
    .replace("{{ $initialPage ?? 'overview' }}", 'users')
    .replace("@include('portal.partials.fokus-law-profile')", profileView);

const initialCompanyUsers = [
    { id: 'VNC_ADMIN', name: 'Érica Menezes', cpf: '52998224725', email: 'erica@example.test', role: 'admin', status: 'ativo', version: 1 },
    { id: 'VNC_001', name: 'Ana Operadora', cpf: '11144477735', email: 'ana@example.test', role: 'usuario', status: 'ativo', version: 1 },
];

async function openUsers(page, viewport) {
    await page.setViewportSize(viewport);
    await page.route('**/portal/usuarios', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: usersShellView }));
    await page.route('**/api/law/shell-context', (route) => route.fulfill({ json: {
        user: { id: 'USR_ADMIN', name: 'Érica Menezes', email: 'erica@example.test' },
        company: { id: 'COM_TEST', name: 'Menezes Advocacia', role: 'admin' },
        active_company_id: 'COM_TEST', companies: [{ id: 'COM_TEST', name: 'Menezes Advocacia' }],
        subscription: null, permissions: { manage_company_users: true, manage_settings: true },
        modules: [], support_mode: null,
    } }));
    await page.route('**/api/law/units', (route) => route.fulfill({ json: { units: [], active_unit: null } }));
    await page.route('**/api/law/notifications', (route) => route.fulfill({ json: { unread_count: 0, notifications: [] } }));
    await page.route('**/api/csrf-token', (route) => route.fulfill({ json: { token: 'law-users-test-token' } }));
    let users = structuredClone(initialCompanyUsers);
    let conflictNextMutation = false;
    const mutations = [];
    await page.route('**/api/portal/users**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() === 'GET') return route.fulfill({ json: users });
        const pathParts = url.pathname.split('/');
        const id = decodeURIComponent(pathParts.at(-1) === 'restore' ? pathParts.at(-2) : pathParts.at(-1));
        const body = request.postDataJSON() || {};
        mutations.push({ method: request.method(), path: url.pathname, body });
        if (request.method() === 'POST' && url.pathname === '/api/portal/users') {
            return route.fulfill({ status: 201, json: { message: 'Convite enviado para o e-mail cadastrado.' } });
        }
        if (conflictNextMutation) {
            conflictNextMutation = false;
            return route.fulfill({ status: 409, json: { message: 'Este vínculo foi alterado por outra pessoa. Atualize a tela e tente novamente.' } });
        }
        const current = users.find((user) => user.id === id);
        if (url.pathname.endsWith('/restore')) {
            current.status = 'ativo';
            current.version += 1;
        } else if (current) {
            if (body.role) current.role = body.role;
            if (body.status) current.status = body.status;
            current.version += 1;
        }
        return route.fulfill({ json: { message: 'Vínculo atualizado.' } });
    });
    await page.goto('/portal/usuarios');
    await expect(page.getByRole('heading', { name: 'Usuários, perfis e permissões' })).toBeVisible();
    return { mutations, setConflict: () => { conflictNextMutation = true; } };
}

test('gestão de usuários integrada ao shell funciona em desktop, tablet e celular', async ({ page }) => {
    for (const viewport of [{ width: 1365, height: 900 }, { width: 768, height: 1024 }, { width: 375, height: 812 }]) {
        const { mutations } = await openUsers(page, viewport);
        await expect(page.locator('#page-items a[aria-current="page"]')).toHaveAttribute('href', '/portal/usuarios');
        await expect(page.getByRole('heading', { name: 'Perfis disponíveis' })).toBeVisible();
        await expect(page.getByText('Ana Operadora')).toBeVisible();
        await expect(page.getByText('111.***.***-35')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Transferir administração' })).toBeVisible();

        await page.getByLabel('Nome completo').fill('Pessoa Nova');
        await page.getByLabel('CPF').fill('11111111111');
        await page.getByLabel('E-mail').fill('nova@example.test');
        await page.getByRole('button', { name: 'Enviar convite' }).click();
        await expect(page.locator('#law-users-feedback')).toContainText('CPF válido');
        expect(mutations.filter((item) => item.method === 'POST')).toHaveLength(0);

        await page.getByLabel('CPF').fill('52998224725');
        await page.getByRole('button', { name: 'Enviar convite' }).click();
        await expect(page.locator('#law-users-feedback')).toContainText('Convite enviado');
        expect(mutations.some((item) => item.method === 'POST' && item.body.cpf === '52998224725')).toBe(true);

        await page.getByRole('button', { name: 'Definir perfil: Gestor' }).click();
        await expect(page.locator('#law-users-feedback')).toContainText('Vínculo atualizado');
        expect(mutations.some((item) => item.method === 'PATCH' && item.body.role === 'gestor' && item.body.version === 1)).toBe(true);

        await page.getByRole('button', { name: 'Suspender acesso' }).click();
        await expect(page.locator('#law-users-feedback')).toContainText('Vínculo atualizado');
        expect(mutations.some((item) => item.method === 'PATCH' && item.body.status === 'suspenso')).toBe(true);

        await page.getByRole('button', { name: 'Remover', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Remover acesso' });
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(page.getByRole('button', { name: 'Remover', exact: true })).toBeFocused();
        await page.getByRole('button', { name: 'Remover', exact: true }).click();
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Remover acesso' }).click();
        await expect(dialog).toBeHidden();
        expect(mutations.some((item) => item.method === 'PATCH' && item.body.status === 'removido')).toBe(true);
        await page.getByRole('button', { name: 'Restaurar acesso' }).click();
        await expect(page.locator('#law-users-feedback')).toContainText('Vínculo atualizado');
        expect(mutations.some((item) => item.method === 'POST' && item.path.endsWith('/restore'))).toBe(true);

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(overflow).toBe(false);
        expect(await page.locator('body').evaluate((element) => getComputedStyle(element).fontFamily)).toContain('Google Sans');
        if (viewport.width <= 720) {
            await page.getByRole('button', { name: 'Abrir menu' }).click();
            await expect(page.locator('#law-sidebar')).toHaveClass(/is-open/);
        }
    }
});

test('lista vazia, erro de API e conflito de versão são apresentados sem perder a página', async ({ page }) => {
    const { setConflict } = await openUsers(page, { width: 1365, height: 900 });
    setConflict();
    await page.getByRole('button', { name: 'Definir perfil: Gestor' }).click();
    await expect(page.locator('#law-users-feedback')).toContainText('alterado por outra pessoa');
    await expect(page.getByRole('heading', { name: 'Pessoas com acesso' })).toBeVisible();

    await page.unroute('**/api/portal/users**');
    await page.route('**/api/portal/users**', (route) => route.fulfill({ json: [] }));
    await page.reload();
    await expect(page.getByRole('status').filter({ hasText: 'Nenhuma pessoa está vinculada' })).toBeVisible();

    await page.unroute('**/api/portal/users**');
    await page.route('**/api/portal/users**', (route) => route.fulfill({ status: 500, json: { message: 'Falha simulada ao carregar.' } }));
    await page.reload();
    await expect(page.getByRole('status').filter({ hasText: 'Falha simulada ao carregar.' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Usuários, perfis e permissões' })).toBeVisible();
});
