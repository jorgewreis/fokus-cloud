import { Readable, Writable } from 'node:stream';
import { once } from 'node:events';
import { handleBackofficeVisualRequest } from '../../tools/serve-backoffice-visual.mjs';

// The browser receives synthetic responses directly from an in-process handler.
// No listener, socket, child process or reusable proof is involved.
export async function fulfillInterestFixtureRoute(origin, route) {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.origin !== origin) return route.abort();
    if (requestUrl.pathname === '/bfcache-test-away') return route.fulfill({ status: 200, contentType: 'text/html', headers: { 'cache-control': 'no-store' }, body: '<!doctype html><title>Destino sintético</title>' });
    const source = route.request();
    const req = Readable.from(source.postDataBuffer() ? [source.postDataBuffer()] : []);
    req.url = `${requestUrl.pathname}${requestUrl.search}`;
    req.method = source.method();
    req.headers = { ...await source.allHeaders(), host: requestUrl.host };
    const chunks = [];
    const res = new Writable({ write(chunk, _encoding, callback) { chunks.push(Buffer.from(chunk)); callback(); } });
    res.statusCode = 200;
    res.responseHeaders = {};
    res.writeHead = function(status, headers = {}) {
        this.statusCode = status;
        this.responseHeaders = { ...headers };
        // Only the synthetic document gets cacheable headers. Production is untouched.
        if (source.resourceType() === 'document') this.responseHeaders['cache-control'] = 'private, max-age=60';
        return this;
    };
    await handleBackofficeVisualRequest(req, res);
    if (!res.writableFinished) await once(res, 'finish');
    return route.fulfill({ status: res.statusCode, headers: res.responseHeaders, body: Buffer.concat(chunks) });
}
