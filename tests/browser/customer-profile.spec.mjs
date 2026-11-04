import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';

const selectFokusOption = async (page, label, option) => {
    await page.getByRole('button', { name: label, exact: true }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
};

const shellTemplate = await readFile(new URL('../../resources/views/portal/fokus-law.blade.php', import.meta.url), 'utf8');
const assetManifest = JSON.parse(await readFile(new URL('../../config/fokus-law-assets.json', import.meta.url), 'utf8'));
const assetUrls = new Map();
assert.equal(assetManifest.version, 1);
assert.equal(assetManifest.algorithm, 'sha256');
for (const [source, entry] of Object.entries(assetManifest.assets)) {
    assert.match(entry.sha256, /^[a-f0-9]{64}$/);
    const dot = source.lastIndexOf('.');
    const expectedPath = source.slice(0, dot) + '.' + entry.sha256 + source.slice(dot);
    assert.equal(entry.path, expectedPath);
    assert.ok(!source.includes('..') && !source.startsWith('/'));
    const bytes = await readFile(new URL('../../public/' + entry.path, import.meta.url));
    assert.equal(createHash('sha256').update(bytes).digest('hex'), entry.sha256);
    assetUrls.set(source, '/' + entry.path);
}
let resolvedAssets = 0;
const shellView = shellTemplate.replace(/{{\s*\\App\\Support\\FokusLawAssets::url\('([^']+)'\)\s*}}/g, (_, source) => {
    assert.ok(assetUrls.has(source), 'Missing generated asset: ' + source);
    resolvedAssets += 1;
    return assetUrls.get(source);
});
assert.equal(resolvedAssets, 14, 'Resolve every real Blade asset reference for the static browser fixture.');
assert.ok(!shellView.includes('FokusLawAssets::url'), 'Do not serve unresolved Blade asset helpers.');
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
        modules: [{ id: 'C1', family: 'contatos' }, { id: 'P1', family: 'processos' }], support_mode: supportMode,
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
        await expect(page.locator('.law-rail')).toHaveCount(0);
        await expect(page.getByLabel('Sobre o Fokus Law')).toHaveCount(0);
        if (viewport.width <= 720) await page.getByRole('button', { name: 'Abrir menu' }).click();
        const dashboard = page.getByRole('link', { name: 'Dashboard', exact: true });
        const contacts = page.getByRole('button', { name: 'Contatos', exact: true });
        const processes = page.getByRole('button', { name: 'Processos', exact: true });
        const dimensions = await Promise.all([dashboard, contacts, processes].map((item) => item.evaluate((node) => {
            const bounds = node.getBoundingClientRect();
            const style = getComputedStyle(node);
            return [bounds.width, bounds.height, style.fontSize];
        })));
        expect(dimensions[1]).toEqual(dimensions[0]);
        expect(dimensions[2]).toEqual(dimensions[0]);
        await expect(contacts.locator('img.law-module-chevron')).toHaveAttribute('src', /Arrow-Button-Down--Streamline-Ultimate-Bold\.png$/);
        await expect(contacts.locator('img.law-module-chevron')).toHaveCSS('width', '8px');
        await expect(contacts.locator('img.law-module-chevron')).toHaveCSS('height', '8px');
        await contacts.click();
        await expect(contacts).toHaveAttribute('aria-expanded', 'true');
        await processes.click();
        await expect(processes).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('#account-toggle img.law-account-chevron')).toHaveCSS('transform', 'matrix(-1, 0, 0, -1, 0, 0)');
        await page.locator('#account-toggle').click();
        await expect(page.locator('#account-toggle img.law-account-chevron')).toHaveCSS('transform', 'none');
        const accountWidths = await page.locator('#account-panel .law-nav-item').evaluateAll((items) => items.map((item) => item.getBoundingClientRect().width));
        expect(accountWidths).toHaveLength(4);
        expect(new Set(accountWidths).size).toBe(1);
        await expect(page.locator('#account-toggle img.law-account-chevron')).toHaveAttribute('src', /Arrow-Button-Down--Streamline-Ultimate-Bold\.png$/);
        await expect(page.locator('#account-panel').getByRole('link', { name: 'Meu perfil' })).toHaveAttribute('href', '/portal/fokus-law/perfil');
        await expect(page.locator('#account-panel').getByRole('button', { name: 'Preferências' })).toBeVisible();
        await expect(page.locator('#account-panel').getByRole('button', { name: 'Configurações' })).toBeVisible();
        await expect(page.locator('#account-panel').getByRole('button', { name: 'Sair' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.locator('#account-toggle')).toBeFocused();
        if (viewport.width <= 720) await page.keyboard.press('Escape');
        expect(await page.locator('.law-shell').evaluate((element) => getComputedStyle(element).display)).toBe(viewport.width <= 720 ? 'block' : 'grid');
        expect(await page.locator('body').evaluate((element) => getComputedStyle(element).fontFamily)).toContain('Google Sans');
        const personalFieldWidths = await page.locator('#profile-name').evaluate((element) => [element.getBoundingClientRect().width, element.parentElement.getBoundingClientRect().width]);
        expect(personalFieldWidths[0]).toBeGreaterThan(personalFieldWidths[1] * 0.8);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(overflow).toBe(false);
        if (viewport.width <= 720) await page.getByRole('button', { name: 'Abrir menu' }).click();
        await page.locator('#account-toggle').click();
        await page.locator('#account-settings').click();
        await expect(page.locator('#content-region h2')).toHaveText('Configurações');
        await expect(page.locator('#content-region .law-settings-link[href="/portal/fokus-law/perfil"]')).toHaveCount(0);
    }
});

