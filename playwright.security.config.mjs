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
        // Probe the proxy's separate HTTP health port. Node cannot trust the
        // temporary self-signed certificate used by the browser-facing port.
        url: 'http://127.0.0.1:8444/up',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
});
