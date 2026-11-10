import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../..', import.meta.url));
export default defineConfig({
  testDir: '.', testMatch: ['showcase.spec.mjs', 'documentation.spec.mjs', 'visual-coherence.spec.mjs'], fullyParallel: false,
  use: { baseURL: 'http://127.0.0.1:8874', browserName: 'chromium', reducedMotion: 'reduce' },
  webServer: { cwd: root, command: 'python3 tools/styles-showcase/serve.py', url: 'http://127.0.0.1:8874/styles/index.html', reuseExistingServer: false },
  reporter: [['list'], ['html', { outputFolder: 'playwright-report/styles-showcase', open: 'never' }]],
});
