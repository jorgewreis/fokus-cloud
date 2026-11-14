import { spawn } from 'node:child_process';
import { createServer, request } from 'node:http';
import { once } from 'node:events';
import { randomBytes } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';

// Disposable static fixture only. No application server, real sessions or production data.
if (process.env.FOKUS_INTEREST_BFCACHE_DISPOSABLE !== '1') throw new Error('Set FOKUS_INTEREST_BFCACHE_DISPOSABLE=1 for the local synthetic test.');
const mode = process.argv[2] || 'same';
if (!['same', 'changed', 'denied', 'double', 'double-navigation'].includes(mode)) throw new Error('Expected same, changed, denied, double or double-navigation.');
const fixturePort = Number(process.env.FOKUS_BFCACHE_FIXTURE_PORT || 4195);
const proxyPort = Number(process.env.FOKUS_BFCACHE_PROXY_PORT || 4196);
if (!Number.isInteger(fixturePort) || fixturePort < 1024 || fixturePort > 65535 || !Number.isInteger(proxyPort) || (proxyPort !== 0 && (proxyPort < 1024 || proxyPort > 65535 || fixturePort === proxyPort))) throw new Error('Use a fixture port and a distinct unprivileged proxy port or 0.');
// The proxy binds first and the owned fixture must confirm its own listen
// callback. A random per-run proof is checked on every proxied response.
const fixtureProof = randomBytes(32).toString('hex');
let fixture;
const proxy = createServer((req, res) => {
    if (req.url === '/bfcache-test-away') {
        res.writeHead(200, { 'content-type': 'text/html', 'cache-control': 'no-store' });
        res.end('<!doctype html><title>Destino sintético</title>');
        return;
    }
    const upstream = request({ hostname: '127.0.0.1', port: fixturePort, path: req.url, method: req.method, headers: { ...req.headers, 'x-fokus-fixture-proof': fixtureProof } }, response => {
        if (response.headers['x-fokus-fixture-proof'] !== fixtureProof) {
            response.resume();
            res.writeHead(502);
            res.end('Unverified synthetic fixture');
            return;
        }
        const headers = { ...response.headers };
        delete headers['x-fokus-fixture-proof'];
        // The fixture defaults to no-store. This override applies only to its
        // synthetic top-level document and never changes production headers.
        if (req.headers['sec-fetch-dest'] === 'document') headers['cache-control'] = 'private, max-age=60';
        res.writeHead(response.statusCode, headers);
        response.pipe(res);
    });
    upstream.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end(); });
    req.pipe(upstream);
});
let browser;
try {
    await new Promise((resolve, reject) => proxy.listen(proxyPort, '127.0.0.1', resolve).once('error', reject));
    await new Promise((resolve, reject) => {
        fixture = spawn(process.execPath, ['tools/serve-backoffice-visual.mjs'], {
            env: { PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, BACKOFFICE_VISUAL_PORT: String(fixturePort), FOKUS_VISUAL_FIXTURE_PROOF: fixtureProof },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        let output = '', errors = '';
        const timer = setTimeout(() => finish(new Error('Synthetic fixture did not confirm readiness.')), 10000);
        const finish = error => {
            clearTimeout(timer);
            fixture.stdout.removeListener('data', onOutput);
            fixture.stderr.removeListener('data', onErrorOutput);
            fixture.stdout.resume();
            fixture.stderr.resume();
            fixture.removeListener('error', onError);
            fixture.removeListener('exit', onExit);
            error ? reject(error) : resolve();
        };
        const onOutput = chunk => {
            output += chunk.toString();
            if (output.split(/\r?\n/).includes(`Backoffice visual server on ${fixturePort}`)) finish();
        };
        const onErrorOutput = chunk => { errors += chunk.toString(); };
        const onError = error => finish(error);
        const onExit = code => finish(new Error(`Synthetic fixture exited before readiness (${code}): ${errors}`));
        fixture.stdout.on('data', onOutput);
        fixture.stderr.on('data', onErrorOutput);
        fixture.once('error', onError);
        fixture.once('exit', onExit);
    });
    if (fixture.exitCode !== null || fixture.killed) throw new Error('Synthetic fixture stopped after readiness.');
    const proofResponse = await fetch(`http://127.0.0.1:${fixturePort}/backoffice/`, { headers: { 'x-fokus-fixture-proof': fixtureProof } });
    if (!proofResponse.ok || proofResponse.headers.get('x-fokus-fixture-proof') !== fixtureProof) throw new Error('Synthetic fixture identity could not be verified.');
    await proofResponse.arrayBuffer();
    browser = await chromium.launch({
        headless: true,
        // Playwright disables BFCache by default. This test requires real restoration.
        ignoreDefaultArgs: ['--disable-back-forward-cache'],
        ...(process.env.FOKUS_TEST_BROWSER ? { executablePath: process.env.FOKUS_TEST_BROWSER } : {}),
        args: ['--enable-features=BackForwardCache'],
    });
    const page = await browser.newPage();
    const origin = `http://127.0.0.1:${proxy.address().port}`;
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    await page.addInitScript(() => {
        window.addEventListener('pageshow', event => {
            sessionStorage.setItem('fokus-real-bfcache-persisted', String(event.persisted));
            if (event.persisted) sessionStorage.setItem('fokus-real-bfcache-count', String(Number(sessionStorage.getItem('fokus-real-bfcache-count') || 0) + 1));
        });
    });
    let authCount = 0, dataCount = 0, releaseAuth, authStarted;
    const gate = new Promise(resolve => { releaseAuth = resolve; });
    const started = new Promise(resolve => { authStarted = resolve; });
    await page.route('**/api/backoffice/auth/me', async route => {
        authCount++;
        if (authCount > 1 && mode !== 'double-navigation') { authStarted(); await gate; }
        if (mode === 'denied' && authCount === 2) return route.fulfill({ status: 401, json: { message: 'Negado em teste' } });
        return route.fulfill({ json: { admin: { id: mode === 'changed' && authCount > 1 ? 'PAD_B' : 'PAD_A', name: 'Teste', role: 'superadministrador', permissions: ['platform.product_interests.manage'] } } });
    });
    await page.route('**/api/backoffice/product-interests-options?*', route => route.fulfill({ json: { products: [], meta: { current_page: 1, has_more: false }, historical_index_ready: true } }));
    await page.route('**/api/backoffice/product-interests?*', route => { dataCount++; return route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } } }); });
    const documentResponse = await page.goto(`${origin}/backoffice/interesses`);
    assert.equal(documentResponse.headers()['x-fokus-fixture-proof'], undefined);
    await page.locator('#interest-list').waitFor();
    if (mode === 'double-navigation') await page.evaluate(() => {
        const router = window.__backofficeRouter;
        const navigate = router.navigate.bind(router);
        let first = true;
        router.navigate = async (...args) => {
            const result = await navigate(...args);
            if (first) { first = false; window.__testNavigateHeld = true; await new Promise(resolve => { window.__testNavigateRelease = resolve; }); }
            return result;
        };
    });
    await page.goto(`${origin}/bfcache-test-away`);
    // BFCache does not fire a fresh load event; wait for history navigation commit.
    await page.goBack({ waitUntil: 'commit' });
    await page.waitForFunction(() => sessionStorage.getItem('fokus-real-bfcache-persisted') === 'true', null, { timeout: 10000 });
    if (mode === 'double-navigation') {
        await page.waitForFunction(() => window.__testNavigateHeld === true, null, { timeout: 10000 });
        assert.equal(await page.evaluate(() => document.documentElement.style.visibility), 'hidden');
        await page.goto(`${origin}/bfcache-test-away`);
        const reload = page.waitForRequest(request => request.isNavigationRequest() && request.url() === `${origin}/backoffice/interesses`);
        await page.goBack({ waitUntil: 'commit' });
        await reload;
        await page.waitForLoadState('domcontentloaded');
        assert.equal(Number(await page.evaluate(() => sessionStorage.getItem('fokus-real-bfcache-count'))), 2);
        console.log(JSON.stringify({ mode, restored: true, persistedCount: 2, hiddenDuringFirstNavigation: true, reloadRequested: true }));
    } else {
    await started;
    assert.equal(await page.evaluate(() => document.documentElement.style.visibility), 'hidden');
    assert.equal(dataCount, 1);
    if (mode === 'double') {
        await page.goto(`${origin}/bfcache-test-away`);
        await page.goBack({ waitUntil: 'commit' });
        await page.waitForFunction(() => Number(sessionStorage.getItem('fokus-real-bfcache-count')) >= 2, null, { timeout: 10000 });
        assert.equal(await page.evaluate(() => document.documentElement.style.visibility), 'hidden');
        assert.equal(authCount, 3);
        await page.waitForFunction(() => sessionStorage.getItem('fokus-real-bfcache-persisted') === 'true');
        releaseAuth();
        await page.waitForFunction(() => document.documentElement.style.visibility !== 'hidden', null, { timeout: 15000 });
        for (let attempt = 0; attempt < 50 && dataCount < 2; attempt++) await new Promise(resolve => setTimeout(resolve, 100));
        assert.equal(dataCount, 2);
        console.log(JSON.stringify({ mode, restored: true, persistedCount: 2, hiddenBeforeVerification: true, authCount, dataCount }));
    } else if (mode === 'changed' || mode === 'denied') {
        const reload = page.waitForRequest(request => request.isNavigationRequest() && request.url() === `${origin}/backoffice/interesses`);
        releaseAuth();
        await reload;
        console.log(JSON.stringify({ mode, restored: true, hiddenBeforeVerification: true, dataCountBeforeRevalidation: dataCount, reloadRequested: true }));
    } else {
        releaseAuth();
        await page.waitForFunction(() => document.documentElement.style.visibility !== 'hidden', null, { timeout: 15000 });
        assert.equal(authCount, 2);
        for (let attempt = 0; attempt < 50 && dataCount < 2; attempt++) await new Promise(resolve => setTimeout(resolve, 100));
        assert.equal(dataCount, 2);
        console.log(JSON.stringify({ mode, restored: true, hiddenBeforeVerification: true, authCount, dataCount }));
    }
    }
} finally {
    await browser?.close();
    proxy.closeAllConnections?.();
    await new Promise(resolve => proxy.close(resolve));
    if (fixture && fixture.exitCode === null) {
        fixture.kill();
        await Promise.race([once(fixture, 'exit'), new Promise(resolve => setTimeout(resolve, 3000))]);
    }
}
