import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../..', import.meta.url));
export default defineConfig({
  testDir: '.', testMatch: ['showcase.spec.mjs', 'documentation.spec.mjs', 'visual-coherence.spec.mjs', 'rei54.browser.spec.mjs', 'law-select-adapter.browser.spec.mjs'], fullyParallel: false,
  use: { baseURL: 'http://127.0.0.1:8874', browserName: 'chromium', reducedMotion: 'reduce' },
  webServer: [
    { cwd: root, command: `${process.platform === 'win32' ? 'python' : 'python3'} tools/styles-showcase/serve.py`, url: 'http://127.0.0.1:8874/styles/index.html', reuseExistingServer: false },
    { cwd: root, command: 'node tools/styles-showcase/serve-law-fixture.mjs', url: 'http://127.0.0.1:8765/law-select.html', reuseExistingServer: false },
  ],
  reporter: [['list'], ['html', { outputFolder: 'playwright-report/styles-showcase', open: 'never' }]],
});
