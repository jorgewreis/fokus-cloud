import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

async function expectModalCycle(page, { trigger, dialog, name, description, open, expectReturnFocus = true }) {
    await open();
    await expect(dialog).toHaveClass(/is-open/);
    await expect(dialog).toHaveAttribute('role', 'dialog');
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect(dialog).toHaveAttribute('aria-describedby', description);
    await expect(page.getByRole('dialog', { name })).toBeVisible();
    const selector = `#${await dialog.getAttribute('id')}`;
    await expect.poll(() => page.evaluate((value) => document.querySelector(value)?.contains(document.activeElement), selector)).toBe(true);
    await page.keyboard.press('Escape');
    await expect(dialog).not.toHaveClass(/is-open/);
    if (expectReturnFocus) await expect(trigger).toBeFocused();
}

test('modais de Pagamentos e Componentes expõem contexto acessível', async ({ page }) => {
    await page.route('**/api/backoffice/auth/me', (route) => route.fulfill({ json: { admin: {
        id: 'PAD_TEST', name: 'Admin Teste', role: 'superadministrador',
        permissions: ['platform.security.manage', 'platform.payments.view'],
    } } }));
    await page.goto('/backoffice/pagamentos');
    await expect(page.locator('#billing-action-trigger')).toBeAttached();
    await expectModalCycle(page, {
        trigger: page.locator('#billing-action-trigger'),
        dialog: page.locator('#billing-action-modal'),
        name: 'Confirmar ação',
        description: 'billing-action-description',
        // The production flow opens this modal from an action inside a drawer;
        // this synthetic route invokes its hidden technical trigger directly.
        expectReturnFocus: false,
        open: () => page.locator('#billing-action-trigger').evaluate((element) => element.click()),
    });

    await page.goto('/backoffice/componentes');
    await expectModalCycle(page, {
        trigger: page.locator('[data-fs-target="#component-modal"]'),
        dialog: page.locator('#component-modal'),
        name: 'Confirmar ação',
        description: 'component-modal-description',
        open: () => page.locator('[data-fs-target="#component-modal"]').click(),
    });
});

test('convite de administrador declara e anuncia o diálogo', async ({ page }) => {
    await page.goto('/backoffice/');
    await page.addScriptTag({ url: '/assets/js/fokus.min.js' });
    await page.evaluate(() => {
        window.FokusApi = { request: async (path) => path === '/backoffice/auth/me'
            ? { admin: { permissions: ['platform.security.manage'] } }
            : { admins: [] } };
    });
    const root = resolve(fileURLToPath(new URL('../..', import.meta.url)));
    const markup = await readFile(resolve(root, 'public/backoffice/pages/security.html'), 'utf8');
    await page.setContent(markup);

    await expectModalCycle(page, {
        trigger: page.locator('#invite-admin'),
        dialog: page.locator('#invite-dialog'),
        name: 'Convidar administrador',
        description: 'invite-dialog-description',
        open: () => page.locator('#invite-admin').click(),
    });
});
