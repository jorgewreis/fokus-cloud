import { expect, test } from '@playwright/test';

test('campos de busca de planos e usuários permanecem dentro dos cartões no celular', async ({ page }) => {
    for (const width of [320, 375, 768]) {
        await page.setViewportSize({ width, height: 900 });
        for (const [path, id, input] of [
            ['planos', 'subscription-plans', '#plan-search'],
            ['usuarios', 'users', '#users-query'],
        ]) {
            await page.goto(`/backoffice/${path}`);
            await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', id);
            const field = page.locator(input);
            await expect(field).toBeVisible();
            const bounds = await field.evaluate((element) => {
                const fieldRect = element.getBoundingClientRect();
                const cardRect = element.closest('.fs-card').getBoundingClientRect();
                return { left: fieldRect.left, right: fieldRect.right, cardLeft: cardRect.left, cardRight: cardRect.right };
            });
            expect(bounds.left).toBeGreaterThanOrEqual(bounds.cardLeft);
            expect(bounds.right).toBeLessThanOrEqual(bounds.cardRight);
            expect(bounds.left).toBeGreaterThanOrEqual(0);
            expect(bounds.right).toBeLessThanOrEqual(width);
            await field.fill('teste');
            await expect(field).toHaveValue('teste');
        }
    }
});
