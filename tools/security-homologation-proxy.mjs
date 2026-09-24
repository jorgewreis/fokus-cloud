import { createServer as createHttpsServer } from 'node:https';
import { createServer as createHttpServer, request as httpRequest } from 'node:http';
import { readFileSync } from 'node:fs';

const certificatePath = process.env.SECURITY_PROXY_CERT;
const keyPath = process.env.SECURITY_PROXY_KEY;
if (!certificatePath || !keyPath) {
    throw new Error('SECURITY_PROXY_CERT and SECURITY_PROXY_KEY are required.');
}

const server = createHttpsServer({
    cert: readFileSync(certificatePath),
    key: readFileSync(keyPath),
}, (request, response) => {
    const upstream = httpRequest({
        hostname: '127.0.0.1',
        port: 8000,
        path: request.url,
        method: request.method,
        headers: {
            ...request.headers,
            host: 'localhost:8443',
            'x-forwarded-proto': 'https',
            'x-forwarded-host': 'localhost:8443',
        },
    }, (upstreamResponse) => {
        response.writeHead(upstreamResponse.statusCode ?? 502, upstreamResponse.headers);
        upstreamResponse.pipe(response);
    });

    upstream.on('error', () => {
        if (! response.headersSent) response.writeHead(502);
        response.end();
    });
    request.pipe(upstream);
});

server.listen(8443, '127.0.0.1');
const healthServer = createHttpServer((request, response) => {
    response.writeHead(request.url === '/up' ? 200 : 404, { 'Content-Type': 'text/plain' });
    response.end(request.url === '/up' ? 'ok' : 'not found');
});
healthServer.listen(8444, '127.0.0.1');
for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        healthServer.close();
        server.close(() => process.exit(0));
    });
}
