import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { fulfillInterestFixtureRoute } from './interest-bfcache-proxy.mjs';

const listen = (server, port = 0) => new Promise((resolve, reject) => server.listen(port, '127.0.0.1', resolve).once('error', reject));
const close = server => new Promise(resolve => server.close(resolve));

test('listener substituto após prontidão nunca é contatado', async () => {
    // The fixture does not bind or contact a socket. An unrelated server may
    // occupy and replace its virtual origin without receiving any request.
    let firstHits = 0, replacementHits = 0;
    const first = createServer((_req, res) => { firstHits++; res.end('primeiro servidor alheio'); });
    await listen(first);
    const port = first.address().port;
    const origin = `http://127.0.0.1:${port}`;
    const request = () => ({
        url: () => `${origin}/backoffice/`, method: () => 'GET', postDataBuffer: () => null,
        allHeaders: async () => ({}), resourceType: () => 'document',
    });
    const fulfilled = [];
    const route = { request, abort: () => { throw new Error('Não deveria abortar fixture'); }, fulfill: value => fulfilled.push(value) };
    try {
        await fulfillInterestFixtureRoute(origin, route);
        assert.equal(fulfilled[0].status, 200);
        assert.match(fulfilled[0].body.toString(), /Fokus Cloud/);
        assert.equal(firstHits, 0);
        await close(first);
        const replacement = createServer((_req, res) => { replacementHits++; res.end('servidor substituto'); });
        await listen(replacement, port);
        try {
            await fulfillInterestFixtureRoute(origin, route);
            assert.equal(fulfilled[1].status, 200);
            assert.match(fulfilled[1].body.toString(), /Fokus Cloud/);
            assert.equal(replacementHits, 0);
            assert.equal(replacement.listening, true);
        } finally { await close(replacement); }
    } finally { if (first.listening) await close(first); }
});

test('requisição para destino externo é abortada sem acesso à rede', async () => {
    let aborted = false, fulfilled = false;
    await fulfillInterestFixtureRoute('http://127.0.0.1:4196', {
        request: () => ({ url: () => 'http://example.invalid/' }),
        abort: () => { aborted = true; }, fulfill: () => { fulfilled = true; },
    });
    assert.equal(aborted, true);
    assert.equal(fulfilled, false);
});

test('navegador não consulta listener substituído após carregar fixture', { skip: !process.env.FOKUS_TEST_BROWSER }, async () => {
    const { chromium } = await import('@playwright/test');
    let firstHits = 0, replacementHits = 0;
    const first = createServer((_req, res) => { firstHits++; res.end('primeiro servidor alheio'); });
    await listen(first);
    const port = first.address().port;
    const origin = `http://127.0.0.1:${port}`;
    const browser = await chromium.launch({ headless: true, executablePath: process.env.FOKUS_TEST_BROWSER });
    let replacement;
    try {
        const context = await browser.newContext({ serviceWorkers: 'block' });
        const page = await context.newPage();
        await page.route('**/*', route => fulfillInterestFixtureRoute(origin, route));
        await page.goto(`${origin}/backoffice/`);
        await page.locator('#sidebar').waitFor();
        assert.equal(firstHits, 0);
        await close(first);
        replacement = createServer((_req, res) => { replacementHits++; res.end('servidor substituto'); });
        await listen(replacement, port);
        await page.reload();
        await page.locator('#sidebar').waitFor();
        assert.equal(replacementHits, 0);
        assert.equal(replacement.listening, true);
    } finally {
        await browser.close();
        if (first.listening) await close(first);
        if (replacement?.listening) await close(replacement);
    }
});

test('contexto bloqueia registro de service worker sintético', { skip: !process.env.FOKUS_TEST_BROWSER, timeout: 15000 }, async () => {
    const { chromium } = await import('@playwright/test');
    const browser = await chromium.launch({ headless: true, executablePath: process.env.FOKUS_TEST_BROWSER });
    try {
        const context = await browser.newContext({ serviceWorkers: 'block' });
        const page = await context.newPage();
        let workerRequests = 0;
        await page.route('**/*', route => {
            if (route.request().url().endsWith('/fake-sw.js')) workerRequests++;
            return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Fixture isolado</title>' });
        });
        await page.goto('http://127.0.0.1:4196/fixture');
        const result = await page.evaluate(async () => {
            const registration = navigator.serviceWorker.register('/fake-sw.js').then(value => value ? 'registered' : 'blocked', () => 'rejected');
            const outcome = await Promise.race([registration, new Promise(resolve => setTimeout(() => resolve('blocked'), 500))]);
            return { outcome, count: (await navigator.serviceWorker.getRegistrations()).length, controlled: Boolean(navigator.serviceWorker.controller) };
        });
        assert.notEqual(result.outcome, 'registered');
        assert.equal(result.count, 0);
        assert.equal(result.controlled, false);
        assert.equal(workerRequests, 0);
        await context.close();
    } finally { await browser.close(); }
});
