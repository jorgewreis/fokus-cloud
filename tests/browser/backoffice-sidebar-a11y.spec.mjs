import { test, expect } from '@playwright/test';

test('sidebar inicial respeita hover e mantém ordem inicial de teclado', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.mouse.move(900, 800);
    await page.addInitScript(() => {
        window.__sidebarEvents = [];
        for (const type of ['mouseover', 'focusin', 'pointerdown']) {
            document.addEventListener(type, (event) => {
                if (event.target.closest?.('#sidebar')) window.__sidebarEvents.push({ type, target: event.target.id || event.target.className, trusted: event.isTrusted });
            }, true);
        }
    });
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: {
        id: 'PAD_TEST', name: 'Admin Teste', role: 'superadministrador',
        permissions: ['platform.security.manage', 'platform.catalog.manage', 'platform.catalog.publish', 'platform.users.view', 'platform.subscriptions.manage', 'platform.payments.view'],
    } } }));
    await page.goto('/backoffice/empresas');
    await expect(page.locator('[data-backoffice-page="companies"]').first()).toBeVisible();
    // Chromium on Linux can reset the pointer during navigation and send a
    // trusted mouseover to the sidebar; move it away after navigation too.
    await page.mouse.move(901, 800);
    const initial = await page.evaluate(() => ({
        width: window.innerWidth,
        responsive: matchMedia('(max-width: 1919px)').matches,
        hovered: document.querySelector('#sidebar').matches(':hover'),
        focused: document.activeElement?.tagName,
        sidebarHoverEvent: window.__sidebarEvents.some((event) => event.type === 'mouseover' && event.trusted),
    }));
    expect(initial).toMatchObject({ width: 1440, responsive: true, hovered: false, focused: 'BODY' });
    await expect(page.locator('#sidebar')).toHaveClass(initial.sidebarHoverEvent ? /expanded/ : /single/);
    await page.keyboard.press('Tab');
    await expect(page.locator('#sidebar [data-sidebar-item="platform-dashboard-final"]')).toBeFocused();
    await expect(page.locator('#sidebar [data-sidebar-item="platform-dashboard-final"]')).toHaveCSS('outline-width', '3px');
    await page.keyboard.press('Tab');
    await expect(page.locator('#sidebar [data-sidebar-item="companies"]')).toBeFocused();
});

test('sidebar mantém nomes e foco visível ao navegar por teclado', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    // The sidebar expands on hover; keep the initial pointer outside it so the
    // collapsed state is exercised consistently in Chromium and Edge.
    await page.mouse.move(900, 800);
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: {
        id: 'PAD_TEST', name: 'Admin Teste', role: 'superadministrador',
        permissions: ['platform.security.manage', 'platform.catalog.manage', 'platform.catalog.publish', 'platform.users.view', 'platform.subscriptions.manage', 'platform.payments.view'],
    } } }));
    await page.goto('/backoffice/empresas');
    await expect(page.locator('[data-backoffice-page="companies"]').first()).toBeVisible();
    // An outside click uses the actual document handler to close a sidebar
    // that may have expanded during navigation or pointer initialization.
    await page.mouse.click(900, 850);
    await expect(page.locator('#sidebar')).toHaveClass(/single/);
    const buttons = [
        ['platform-dashboard-final', 'Dashboard'], ['companies', 'Empresas'],
        ['users', 'Usuários'], ['subscriptions', 'Assinaturas'],
        ['pagamentos', 'Pagamentos'], ['vouchers', 'Vouchers'],
        ['audit-overview', 'Auditoria'],
    ];
    for (const [id, name] of buttons) {
        await expect(page.locator(`#sidebar [data-sidebar-item="${id}"]`).first()).toHaveAccessibleName(name);
    }
    await expect(page.locator('#sidebar [data-sidebar-group="catalog"] > button')).toHaveAccessibleName('Catálogo');

    // A pointer click can set the sequential focus starting point inside the
    // page; navigate normally until the Dashboard is reached instead of
    // assuming it will be the first Tab stop.
    for (let attempt = 0; attempt < 40; attempt++) {
        await page.keyboard.press('Tab');
        if (await page.locator('#sidebar [data-sidebar-item="platform-dashboard-final"]').evaluate((element) => element === document.activeElement)) break;
    }
    const focused = page.locator('#sidebar .sidebar-button:focus-visible');
    await expect(focused).toHaveCount(1);
    await expect(focused).toHaveAccessibleName('Dashboard');
    await expect(focused).toHaveCSS('outline-style', 'solid');
    await expect(focused).toHaveCSS('outline-width', '3px');
    await expect(page.locator('#sidebar')).toHaveClass(/sidebar-labels-visible/);
    for (const [id, name] of buttons) {
        await expect(page.locator(`#sidebar [data-sidebar-item="${id}"]`).first()).toHaveAccessibleName(name);
    }
    await page.keyboard.press('Tab');
    await expect(page.locator('#sidebar [data-sidebar-item="companies"]')).toBeFocused();
    await expect(page.locator('#sidebar [data-sidebar-item="companies"]')).toHaveCSS('outline-style', 'solid');
});
