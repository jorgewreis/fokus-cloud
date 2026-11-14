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
        const page = await browser.newPage();
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
