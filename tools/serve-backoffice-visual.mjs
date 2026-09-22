import { createReadStream, existsSync } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { extname, resolve } from 'node:path';

const publicRoot = resolve(import.meta.dirname, '../public');
const port = Number(process.env.BACKOFFICE_VISUAL_PORT || 4177);
const types = { '.css': 'text/css', '.html': 'text/html', '.js': 'application/javascript', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const admin = { id: 'PAD_VISUAL', name: 'Administração Fokus', role: 'superadministrador', permissions: ['platform.security.manage'] };
const products = [{ id: 'PRD_LAW', name: 'Fokus Law', code: 'law', status: 'ativo', plans: [{ id: 'PLN_1', name: 'Essencial' }] }];
const modules = [{ id: 'MOD_1', product_id: 'PRD_LAW', product_name: 'Fokus Law', name: 'Gestão de processos', code: 'processos', module_code: 'processos', status: 'ativo', publication_state: 'publicado', monthly_price: 29.9, segments: ['advocacia'], capabilities: ['Controle de prazos'], capability_codes: ['prazos'], dependencies: [], incompatibilities: [], linked_plans: [], personalizations: [] }];

const json = (response, payload, status = 200) => {
    response.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    response.end(JSON.stringify(payload));
};

createServer(async (request, response) => {
    const url = new URL(request.url, `http://${request.headers.host}`);
    if (url.pathname === '/api/csrf-token') return json(response, { token: 'visual-test-token' });
    if (url.pathname === '/api/backoffice/auth/me') return json(response, { admin });
    if (url.pathname === '/api/backoffice/dashboard') return json(response, { user: admin, alerts: [] });
    if (url.pathname === '/api/backoffice/companies') return json(response, { data: [], meta: { total: 0, current_page: 1, per_page: 25, last_page: 1 }, summary: {} });
    if (url.pathname === '/api/backoffice/catalog/products') return json(response, { products });
    if (url.pathname === '/api/backoffice/catalog') return json(response, { products: [{ ...products[0], modules }], options: { module_codes: [{ code: 'processos', label: 'Processos' }], personalization_types: [{ code: 'usuarios', label: 'Usuários' }] } });
    if (url.pathname.startsWith('/api/backoffice/')) return json(response, {});

    const relative = url.pathname === '/backoffice/' || /^\/backoffice\/(?!assets\/|pages\/)/.test(url.pathname)
        ? 'backoffice/index.html'
        : url.pathname.replace(/^\//, '');
    const file = resolve(publicRoot, relative);
    if (!file.startsWith(publicRoot) || !existsSync(file) || !(await stat(file)).isFile()) {
        response.writeHead(404); response.end(); return;
    }
    response.writeHead(200, { 'content-type': types[extname(file)] || 'application/octet-stream', 'cache-control': 'no-store' });
    createReadStream(file).pipe(response);
}).listen(port, '127.0.0.1', () => console.log(`Backoffice visual server on ${port}`));
