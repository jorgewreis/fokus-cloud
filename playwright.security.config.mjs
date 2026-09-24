import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    testMatch: 'security-homologation.spec.mjs',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    use: {
        baseURL: 'https://localhost:8443',
        ignoreHTTPSErrors: true,
        colorScheme: 'light',
        locale: 'pt-BR',
        timezoneId: 'America/Bahia',
        trace: 'off',
        video: 'off',
        screenshot: 'off',
    },
    webServer: {
        command: 'node tools/security-homologation-proxy.mjs',
        // Playwright's webServer readiness probe uses Node TLS defaults and
        // cannot trust the temporary self-signed proxy certificate. The app
        // server is already health-checked by the workflow over this upstream.
        url: 'http://127.0.0.1:8000/up',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
});
