import { test, expect } from '@playwright/test';

test('placeholder da busca mantém opacidade integral em larguras estreita e ampla', async ({ page }) => {
    for (const width of [320, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/backoffice/empresas');
        const input = page.locator('#company-query');
        await expect(input).toBeVisible();
        const styles = await input.evaluate((element) => {
            const placeholder = getComputedStyle(element, '::placeholder');
            return { color: placeholder.color, opacity: Number(placeholder.opacity), background: getComputedStyle(element).backgroundColor };
        });
        expect(styles.opacity).toBe(1);
        expect(styles.color).not.toBe('rgba(0, 0, 0, 0)');
    }
});