test('dashboard exibe módulos com ícones coloridos de 32px e sem botão de abertura', async ({ page }) => {
    await openProfile(page);
    const dashboardShell = shellView.replace("{{ $initialPage ?? 'overview' }}", 'overview').replace("@include('portal.partials.fokus-law-profile')", profileView);
    await page.route('**/portal/fokus-law', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: dashboardShell }));
    await page.goto('/portal/fokus-law');
    await expect(page.locator('.law-dashboard-widget-mark img')).toHaveCount(2);
    await expect(page.locator('.law-dashboard-contacts .law-dashboard-widget-mark img')).toHaveAttribute('src', /Single-Man-Phone-Book--Streamline-Ultimate-Colors\.png$/);
    await expect(page.locator('.law-dashboard-module-wide .law-dashboard-widget-mark img')).toHaveAttribute('src', /Archive-Books--Streamline-Ultimate-Colors\.png$/);
    await expect(page.getByRole('button', { name: 'Abrir módulo' })).toHaveCount(0);
    for (const image of await page.locator('.law-dashboard-widget-mark img').all()) {
        const styles = await image.evaluate((node) => ({ width: node.getBoundingClientRect().width, height: node.getBoundingClientRect().height, filter: getComputedStyle(node).filter, background: getComputedStyle(node.parentElement).backgroundColor, border: getComputedStyle(node.parentElement).borderWidth, src: node.currentSrc, loaded: node.complete && node.naturalWidth > 0 }));
        expect(styles).toMatchObject({ width: 32, height: 32, filter: 'none', background: 'rgba(0, 0, 0, 0)', border: '0px', loaded: true });
        expect(styles.src).toContain('Streamline-Ultimate-Colors.png');
    }
    await page.getByRole('button', { name: 'Contatos', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Contatos', exact: true })).toHaveAttribute('aria-expanded', 'true');
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
const transferShellView = shellView
    .replace("{{ $initialPage ?? 'overview' }}", 'transfer')
    .replace("@include('portal.partials.fokus-law-profile')", profileView);

const lawUnits = [
    { id: 'LUN_CENTRAL', name: 'Setor Central', status: 'ativo' },
    { id: 'LUN_INTERIOR', name: 'Setor Interior', status: 'ativo' },
];
const roleFixtures = lawUnits.flatMap((unit) => [
    { id: `${unit.id}_ADMIN`, code: 'unit_admin', name: 'Administrador do setor', is_system: true, version: 1, assignable: true, permissions: ['law.users.manage', 'law.roles.manage', 'law.contacts.view'] },
    { id: `${unit.id}_CHIEF`, code: 'chief_clerk', name: 'Chefe / Escrivão', is_system: true, version: 1, assignable: true, permissions: ['law.users.manage', 'law.contacts.view'] },
    { id: `${unit.id}_OPERATOR`, code: 'operator', name: 'Operador', is_system: true, version: 1, assignable: true, permissions: ['law.contacts.view'] },
    { id: `${unit.id}_VIEWER`, code: 'viewer', name: 'Somente leitura', is_system: true, version: 1, assignable: true, permissions: ['law.contacts.view'] },
]);

async function openUsers(page, viewport) {
    await page.setViewportSize(viewport);
    await page.route('**/portal/usuarios', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: usersShellView }));
    await page.route('**/portal/transferir-administracao', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: transferShellView }));
    await page.route('**/api/law/shell-context', (route) => route.fulfill({ json: {
        user: { id: 'USR_ADMIN', name: 'Érica Menezes', email: 'erica@example.test' },
        company: { id: 'COM_TEST', name: 'Menezes Advocacia', role: 'admin' },
        active_company_id: 'COM_TEST', companies: [{ id: 'COM_TEST', name: 'Menezes Advocacia' }],
        units: lawUnits, active_unit_id: lawUnits[0].id, active_unit: { id: lawUnits[0].id, name: lawUnits[0].name },
        subscription: null, permissions: { manage_company_users: true, view_company_users: true, manage_law_roles: true, assign_law_roles: true, transfer_admin: true, manage_settings: true },
        modules: [], support_mode: null,
    } }));
    await page.route('**/api/law/units', (route) => route.fulfill({ json: { units: lawUnits, active_unit: { id: lawUnits[0].id, name: lawUnits[0].name }, active_unit_id: lawUnits[0].id } }));
    await page.route('**/api/law/notifications', (route) => route.fulfill({ json: { unread_count: 0, notifications: [] } }));
    await page.route('**/api/csrf-token', (route) => route.fulfill({ json: { token: 'law-users-test-token' } }));
    let users = [
        { id: 'VNC_ADMIN', name: 'Érica Menezes', email: 'erica@example.test', role: 'admin', status: 'ativo', version: 1, law_memberships: [] },
        { id: 'VNC_001', name: 'Ana Operadora', email: 'ana@example.test', role: 'usuario', status: 'ativo', version: 1, law_memberships: [{ id: 'LUM_001', unit_id: lawUnits[0].id, unit_name: lawUnits[0].name, status: 'ativo', version: 1, role_id: `${lawUnits[0].id}_OPERATOR`, role_code: 'operator', role_name: 'Operador' }] },
    ];
    let conflictNextMutation = false;
    const mutations = [];
    await page.route('**/api/law/access/roles**', async (route) => {
        const request = route.request();
        const url = new URL(route.request().url());
        const unitId = url.searchParams.get('law_unit_id') || lawUnits[0].id;
        if (request.method() === 'POST') {
            const body = request.postDataJSON();
            roleFixtures.push({ id: `${unitId}_CUSTOM`, code: 'custom', name: body.name, is_system: false, version: 1, assignable: true, permissions: body.permission_codes });
            return route.fulfill({ status: 201, json: { id: `${unitId}_CUSTOM`, name: body.name, version: 1 } });
        }
        if (request.method() === 'PATCH') return route.fulfill({ json: { message: 'Perfil atualizado.' } });
        await route.fulfill({ json: { roles: roleFixtures.filter((role) => role.id.startsWith(unitId)) } });
    });
    await page.route('**/api/law/access/permissions**', (route) => route.fulfill({ json: { permissions: [
        { code: 'law.contacts.view', description: 'Visualizar contatos', granted_to_actor: true },
        { code: 'law.subscription.manage', description: 'Gerenciar assinatura', granted_to_actor: false },
    ] } }));
    await page.route('**/api/portal/audit-history', (route) => route.fulfill({ json: [] }));
    await page.route('**/api/portal/users**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() === 'GET') return route.fulfill({ json: users });
        const pathParts = url.pathname.split('/');
        const id = decodeURIComponent(pathParts[pathParts.indexOf('users') + 1]);
        const body = request.postDataJSON() || {};
        mutations.push({ method: request.method(), path: url.pathname, body });
        if (request.method() === 'POST' && url.pathname === '/api/portal/users') {
            return route.fulfill({ status: 201, json: { message: 'Convite enviado para o e-mail cadastrado.' } });
        }
        if (conflictNextMutation) {
            conflictNextMutation = false;
            return route.fulfill({ status: 409, json: { message: 'O acesso deste setor foi alterado por outra pessoa. Atualize a tela e tente novamente.' } });
        }
        const current = users.find((user) => user.id === id);
        if (url.pathname.endsWith('/law-access/restore')) {
            current.law_memberships[0].status = 'ativo'; current.law_memberships[0].version += 1;
        } else if (current && url.pathname.endsWith('/law-access')) {
            const membership = current.law_memberships[0];
            if (body.law_access_role_id) membership.role_id = body.law_access_role_id;
            if (body.status) membership.status = body.status;
            membership.version += 1;
        }
        return route.fulfill({ json: { message: 'Vínculo atualizado.' } });
    });
    await page.route('**/api/portal/transfer-admin', (route) => route.fulfill({ json: { message: 'Enviamos o aceite de transferência.' } }));
    await page.goto('/portal/usuarios');
    await expect(page.getByRole('heading', { name: 'Usuários, perfis e permissões' })).toBeVisible();
    return { mutations, setConflict: () => { conflictNextMutation = true; } };
}

