import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';
import { fulfillInterestFixtureRoute } from './interest-bfcache-proxy.mjs';

// Disposable static fixture only. No application server, real sessions or production data.
if (process.env.FOKUS_INTEREST_BFCACHE_DISPOSABLE !== '1') throw new Error('Set FOKUS_INTEREST_BFCACHE_DISPOSABLE=1 for the local synthetic test.');
const mode = process.argv[2] || 'same';
if (!['same', 'changed', 'denied', 'double', 'double-navigation'].includes(mode)) throw new Error('Expected same, changed, denied, double or double-navigation.');
const origin = 'http://127.0.0.1:4196';
let browser;
try {
    browser = await chromium.launch({
        headless: true,
        // Playwright disables BFCache by default. This test requires real restoration.
        ignoreDefaultArgs: ['--disable-back-forward-cache'],
        ...(process.env.FOKUS_TEST_BROWSER ? { executablePath: process.env.FOKUS_TEST_BROWSER } : {}),
        args: ['--enable-features=BackForwardCache'],
    });
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const page = await context.newPage();
    const cdp = await page.context().newCDPSession(page); await cdp.send('Page.enable'); cdp.on('Page.backForwardCacheNotUsed', event => console.log('not restored', JSON.stringify(event.notRestoredExplanations)));
    await page.route('**/*', route => fulfillInterestFixtureRoute(origin, route));
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
    await page.goto(`${origin}/backoffice/interesses`);
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
}
