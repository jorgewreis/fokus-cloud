import { test, expect } from '@playwright/test';

test('sidebar recolhida após saída do ponteiro mantém ordem inicial de teclado', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.clock.install();
    await page.mouse.move(900, 800);
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: {
        id: 'PAD_TEST', name: 'Admin Teste', role: 'superadministrador',
        permissions: ['platform.security.manage', 'platform.catalog.manage', 'platform.catalog.publish', 'platform.users.view', 'platform.subscriptions.manage', 'platform.payments.view'],
    } } }));
    await page.goto('/backoffice/empresas');
    await expect(page.locator('[data-backoffice-page="companies"]').first()).toBeVisible();
    // Chromium on Linux can reset the pointer during navigation and send a
    // trusted mouseover to the sidebar; move it away after navigation too.
    await page.mouse.move(901, 800);
    await expect(page.locator('#sidebar')).not.toHaveCSS('display', 'none');
    // Advance the existing 60-second idle timer and its collapse transition;
    // this exercises the real mouseleave handler without altering its classes.
    await page.clock.fastForward(60_000);
    await page.clock.fastForward(600);
    await expect(page.locator('#sidebar')).toHaveClass(/single/);
    await expect(page.locator('#sidebar')).not.toHaveClass(/expanded/);
    expect(await page.locator('#sidebar').evaluate((element) => element.matches(':hover'))).toBe(false);
    await page.keyboard.press('Tab');
    await expect(page.locator('#sidebar [data-sidebar-item="platform-dashboard-final"]')).toBeFocused();
    await expect(page.locator('#sidebar [data-sidebar-item="platform-dashboard-final"]')).toHaveCSS('outline-width', '3px');
    await page.keyboard.press('Tab');
    await expect(page.locator('#sidebar [data-sidebar-item="companies"]')).toBeFocused();
});

test('sidebar expande no hover e recolhe após saída do ponteiro', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.clock.install();
    await page.mouse.move(900, 800);
    await page.goto('/backoffice/empresas');
    await expect(page.locator('[data-backoffice-page="companies"]').first()).toBeVisible();
    await page.mouse.move(900, 800);
    await page.locator('#sidebar [data-sidebar-item="companies"]').hover();
    await expect(page.locator('#sidebar')).toHaveClass(/expanded/);
    await page.mouse.move(900, 800);
    await page.clock.fastForward(60_000);
    await page.clock.fastForward(600);
    await expect(page.locator('#sidebar')).toHaveClass(/single/);
    await expect(page.locator('#sidebar')).not.toHaveClass(/expanded/);
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