test('gestão de usuários integrada ao shell funciona em desktop, tablet e celular', async ({ page }) => {
    for (const viewport of [{ width: 1365, height: 900 }, { width: 768, height: 1024 }, { width: 375, height: 812 }]) {
        const { mutations } = await openUsers(page, viewport);
        await expect(page.locator('.law-settings-navigation a[aria-current="page"]')).toHaveAttribute('href', '/portal/usuarios');
        await expect(page.getByRole('heading', { name: 'Perfis de Setor Central' })).toBeVisible();
        await expect(page.getByText('Ana Operadora')).toBeVisible();
        await expect(page.getByText('111.***.***-35')).toHaveCount(0);
        await expect(page.locator('#content-region .law-users-transfer')).toBeVisible();

        await page.getByLabel('Nome completo').fill('Pessoa Nova');
        await page.getByLabel('CPF').fill('11111111111');
        await page.getByLabel('E-mail').fill('nova@example.test');
        await page.getByRole('checkbox', { name: 'Setor Central' }).check();
        await selectFokusOption(page, 'Perfil para Setor Central', 'Operador');
        await page.getByRole('checkbox', { name: 'Setor Interior' }).check();
        await selectFokusOption(page, 'Perfil para Setor Interior', 'Operador');
        await page.getByRole('button', { name: 'Enviar convite' }).click();
        await expect(page.locator('#content-region .law-users-feedback').first()).toContainText('CPF válido');
        expect(mutations.filter((item) => item.method === 'POST')).toHaveLength(0);

        await page.getByLabel('CPF').fill('52998224725');
        await page.getByRole('button', { name: 'Enviar convite' }).click();
        await expect(page.locator('#content-region .law-users-feedback').first()).toContainText('Convite enviado');
        expect(mutations.some((item) => item.method === 'POST' && item.body.cpf === '52998224725' && item.body.law_assignments.length === 2)).toBe(true);

        await selectFokusOption(page, 'Perfil de Ana Operadora', 'Gestor da unidade');
        await page.getByRole('button', { name: 'Salvar perfil' }).click();
        await expect(page.locator('#content-region .law-users-feedback').first()).toContainText('Acesso atualizado');
        expect(mutations.some((item) => item.method === 'PUT' && item.body.law_access_role_id === `${lawUnits[0].id}_CHIEF` && item.body.version === 1)).toBe(true);

        await page.getByRole('button', { name: 'Suspender acesso' }).click();
        await expect(page.locator('#content-region .law-users-feedback').first()).toContainText('Acesso atualizado');
        expect(mutations.some((item) => item.method === 'PUT' && item.body.status === 'suspenso')).toBe(true);

        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'Remover do setor' }).click();
        expect(mutations.some((item) => item.method === 'PUT' && item.body.status === 'removido')).toBe(true);
        await page.getByRole('button', { name: 'Restaurar acesso' }).click();
        await expect(page.locator('#content-region .law-users-feedback').first()).toContainText('Acesso restaurado');
        expect(mutations.some((item) => item.method === 'POST' && item.path.endsWith('/law-access/restore'))).toBe(true);

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(overflow).toBe(false);
        expect(await page.locator('body').evaluate((element) => getComputedStyle(element).fontFamily)).toContain('Google Sans');
        if (viewport.width <= 720) {
            await page.getByRole('button', { name: 'Abrir menu' }).click();
            await expect(page.locator('#law-sidebar')).toHaveClass(/is-open/);
        }
    }
});

