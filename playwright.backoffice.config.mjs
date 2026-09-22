import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    snapshotPathTemplate: '{testDir}/{testFilePath}-snapshots/{arg}{ext}',
    use: { baseURL: 'http://127.0.0.1:4177', colorScheme: 'light', locale: 'pt-BR', timezoneId: 'America/Bahia' },
    webServer: { command: 'node tools/serve-backoffice-visual.mjs', url: 'http://127.0.0.1:4177/backoffice/', reuseExistingServer: !process.env.CI },
});
