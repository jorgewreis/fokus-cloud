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
        };
    });

    expect(drawerContract).toEqual({
        width: '450px',
        cardsFitContent: true,
        cardBodySpacing: true,
        rowSpacing: true,
        labelsHaveSpans: true,
        labelsHaveSpacing: true,
        labelsFitContent: true,
        columnsDoNotGrow: true,
        controlsUseGoogleSans: true,
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
        return {
            width: getComputedStyle(drawer).width,
            cards: drawer.querySelectorAll('#product-form > .fs-card.fs-card-panel').length,
            labelsHaveSpans: labels.every((label) => label.firstElementChild?.matches('span.fs-u-ml-2')),
            labelsHaveSpacing: labels.every((label) => label.classList.contains('fs-u-mt-3')),
            cardBodySpacing: [...drawer.querySelectorAll('#product-form .fs-card-panel > .fs-card-body')]
                .every((body) => body.matches('.fs-u-mx-2.fs-u-mt-2.fs-u-mb-3')),
            controlsUseGoogleSans: controls.every((control) => getComputedStyle(control).fontFamily.includes('Google Sans')),
        };
    });
    expect(createContract).toEqual({
        width: '450px',
        cards: 2,
        labelsHaveSpans: true,
        labelsHaveSpacing: true,
        cardBodySpacing: true,
        controlsUseGoogleSans: true,
    });

    await page.locator('#product-drawer-close').click();
    await expect(page.locator('#product-drawer')).toBeHidden();
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