test('perfis personalizados e transferir administração funcionam dentro do shell', async ({ page }) => {
    await openUsers(page, { width: 1365, height: 900 });
    await page.getByRole('button', { name: 'Criar perfil personalizado' }).click();
    await page.getByLabel('Nome do perfil').fill('Leitura de contatos');
    await page.getByLabel('Visualizar contatos').check();
    await page.locator('.law-role-editor').getByRole('button', { name: 'Salvar perfil' }).click();
    await expect(page.locator('#content-region .law-users-feedback').first()).toContainText('Perfil salvo');

    await page.goto('/portal/transferir-administracao');
    await expect(page.getByRole('heading', { name: 'Transferir administração' })).toBeVisible();
    await expect(page.locator('.law-settings-navigation a[aria-current="page"]')).toHaveAttribute('href', '/portal/transferir-administracao');
    await expect(page.getByRole('heading', { name: 'Histórico de administração' })).toBeVisible();
});

test('link de transferência mostra a empresa e conclui aceite ou recusa', async ({ page, browser }) => {
    const preview = { company_name: 'Menezes Advocacia', previous_access: 'operador nos setores ativos' };
    await page.route('**/api/auth/preview-admin-transfer**', (route) => route.fulfill({ json: preview }));
    await page.route('**/api/auth/accept-admin-transfer', (route) => route.fulfill({ json: { message: 'Administração transferida com sucesso.' } }));
    await page.goto('/auth/aceitar-transferencia.html?token=valid-accept');
    await expect(page.getByText('Menezes Advocacia')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Aceitar transferência' })).toBeEnabled();
    await page.getByRole('button', { name: 'Aceitar transferência' }).click();
    await expect(page.getByRole('status')).toContainText('Administração transferida com sucesso');

    const declinePage = await browser.newPage();
    await declinePage.route('**/api/auth/preview-admin-transfer**', (route) => route.fulfill({ json: preview }));
    await declinePage.route('**/api/auth/decline-admin-transfer', (route) => route.fulfill({ json: { message: 'A transferência foi recusada. O admin atual permanece responsável pela empresa.' } }));
    await declinePage.goto('/auth/aceitar-transferencia.html?token=valid-decline');
    await declinePage.getByRole('button', { name: 'Recusar transferência' }).click();
    await expect(declinePage.getByRole('status')).toContainText('admin atual permanece responsável');
    await expect(declinePage.getByRole('button', { name: 'Aceitar transferência' })).toBeHidden();
});

test('lista vazia, erro de API e conflito de versão são apresentados sem perder a página', async ({ page }) => {
    const { setConflict } = await openUsers(page, { width: 1365, height: 900 });
    setConflict();
    await page.getByRole('button', { name: 'Suspender acesso' }).click();
    await expect(page.locator('#content-region .law-users-feedback').first()).toContainText('alterado por outra pessoa');
    await expect(page.getByRole('heading', { name: 'Pessoas com acesso ao setor' })).toBeVisible();

    await page.unroute('**/api/portal/users**');
    await page.route('**/api/portal/users**', (route) => route.fulfill({ json: [] }));
    await page.reload();
    await expect(page.getByRole('status').filter({ hasText: 'Nenhuma pessoa possui vínculo com este setor' })).toBeVisible();

    await page.unroute('**/api/portal/users**');
    await page.route('**/api/portal/users**', (route) => route.fulfill({ status: 500, json: { message: 'Falha simulada ao carregar.' } }));
    await page.reload();
    await expect(page.getByRole('status').filter({ hasText: 'Falha simulada ao carregar.' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Usuários, perfis e permissões' })).toBeVisible();
});
