// Synthetic laboratory only: no actual API or production state is contacted.
// The proxy intentionally changes HTML cache headers and, in the candidate
// pass, a single JavaScript response. This does not modify the checkout.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { setTimeout as delay } from 'node:timers/promises';

if (process.env.COMPANY_BFCACHE_DISPOSABLE !== '1' || !process.env.COMPANY_BFCACHE_LAB_ROOT) {
    throw new Error('Set COMPANY_BFCACHE_DISPOSABLE=1 and COMPANY_BFCACHE_LAB_ROOT to the isolated checkout.');
}
const root = path.resolve(process.env.COMPANY_BFCACHE_LAB_ROOT);
const require = createRequire(path.join(process.env.COMPANY_PLAYWRIGHT_ROOT || process.cwd(), 'package.json'));
const { chromium } = require('playwright');
const file = path.join(root, 'public/backoffice/assets/js/companies-page.js');
const source = await readFile(file, 'utf8');
const anchor = 'window.addEventListener("pagehide", resetOperationState);';
assert.equal(source.split(anchor).length, 2, 'Expected exactly one baseline handler; do not silently test a different source.');
const sourceHash = createHash('sha256').update(source).digest('hex');

async function waitUntil(predicate, label) {
    for (let i = 0; i < 150; i++) {
        if (predicate()) return;
        await delay(100);
    }
    throw new Error(`Timed out waiting for ${label}`);
}

const upstreamPort = Number(process.env.COMPANY_BFCACHE_UPSTREAM_PORT || 4194);
const upstream = spawn(process.execPath, [path.join(root, 'tools/serve-backoffice-visual.mjs')], {
    cwd: root,
    env: { ...process.env, BACKOFFICE_VISUAL_PORT: String(upstreamPort) },
    stdio: ['ignore', 'pipe', 'pipe'],
});
let upstreamReady = false;
let upstreamError = '';
upstream.stdout.on('data', (data) => { if (String(data).includes(`Backoffice visual server on ${upstreamPort}`)) upstreamReady = true; });
upstream.stderr.on('data', (data) => { upstreamError += String(data); });
let proxy, browser;
try {
    await waitUntil(() => upstreamReady || upstream.exitCode !== null, 'exclusive synthetic upstream');
    assert.equal(upstream.exitCode, null, `Synthetic upstream exited: ${upstreamError}`);
    let held = [];
    let candidate = false;
    proxy = http.createServer((req, res) => {
        if (req.url === '/__release') {
            for (const pending of held.splice(0)) { pending.writeHead(201, { 'content-type': 'application/json' }); pending.end('{}'); }
            res.end('ok');
            return;
        }
        if (req.url === '/api/backoffice/companies' && req.method === 'POST') { req.resume(); held.push(res); return; }
        const up = http.request({ hostname: '127.0.0.1', port: upstreamPort, path: req.url, method: req.method, headers: { ...req.headers, host: `127.0.0.1:${upstreamPort}` } }, (response) => {
            const headers = { ...response.headers };
            if ((headers['content-type'] || '').includes('text/html')) headers['cache-control'] = 'private, max-age=0';
            if (candidate && req.url.startsWith('/backoffice/assets/js/companies-page.js?')) {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () => {
                    const original = Buffer.concat(chunks).toString();
                    assert.equal(original.split(anchor).length, 2);
                    const body = original.replace(anchor, 'window.addEventListener("pagehide", (event) => { if (!event.persisted) resetOperationState(); });');
                    delete headers['content-length'];
                    res.writeHead(response.statusCode, headers); res.end(body);
                });
                return;
            }
            res.writeHead(response.statusCode, headers); response.pipe(res);
        });
        up.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end(); });
        req.pipe(up);
    });
    await new Promise((resolve) => proxy.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${proxy.address().port}`;
    browser = await chromium.launch({
        headless: true,
        ...(process.env.COMPANY_BFCACHE_BROWSER ? { executablePath: process.env.COMPANY_BFCACHE_BROWSER } : {}),
        ignoreDefaultArgs: ['--disable-back-forward-cache'],
        args: ['--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost', '--disable-background-networking', '--enable-features=BackForwardCache'],
    });
    const results = [];
    for (candidate of [false, true]) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        await context.addInitScript(() => {
            window.__auditMarker = Math.random().toString(36);
            for (const name of ['pagehide', 'pageshow']) window.addEventListener(name, (event) => {
                const events = JSON.parse(sessionStorage.getItem('audit-bfcache-events') || '[]');
                events.push({ name, persisted: event.persisted, marker: window.__auditMarker, path: location.pathname });
                sessionStorage.setItem('audit-bfcache-events', JSON.stringify(events));
            });
        });
        const page = await context.newPage();
        let posts = 0;
        page.on('request', (request) => { if (request.method() === 'POST' && request.url().endsWith('/api/backoffice/companies')) posts++; });
        await page.goto(`${origin}/backoffice/empresas`);
        await page.locator('[data-backoffice-mount]:not([inert])').waitFor({ state: 'attached' });
        await page.locator('#company-create').click();
        for (const [id, value] of [['company-document-number', '11222333000181'], ['company-legal-name', 'Empresa Exemplo'], ['company-admin-name', 'Admin Exemplo'], ['company-admin-cpf', '52998224725'], ['company-admin-email', 'exemplo@example.test'], ['company-admin-password', 'SenhaExemplo123!']]) await page.locator(`#${id}`).fill(value);
        await page.locator('#company-form-submit').click();
        await waitUntil(() => held.length === 1, 'first synthetic POST');
        const before = await page.evaluate(() => window.__auditMarker);
        await page.goto(`${origin}/backoffice/ativar`);
        await page.goBack({ waitUntil: 'commit' });
        await page.locator('#company-create').waitFor({ state: 'visible' });
        const restored = await page.evaluate(() => ({ marker: window.__auditMarker, events: JSON.parse(sessionStorage.getItem('audit-bfcache-events') || '[]') }));
        assert.equal(restored.marker, before, 'Browser did not restore the same document from BFCache.');
        assert(restored.events.some((event) => event.name === 'pagehide' && event.persisted));
        assert(restored.events.some((event) => event.name === 'pageshow' && event.persisted));
        await page.request.get(`${origin}/__release`);
        if (candidate) {
            await page.waitForFunction(() => document.querySelector('#company-drawer')?.getAttribute('aria-hidden') === 'true');
            assert.equal(posts, 1);
            results.push({ candidate, persisted: true, posts, outcome: 'drawer closed after mocked 201' });
        } else {
            await page.waitForFunction(() => !document.querySelector('#company-form-submit')?.disabled);
            assert.equal(await page.locator('#company-operation-drawer-status').isHidden(), true);
            await page.locator('#company-form-submit').click();
            await waitUntil(() => held.length === 1, 'repeated synthetic POST');
            assert.equal(posts, 2);
            results.push({ candidate, persisted: true, posts, outcome: 'no notice; repeated POST observed' });
            await page.request.get(`${origin}/__release`);
        }
        await context.close();
    }
    console.log(JSON.stringify({ sourceHash, browser: browser.version(), results }));
} finally {
    if (browser) await browser.close();
    if (proxy) await new Promise((resolve) => proxy.close(resolve));
    upstream.kill();
}
