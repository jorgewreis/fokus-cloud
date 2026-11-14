import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { once } from 'node:events';

const script = 'tests/Deployment/interest-real-bfcache.mjs';
const run = async (fixturePort, proxyPort) => {
    const child = spawn(process.execPath, [script, 'same'], {
        env: { PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, FOKUS_INTEREST_BFCACHE_DISPOSABLE: '1', FOKUS_BFCACHE_FIXTURE_PORT: String(fixturePort), FOKUS_BFCACHE_PROXY_PORT: String(proxyPort) },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    let stderr = '';
    child.stderr.on('data', chunk => { stderr += chunk.toString(); });
    const timer = setTimeout(() => child.kill(), 15000);
    try { const [code] = await once(child, 'exit'); return { code, stderr }; } finally { clearTimeout(timer); }
};

for (const collision of ['fixture', 'proxy']) {
    test(`listener alheio na porta ${collision} não é consultado nem aceito`, async () => {
        let hits = 0;
        const alien = createServer((_request, response) => { hits++; response.writeHead(200); response.end('resposta alheia'); });
        await new Promise((resolve, reject) => alien.listen(0, '127.0.0.1', resolve).once('error', reject));
        const occupied = alien.address().port;
        try {
            // The fixture path must fail before a browser is started. The proxy
            // collision must fail before even spawning the fixture.
            const result = await run(collision === 'fixture' ? occupied : 4195, collision === 'proxy' ? occupied : 0);
            assert.notEqual(result.code, 0);
            assert.match(result.stderr, /EADDRINUSE|exited before readiness/);
            assert.equal(hits, 0);
            assert.equal(alien.listening, true);
        } finally { await new Promise(resolve => alien.close(resolve)); }
    });
}

test('fixture protegido recusa requisição sem prova ou com prova incorreta', async () => {
    const probe = createServer();
    await new Promise((resolve, reject) => probe.listen(0, '127.0.0.1', resolve).once('error', reject));
    const port = probe.address().port;
    await new Promise(resolve => probe.close(resolve));
    const proof = 'teste-aleatorio-local';
    const fixture = spawn(process.execPath, ['tools/serve-backoffice-visual.mjs'], {
        env: { PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, BACKOFFICE_VISUAL_PORT: String(port), FOKUS_VISUAL_FIXTURE_PROOF: proof },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    try {
        const [output] = await Promise.race([once(fixture.stdout, 'data'), once(fixture, 'exit').then(() => { throw new Error('Fixture não iniciou'); })]);
        assert.match(output.toString(), new RegExp(`Backoffice visual server on ${port}`));
        const url = `http://127.0.0.1:${port}/backoffice/`;
        for (const headers of [{}, { 'x-fokus-fixture-proof': 'incorreto' }]) {
            const response = await fetch(url, { headers });
            assert.equal(response.status, 403);
            assert.equal(response.headers.get('x-fokus-fixture-proof'), null);
        }
        const response = await fetch(url, { headers: { 'x-fokus-fixture-proof': proof } });
        assert.equal(response.status, 200);
        assert.equal(response.headers.get('x-fokus-fixture-proof'), proof);
    } finally {
        if (fixture.exitCode === null) { fixture.kill(); await once(fixture, 'exit'); }
    }
});
