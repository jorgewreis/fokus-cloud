import { test, expect } from '@playwright/test';

const viewports = [
    ['desktop', { width: 1440, height: 900 }],
    ['notebook', { width: 1024, height: 768 }],
    ['tablet', { width: 768, height: 1024 }],
    ['mobile', { width: 375, height: 812 }],
    ['mobile-narrow', { width: 320, height: 700 }],
];

for (const [name, viewport] of viewports) {
    for (const [route, pageId] of [['empresas', 'companies'], ['produtos', 'products'], ['modulos', 'modules']]) {
        test(`visual ${pageId} ${name}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await page.goto(`/backoffice/${route}`);
            await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', pageId);
            await expect(page.locator('.backoffice-records-page')).toBeVisible();
            await expect(page).toHaveScreenshot(`${pageId}-${name}.png`, {
                fullPage: true,
                animations: 'disabled',
                // The same Chromium revision rasterizes the bundled webfonts
                // differently on Windows and Linux. Keep the baseline strict
                // enough to flag layout/color regressions while tolerating
                // cross-platform glyph antialiasing.
                maxDiffPixelRatio: 0.08,
            });
        });
    }
}

test('navegação cancela a página anterior e mantém somente um drawer portaled', async ({ page }) => {
    await page.goto('/backoffice/produtos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'products');
    await expect(page.locator('body > #product-drawer')).toHaveCount(1);
    await page.evaluate(() => window.__backofficeRouter.navigate('companies'));
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'companies');
    await expect(page.locator('body > #product-drawer')).toHaveCount(0);
    await expect(page.locator('body > #company-drawer')).toHaveCount(1);
});

test('diretório Usuários abre detalhes de conta e se adapta a telas menores', async ({ page }) => {
    for (const viewport of [{ width: 1440, height: 900 }, { width: 768, height: 1024 }, { width: 375, height: 812 }]) {
        await page.setViewportSize(viewport);
        await page.goto('/backoffice/usuarios');
        await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'users');
        await expect(page.locator('#users-list tr[data-account-type]')).toHaveCount(2);
        await expect(page.locator('#users-list tr[data-account-type="empresa"] [data-label="Tipo"]')).toHaveText('Usuário Externo - Assinatura');
        await expect(page.locator('#users-list tr[data-account-type="empresa"] [data-label="Perfil ou vínculos"]')).toContainText('Administrador');
        await expect(page.locator('#users-list tr[data-account-type="empresa"] [data-label="Perfil ou vínculos"]')).toContainText('Empresa de Demonstração');
        await expect(page.locator('#users-list tr[data-account-type="plataforma"] [data-label="Tipo"]')).toHaveText('Usuário interno - FokusCloud');
        const brokenActionIcons = await page.locator('#users-list button[data-user-action] img').evaluateAll((images) => images.filter((image) => image.naturalWidth === 0).map((image) => new URL(image.src).pathname));
        expect(brokenActionIcons).toEqual([]);
        await page.getByRole('button', { name: 'Ver detalhes' }).nth(1).click();
        await expect(page.locator('#user-drawer')).toBeVisible();
        await expect(page.locator('#user-drawer-title')).toHaveText('Ana Empresa');
        await expect(page.locator('#user-view-panel')).toContainText('Assinaturas da empresa');
        await expect(page.locator('#user-view-panel')).toContainText('123.456.789-01');
        const overflow = await page.locator('.backoffice-records-page').evaluate((element) => element.scrollWidth > element.clientWidth);
        expect(overflow).toBe(false);
        await page.keyboard.press('Escape');
        await expect(page.locator('#user-drawer')).toBeHidden();
    }
});

test('Novo usuário permite convidar usuário externo vinculado a empresa Fokus Law', async ({ page }) => {
    await page.goto('/backoffice/usuarios');
    await page.locator('#user-invite').click();
    await page.locator('#user-account-type').selectOption('empresa');
    await expect(page.locator('#user-company option[value="CMP_VISUAL"]')).toHaveText('Fokus Law - Empresa de Demonstração');
    await expect(page.locator('#user-external-role')).toBeVisible();
    await page.locator('#user-name').fill('Pessoa Convidada');
    await page.locator('#user-email').fill('convidada@example.test');
    await page.locator('#user-cpf').fill('52998224725');
    await page.locator('#user-company').selectOption('CMP_VISUAL');
    await page.locator('#user-external-role').selectOption('gestor');
    await page.locator('#user-form-submit').click();
    await expect(page.locator('#backoffice-toast-container .fs-toast-body')).toContainText('Convite enviado ao usuário externo.');
});

test('consulta de e-mail no acesso Fokus Law identifica nome e sistema ativo', async ({ page }) => {
    await page.goto('/marketing/products/fokus-law.html');
    await page.locator('#law-email').fill('pessoa@example.test');
    await expect(page.locator('#law-system')).toBeEnabled();
    await expect(page.locator('#law-system option')).toHaveText('Fokus Law · Advocacia - Empresa de Demonstração');
    await expect(page.locator('[data-law-login-status]')).toContainText('Pessoa Teste');
    await expect(page.locator('#law-password')).toBeEnabled();
});

test('consulta de e-mail mostra usuário existente sem assinatura ativa', async ({ page }) => {
    await page.route('**/api/auth/law-context', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            user: { name: 'Pessoa Teste', email: 'pessoa@example.test' },
            systems: [],
            message: 'Não existe nenhuma assinatura ativa do Fokus Law vinculada a este usuário.',
        }),
    }));
    await page.goto('/marketing/products/fokus-law.html');
    await page.locator('#law-email').fill('pessoa@example.test');
    await expect(page.locator('[data-law-login-status]')).toContainText('Pessoa Teste — Não existe nenhuma assinatura ativa do Fokus Law vinculada a este usuário.');
    await expect(page.locator('#law-system')).toBeDisabled();
    await expect(page.locator('#law-password')).toBeDisabled();
});

test('Fokus Law usa o mesmo toast do Backoffice para erro de consulta', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Acesso interno não autenticado.' }) }));
    await page.route('**/api/auth/law-context', (route) => route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Usuário não encontrado.' }) }));
    await page.goto('/marketing/products/fokus-law.html');
    await page.locator('#law-email').fill('ausente@example.test');
    await expect(page.locator('#backoffice-toast-container .fs-backoffice-toast')).toBeVisible();
    await expect(page.locator('#backoffice-toast-container .fs-toast-title')).toHaveText('Erro');
    await expect(page.locator('#backoffice-toast-container .fs-toast-body')).toHaveText('Usuário não encontrado.');
});

test('Superadministrador MFA pode iniciar acesso de suporte a perfil real', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ admin: { role: 'superadministrador' } }) }));
    await page.route('**/api/backoffice/support/law-context', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ subscriptions: [{ id: 'SUB_TESTE_01', label: 'Empresa Teste — Essencial (Suspensa)', status: 'suspensa', users: [{ membership_id: 'MBS_TESTE_01', label: 'Administrador — Pessoa Teste (pessoa@example.test)' }] }] }) }));
    await page.route('**/api/backoffice/support/access', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ redirect_to: '/portal' }) }));
    await page.goto('/marketing/products/fokus-law.html');
    await expect(page.locator('[aria-labelledby="law-support-title"]')).toBeVisible();
    await expect(page.locator('[data-law-login-form]')).toBeHidden();
    await page.locator('#law-support-subscription').selectOption('SUB_TESTE_01');
    await page.locator('#law-support-user').selectOption('MBS_TESTE_01');
    await page.locator('#law-support-reason').fill('Investigar erro de permissões');
    await page.locator('#law-support-start').click();
    await expect(page).toHaveURL(/\/portal$/);
});

test('Portal identifica e encerra modo de suporte preservando o retorno ao Backoffice', async ({ page }) => {
    await page.route('**/api/csrf-token', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'csrf-visual' }) }));
    await page.route('**/api/auth/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ user: { name: 'Pessoa Teste', email_verified: true }, companies: [{ id: 'CMP_TESTE', name: 'Empresa Teste', role: 'admin' }], active_company_id: 'CMP_TESTE', support_mode: { active: true, company: 'Empresa Teste', subscription_status: 'suspensa', reason: 'Investigar falha' } }) }));
    await page.route('**/api/subscriptions', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) }));
    await page.route('**/api/backoffice/support/exit', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ redirect_to: '/backoffice/' }) }));
    await page.goto('/portal/dashboard.html');
    await expect(page.getByText('Modo de suporte ativo')).toBeVisible();
    await expect(page.locator('[data-support-context]')).toContainText('Empresa Teste');
    await page.getByRole('button', { name: 'Encerrar acesso de suporte' }).click();
    await expect(page).toHaveURL(/\/backoffice\/$/);
});

test('drawer de empresas preserva largura, cards e alertas do contrato visual', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/empresas');
    await page.locator('#company-create').click();
    await expect(page.locator('#company-drawer')).toBeVisible();

    const drawerContract = await page.locator('#company-drawer').evaluate((drawer) => {
        const cards = [...drawer.querySelectorAll('#company-form > .fs-card-panel')];
        const labels = [...drawer.querySelectorAll('#company-form label.fs-form-label')];
        const formColumns = [...drawer.querySelectorAll('#company-form .fs-form-col')];
        const controls = [...drawer.querySelectorAll('#company-form input:not([type="hidden"]), #company-form select')];
        return {
            width: getComputedStyle(drawer).width,
            bodyPadding: getComputedStyle(drawer.querySelector(':scope > .fs-offcanvas-body')).padding,
            cardsFitContent: cards.every((card) => {
                const body = card.querySelector(':scope > .fs-card-body');
                return body && body.getBoundingClientRect().bottom <= card.getBoundingClientRect().bottom + 1;
            }),
            cardBodySpacing: cards.every((card) => card.querySelector(':scope > .fs-card-body.fs-u-mx-2.fs-u-mt-2.fs-u-mb-3')),
            rowSpacing: [...drawer.querySelectorAll('#company-form .fs-card-body > .fs-form-row')]
                .every((row) => row.classList.contains('fs-u-mb-2')),
            labelsHaveSpans: labels.every((label) => label.firstElementChild?.matches('span.fs-u-ml-2')),
            labelsHaveSpacing: labels.every((label) => label.classList.contains('fs-u-mt-3')),
            labelsFitContent: labels.every((label) => getComputedStyle(label).width !== 'auto'),
            columnsDoNotGrow: formColumns.every((column) => getComputedStyle(column).flex === '0 1 auto'),
            controlsUseGoogleSans: controls.every((control) => getComputedStyle(control).fontFamily.includes('Google Sans')),
            fieldWidths: {
                documentType: drawer.querySelector('#company-document-type').classList.contains('fs-width-300'),
                document: drawer.querySelector('#company-document-number').classList.contains('fs-width-500'),
                legalName: drawer.querySelector('#company-legal-name').classList.contains('fs-width-600'),
                adminName: drawer.querySelector('#company-admin-name').classList.contains('fs-width-600'),
            },
        };
    });

    expect(drawerContract).toEqual({
        width: '450px',
        bodyPadding: '20px',
        cardsFitContent: true,
        cardBodySpacing: true,
        rowSpacing: true,
        labelsHaveSpans: true,
        labelsHaveSpacing: true,
        labelsFitContent: true,
        columnsDoNotGrow: true,
        controlsUseGoogleSans: true,
        fieldWidths: { documentType: true, document: true, legalName: true, adminName: true },
    });

    const alertContract = await page.locator('#company-message').evaluate((alert) => {
        alert.hidden = false;
        const style = getComputedStyle(alert);
        return {
            display: style.display,
            flexDirection: style.flexDirection,
            margin: style.margin,
            padding: style.padding,
        };
    });

    expect(alertContract).toEqual({
        display: 'flex',
        flexDirection: 'column',
        margin: '10px',
        padding: '10px 20px',
    });
});

test('controles compartilhados preservam a cascata do Fokus Styles', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/produtos');
    await expect(page.locator('#product-pagination .fs-page-item.is-active .fs-page-link')).toHaveCount(1);

    const beforeHover = await page.evaluate(() => {
        const filter = document.querySelector('#product-filter-form .fs-btn-outline-secondary');
        const activePage = document.querySelector('#product-pagination .fs-page-item.is-active .fs-page-link');
        const input = document.querySelector('#product-search');
        const select = document.querySelector('#product-status-filter');
        return {
            filterBorder: getComputedStyle(filter).border,
            activeBorder: getComputedStyle(activePage).border,
            activeBackground: getComputedStyle(activePage).backgroundColor,
            inputPadding: getComputedStyle(input).padding,
            selectPadding: getComputedStyle(select).padding,
        };
    });
    expect(beforeHover.filterBorder).toMatch(/^1px solid (rgb|oklch)/);
    expect(beforeHover.activeBorder).toMatch(/^1px solid (rgb|oklch)/);
    expect(beforeHover.activeBackground).toMatch(/^(rgb|oklch)/);
    expect(beforeHover.inputPadding).toBe('0px 12px');
    expect(beforeHover.selectPadding).toBe('0px 12px');

    await page.locator('#product-filter-form .fs-btn-outline-secondary').hover();
    await page.waitForTimeout(200);
    const hoverContract = await page.locator('#product-filter-form .fs-btn-outline-secondary').evaluate((button) => ({
        hovered: button.matches(':hover'),
        background: getComputedStyle(button).backgroundColor,
        color: getComputedStyle(button).color,
    }));
    expect(hoverContract.hovered).toBe(true);
    expect(hoverContract.color).toBe('rgb(255, 255, 255)');
    expect(hoverContract.background).toMatch(/^(rgb|oklch)/);
});

test('produtos replica o contrato visual e mantém create, edit e view independentes', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/produtos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'products');
    await expect(page.locator('#product-list tr')).toHaveCount(1);

    await page.locator('#product-new').click();
    await expect(page.locator('#product-drawer')).toBeVisible();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'create');
    const createContract = await page.locator('#product-drawer').evaluate((drawer) => {
        const labels = [...drawer.querySelectorAll('#product-form label.fs-form-label')].filter((label) => !label.hidden);
        const controls = [...drawer.querySelectorAll('#product-form input, #product-form select, #product-form textarea')].filter((control) => !control.disabled);
        const textareas = [...drawer.querySelectorAll('#product-form textarea')];
        return {
            width: getComputedStyle(drawer).width,
            cards: drawer.querySelectorAll('#product-form > .fs-card.fs-card-panel').length,
            labelsHaveSpans: labels.every((label) => label.firstElementChild?.matches('span.fs-u-ml-2')),
            labelsHaveSpacing: labels.every((label) => label.classList.contains('fs-u-mt-3')),
            cardBodySpacing: [...drawer.querySelectorAll('#product-form .fs-card-panel > .fs-card-body')]
                .every((body) => body.matches('.fs-u-mx-2.fs-u-mt-2.fs-u-mb-3')),
            controlsUseGoogleSans: controls.every((control) => getComputedStyle(control).fontFamily.includes('Google Sans')),
            textareaPadding: [...new Set(textareas.map((textarea) => `${getComputedStyle(textarea).paddingTop} ${getComputedStyle(textarea).paddingRight}`))],
            fieldWidths: {
                code: drawer.querySelector('#product-code').classList.contains('fs-width-600'),
                technicalDescription: drawer.querySelector('#product-technical-description').classList.contains('fs-width-600'),
                commercialDescription: drawer.querySelector('#product-commercial-content').classList.contains('fs-width-600'),
            },
        };
    });
    expect(createContract).toEqual({
        width: '450px',
        cards: 2,
        labelsHaveSpans: true,
        labelsHaveSpacing: true,
        cardBodySpacing: true,
        controlsUseGoogleSans: true,
        textareaPadding: ['10px 15px'],
        fieldWidths: { code: true, technicalDescription: true, commercialDescription: true },
    });

    await page.locator('#product-drawer-close').click();
    await expect(page.locator('#product-drawer')).toBeHidden();
    await expect(page.getByRole('button', { name: 'Editar produto' })).toHaveCount(0);
    await page.route('**/api/backoffice/catalog/products', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, status: 'pausado' })) } });
    });
    await page.reload();
    await page.getByRole('button', { name: 'Editar produto' }).click();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'edit');
    await expect(page.locator('#product-drawer-title')).toHaveText('Editar dados do produto');
    await expect(page.locator('#product-form-submit')).toHaveText('Salvar alterações');
    await expect(page.locator('#product-display-order-field')).toBeVisible();

    await page.locator('#product-drawer-close').click();
    await page.getByRole('button', { name: 'Ver detalhes do produto' }).click();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'view');
    await expect(page.locator('#product-form')).toBeHidden();
    await expect(page.locator('#product-view-panel')).toBeVisible();
    await expect(page.locator('#product-view-name')).toHaveText('Fokus Law');
});

test('módulos replica o contrato visual, personalizações e estados do drawer', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/modulos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'modules');
    await expect(page.locator('#module-list tr')).toHaveCount(1);

    await page.locator('#module-new').click();
    await expect(page.locator('#module-drawer')).toBeVisible();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'create');
    const createContract = await page.locator('#module-drawer').evaluate((drawer) => {
        const labels = [...drawer.querySelectorAll('#module-form label.fs-form-label')].filter((label) => !label.hidden);
        const textareas = [...drawer.querySelectorAll('#module-form textarea')];
        return {
            width: getComputedStyle(drawer).width,
            cards: drawer.querySelectorAll('#module-form > .fs-card.fs-card-panel').length,
            labelsHaveSpans: labels.every((label) => label.firstElementChild?.matches('span.fs-u-ml-2')),
            cardBodySpacing: [...drawer.querySelectorAll('#module-form .fs-card-panel > .fs-card-body')]
                .every((body) => body.matches('.fs-u-mx-2.fs-u-mt-2.fs-u-mb-3')),
            compactMultiSelects: [...drawer.querySelectorAll('#module-form select[multiple]')]
                .every((select) => select.getAttribute('size') === '1'),
            textareaPadding: [...new Set(textareas.map((textarea) => getComputedStyle(textarea).padding))],
            textareaFonts: [...new Set(textareas.map((textarea) => getComputedStyle(textarea).fontFamily))],
        };
    });
    expect(createContract).toEqual({
        width: '450px',
        cards: 4,
        labelsHaveSpans: true,
        cardBodySpacing: true,
        compactMultiSelects: true,
        textareaPadding: ['10px 15px'],
        textareaFonts: ['"Google Sans", sans-serif'],
    });

    await page.locator('#module-personalizations-open').click();
    await expect(page.locator('#module-personalizations-drawer')).toBeVisible();
    await page.locator('#personalization-add').click();
    await expect(page.locator('#personalizations-list > .fs-card')).toHaveCount(1);
    await page.locator('#personalizations-save').click();
    await expect(page.locator('#module-personalizations-drawer')).toBeHidden();

    await page.locator('#module-drawer-close').click();
    await expect(page.getByRole('button', { name: 'Editar módulo' })).toHaveCount(0);
    await page.route('**/api/backoffice/catalog', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, modules: product.modules.map((module) => ({ ...module, status: 'inativo', publication_state: 'pausado' })) })) } });
    });
    await page.reload();
    await page.getByRole('button', { name: 'Editar módulo' }).click();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'edit');
    await expect(page.locator('#module-drawer-title')).toHaveText('Editar dados do módulo');
    await expect(page.locator('#module-form-submit')).toHaveText('Salvar alterações');
    await expect(page.locator('#module-edit-controls')).toBeVisible();

    await page.locator('#module-drawer-close').click();
    await page.getByRole('button', { name: 'Ver detalhes do módulo' }).click();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'view');
    await expect(page.locator('#module-form')).toBeHidden();
    await expect(page.locator('#module-view-panel')).toBeVisible();
    await expect(page.locator('#module-view-panel')).toContainText('Gestão de processos');
});

test('salvar alterações de módulo usa PATCH no módulo em edição', async ({ page }) => {
    let saveRequest = null;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/modules/MOD_1')) saveRequest = { method: request.method(), url: request.url() };
    });

    await page.route('**/api/backoffice/catalog', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, modules: product.modules.map((module) => ({ ...module, status: 'inativo', publication_state: 'pausado' })) })) } });
    });
    await page.goto('/backoffice/modulos');
    await page.getByRole('button', { name: 'Editar módulo' }).click();
    await expect(page.locator('#module-drawer')).toHaveAttribute('data-mode', 'edit');
    await page.locator('#module-name').fill('Gestão de processos atualizada');
    await page.locator('#module-form-submit').click();
    await expect.poll(() => saveRequest).toEqual({ method: 'PATCH', url: 'http://127.0.0.1:4177/api/backoffice/catalog/modules/MOD_1' });
});

test('salvar alterações de produto usa PATCH no produto em edição', async ({ page }) => {
    let saveRequest = null;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/products/PRD_LAW')) saveRequest = { method: request.method(), url: request.url() };
    });

    await page.route('**/api/backoffice/catalog/products', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: { ...data, products: data.products.map((product) => ({ ...product, status: 'pausado' })) } });
    });
    await page.goto('/backoffice/produtos');
    await page.getByRole('button', { name: 'Editar produto' }).click();
    await expect(page.locator('#product-drawer')).toHaveAttribute('data-mode', 'edit');
    await page.locator('#product-name').fill('Fokus Law atualizado');
    await page.locator('#product-form-submit').click();
    await expect.poll(() => saveRequest).toEqual({ method: 'PATCH', url: 'http://127.0.0.1:4177/api/backoffice/catalog/products/PRD_LAW' });
});

test('salvar alterações de plano usa PATCH no plano em edição', async ({ page }) => {
    let saveRequest = null;
    let saveRequests = 0;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/plans/PLN_1')) { saveRequests += 1; saveRequest = { method: request.method(), url: request.url() }; }
    });

    await page.route('**/api/backoffice/plans', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: data.map((plan) => ({ ...plan, status: 'inativo', publication_state: 'pausado' })) });
    });
    await page.goto('/backoffice/planos');
    await page.getByRole('button', { name: 'Editar plano' }).click();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'edit');
    await page.locator('#plan-name').fill('Essencial atualizado');
    await page.locator('#plan-form-submit').dblclick();
    await expect.poll(() => saveRequest).toEqual({ method: 'PATCH', url: 'http://127.0.0.1:4177/api/backoffice/catalog/plans/PLN_1' });
    expect(saveRequests).toBe(1);
});

test('ativar módulo não dispara a ação nem o toast mais de uma vez', async ({ page }) => {
    let activationRequests = 0;
    page.on('request', (request) => {
        if (request.url().endsWith('/api/backoffice/catalog/modules/MOD_1/activate')) activationRequests += 1;
    });

    await page.goto('/backoffice/modulos');
    await expect(page.locator('#module-list')).toBeVisible();
    await page.evaluate(() => {
        window.__moduleClickCount = 0;
        document.querySelector('#module-list').addEventListener('click', () => { window.__moduleClickCount += 1; }, true);
    });
    await page.locator('#module-list [data-module-action="view"]').first().evaluate((button) => {
        button.dataset.moduleAction = 'activate';
        button.setAttribute('aria-label', 'Ativar módulo');
    });
    await page.locator('#module-list [data-module-action="activate"]').click();
    expect(await page.evaluate(() => window.__moduleClickCount)).toBe(1);
    await expect.poll(() => activationRequests).toBe(1);
    await expect.poll(() => page.locator('#backoffice-toast-container .fs-toast').count()).toBe(1);
});

test('planos replica o contrato visual, composição e estados do drawer', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/backoffice/planos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'subscription-plans');
    await expect(page.locator('#plan-list tr')).toHaveCount(1);
    await expect(page.locator('#plan-pagination .fs-page-link')).toHaveCount(3);
    await expect(page.locator('#plan-pagination .fs-page-item.is-active')).toContainText('1');
    await expect(page.locator('#plan-table-footer-summary')).toHaveText('Mostrando página 1 de 1 com 1 registros, de um total de 1 páginas.');
    await expect(page.locator('#plan-list [data-plan-action="pause"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="activate"], #plan-list [data-plan-action="archive"], #plan-list [data-plan-action="delete"]')).toHaveCount(0);
    await expect(page.locator('#plan-product-filter, #plan-publication-filter, #plan-featured-filter')).toHaveCount(0);
    await expect(page.locator('table[aria-label="Planos de assinatura"] thead th')).toHaveText(['Plano', 'Produto', 'Valores', 'Status', 'Publicação', 'Assinaturas', 'Ações']);
    const priceContract = await page.locator('#plan-list td[data-label="Valores"]').evaluate((cell) => ({
        lines: cell.querySelectorAll('.plan-price-line').length,
        annualSmaller: Number.parseFloat(getComputedStyle(cell.querySelector('.plan-price-line--annual .plan-price-value')).fontSize) < Number.parseFloat(getComputedStyle(cell.querySelector('.plan-price-line:not(.plan-price-line--annual) .plan-price-value')).fontSize),
    }));
    expect(priceContract).toEqual({ lines: 2, annualSmaller: true });

    await page.locator('#plan-new').click();
    await expect(page.locator('#plan-drawer')).toBeVisible();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'create');
    const createContract = await page.locator('#plan-drawer').evaluate((drawer) => ({
        width: getComputedStyle(drawer).width,
        cards: drawer.querySelectorAll('#plan-form > .fs-card.fs-card-panel').length,
        controlsUseGoogleSans: [...drawer.querySelectorAll('input, select, textarea')].every((control) => getComputedStyle(control).fontFamily.includes('Google Sans')),
        textareaPadding: [...new Set([...drawer.querySelectorAll('textarea')].map((textarea) => getComputedStyle(textarea).padding))],
        segmentIsSelect: drawer.querySelector('#plan-segment')?.tagName === 'SELECT',
        segmentOptions: [...drawer.querySelectorAll('#plan-segment option')].map((option) => option.value),
        descriptionWidths: [...drawer.querySelectorAll('#plan-technical-description, #plan-commercial-content')].map((field) => field.classList.contains('fs-width-600')),
        descriptionMaxLengths: [...drawer.querySelectorAll('#plan-technical-description, #plan-commercial-content')].map((field) => field.getAttribute('maxlength')),
        primaryButton: drawer.querySelector('#plan-form-submit')?.classList.contains('fs-btn-primary'),
    }));
    expect(createContract).toEqual({ width: '450px', cards: 3, controlsUseGoogleSans: true, textareaPadding: ['10px 15px'], segmentIsSelect: true, segmentOptions: ['', 'advocacia'], descriptionWidths: [true, true], descriptionMaxLengths: ['2000', '20000'], primaryButton: true });

    await page.locator('#plan-product').selectOption('PRD_LAW');
    await page.locator('#plan-segment').selectOption('advocacia');
    await page.locator('#plan-composition-open').click();
    await expect(page.locator('#plan-composition-drawer')).toBeVisible();
    await expect(page.locator('#plan-module-options')).toContainText('Gestão de processos');
    await expect(page.locator('#plan-module-options .fs-check-label')).toHaveCount(1);
    const moduleCheckbox = page.locator('#plan-module-options input[type="checkbox"]').first();
    await page.locator('#plan-module-options .plan-module-meta').click();
    await expect(moduleCheckbox).toBeChecked();
    await page.locator('#plan-composition-save').click();
    await expect(page.locator('#plan-composition-drawer')).toBeHidden();
    await expect(page.locator('#plan-composition-summary')).toContainText('1 funcionalidade');
    await expect(page.locator('#plan-suggested-price')).toHaveText('R$ 24,90');
    await expect(page.locator('#plan-annual-price')).toHaveText('R$ 249,00');

    await page.locator('#plan-drawer-close').click();
    await expect(page.getByRole('button', { name: 'Editar plano' })).toHaveCount(0);
    await page.route('**/api/backoffice/plans', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({ response, json: data.map((plan) => ({ ...plan, status: 'inativo', publication_state: 'pausado' })) });
    });
    await page.reload();
    await page.getByRole('button', { name: 'Editar plano' }).click();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'edit');
    await expect(page.locator('#plan-form-submit')).toHaveText('Salvar alterações');
    await expect(page.locator('#plan-code')).toBeDisabled();

    await page.locator('#plan-drawer-close').click();
    await page.getByRole('button', { name: 'Ver detalhes do plano' }).click();
    await expect(page.locator('#plan-drawer')).toHaveAttribute('data-mode', 'view');
    await expect(page.locator('#plan-form')).toBeHidden();
    await expect(page.locator('#plan-view-panel')).toBeVisible();
    await expect(page.locator('#plan-view-panel')).toHaveClass(/fs-offcanvas-body/);
    await expect(page.locator('#plan-view-panel > .fs-card.fs-card-panel')).toHaveCount(3);
    await expect(page.locator('#plan-view-panel')).toContainText('2');
});

test('planos limita os ícones de ciclo de vida aos estados permitidos', async ({ page }) => {
    let status = 'inativo';
    await page.route('**/api/backoffice/plans', async (route) => {
        const response = await route.fetch();
        const plans = await response.json();
        await route.fulfill({ response, json: plans.map((plan) => ({ ...plan, status, publication_state: status === 'arquivado' ? 'arquivado' : 'pausado' })) });
    });
    await page.goto('/backoffice/planos');
    await expect(page.locator('#plan-list [data-plan-action="activate"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="archive"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="pause"], #plan-list [data-plan-action="delete"]')).toHaveCount(0);

    status = 'arquivado';
    await page.reload();
    await expect(page.locator('#plan-list [data-plan-action="delete"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="activate"], #plan-list [data-plan-action="archive"], #plan-list [data-plan-action="pause"]')).toHaveCount(0);

    status = 'ativo';
    await page.reload();
    await expect(page.locator('#plan-list [data-plan-action="publish"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="pause"]')).toHaveCount(1);
    await expect(page.locator('#plan-list [data-plan-action="archive"], #plan-list [data-plan-action="edit"]')).toHaveCount(0);
});
