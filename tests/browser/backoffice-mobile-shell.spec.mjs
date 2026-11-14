import { test, expect } from '@playwright/test';

for (const width of [320, 375]) {
    test(`o menu móvel não cobre o cabeçalho ou o conteúdo em ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 812 });
        await page.mouse.move(width - 10, 800);
        await page.goto('/backoffice/empresas');
        const sidebar = page.locator('#sidebar');
        const toggle = page.locator('.mobile-sidebar-toggle');
        const title = page.locator('#companies-page-title');
        const user = page.locator('.admin-header-user');
        await expect(sidebar).toHaveClass(/single/);
        await expect(title).toBeVisible();
        const boxes = await Promise.all([toggle.boundingBox(), title.boundingBox(), user.boundingBox()]);
        const [menuBox, titleBox, userBox] = boxes;
        expect(menuBox).not.toBeNull();
        expect(titleBox).not.toBeNull();
        expect(userBox).not.toBeNull();
        expect(menuBox.y + menuBox.height).toBeLessThanOrEqual(titleBox.y);
        expect(menuBox.x + menuBox.width).toBeLessThanOrEqual(userBox.x);
        const avatarBox = await page.locator('.admin-user-avatar').boundingBox();
        expect(avatarBox.width).toBe(36);
        expect(avatarBox.height).toBe(36);
        expect(userBox.x + userBox.width).toBeLessThanOrEqual(width);
        await toggle.focus();
        await expect(toggle).toBeFocused();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        const content = page.locator('.container-content');
        await content.evaluate((element) => { element.scrollTop = 250; });
        await expect.poll(() => content.evaluate((element) => Math.round(element.scrollTop))).toBeGreaterThan(0);
        const afterScroll = await toggle.boundingBox();
        const header = await page.locator('.admin-final-header').boundingBox();
        expect(Math.abs(afterScroll.y - menuBox.y)).toBeLessThan(1);
        expect(header.y).toBeLessThanOrEqual(afterScroll.y);
        expect(header.y + header.height).toBeGreaterThanOrEqual(afterScroll.y + afterScroll.height);
    });
}

for (const width of [320, 375]) {
    for (const [route, inputId] of [['empresas', 'company-query'], ['produtos', 'product-search'], ['modulos', 'module-query']]) {
        test(`a pesquisa de ${route} cabe no filtro em ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 812 });
            await page.mouse.move(width - 10, 800);
            await page.goto(`/backoffice/${route}`);
            const input = page.locator(`#${inputId}`);
            await expect(input).toBeVisible();
            const boxes = await input.evaluate((element) => {
                const rect = (item) => { const r = item.getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width }; };
                return { input: rect(element), group: rect(element.closest('.fs-input-group')), card: rect(element.closest('.fs-card-panel')) };
            });
            expect(boxes.input.width).toBeGreaterThan(100);
            expect(boxes.input.right).toBeLessThanOrEqual(boxes.group.right + 1);
            expect(boxes.group.right).toBeLessThanOrEqual(boxes.card.right + 1);
            expect(boxes.input.right).toBeLessThanOrEqual(width);
            await input.fill('Filtro sintético');
            await expect(input).toHaveValue('Filtro sintético');
        });
    }
}
