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
