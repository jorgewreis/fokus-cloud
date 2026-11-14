import { test, expect } from '@playwright/test';

test('sidebar mantém nomes e foco visível ao navegar por teclado', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: {
        id: 'PAD_TEST', name: 'Admin Teste', role: 'superadministrador',
        permissions: ['platform.security.manage', 'platform.catalog.manage', 'platform.catalog.publish', 'platform.users.view', 'platform.subscriptions.manage', 'platform.payments.view'],
    } } }));
    await page.goto('/backoffice/empresas');
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

    await page.keyboard.press('Tab');
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
