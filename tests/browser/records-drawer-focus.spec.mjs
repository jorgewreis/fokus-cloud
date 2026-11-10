import { test, expect } from '@playwright/test';

test('drawer waits for delayed visibility before assigning focus and cancels on close', async ({ page }) => {
    await page.goto('/backoffice/assinaturas');
    await page.addStyleTag({ content: '#subscription-drawer { transition: visibility 0s linear .25s, transform .3s ease; }' });
    const trigger = page.getByRole('button', { name: /Ver detalhes da assinatura/ }).first();
    const drawer = page.locator('#subscription-drawer');
    await trigger.click();
    await expect(drawer).toHaveClass(/is-open/);
    await expect.poll(() => page.evaluate(() => document.activeElement?.closest('#subscription-drawer')?.id || '')).toBe('subscription-drawer');
    await page.keyboard.press('Escape');
    await expect(drawer).not.toHaveClass(/is-open/);
    await expect(trigger).toBeFocused();
    await trigger.click();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(350); // Deliberately cross the synthetic visibility delay after close.
    await expect(drawer).not.toHaveClass(/is-open/);
    await expect(trigger).toBeFocused();
});
