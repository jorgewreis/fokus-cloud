import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../..', import.meta.url));
export default defineConfig({
  testDir: '.', testMatch: 'showcase.spec.mjs', fullyParallel: false,
  use: { baseURL: 'http://127.0.0.1:8874', browserName: 'chromium', reducedMotion: 'reduce' },
  webServer: { cwd: root, command: 'python3 -m http.server 8874 --directory public', url: 'http://127.0.0.1:8874/styles/index.html', reuseExistingServer: false },
  reporter: [['list'], ['html', { outputFolder: 'playwright-report/styles-showcase', open: 'never' }]],
});
