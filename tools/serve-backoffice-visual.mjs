import { createReadStream, existsSync } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { extname, resolve } from 'node:path';

const publicRoot = resolve(import.meta.dirname, '../public');
const port = Number(process.env.BACKOFFICE_VISUAL_PORT || 4177);
const types = { '.css': 'text/css', '.html': 'text/html', '.js': 'application/javascript', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const admin = { id: 'PAD_VISUAL', name: 'Administração Fokus', role: 'superadministrador', permissions: ['platform.security.manage', 'platform.catalog.publish', 'platform.users.view'] };
const products = [{ id: 'PRD_LAW', name: 'Fokus Law', code: 'law', status: 'ativo', plans: [{ id: 'PLN_1', name: 'Essencial' }] }];
const modules = [{ id: 'MOD_1', product_id: 'PRD_LAW', product_name: 'Fokus Law', name: 'Gestão de processos', code: 'processos', module_code: 'processos', status: 'ativo', publication_state: 'publicado', monthly_price: 29.9, segments: ['advocacia'], capabilities: ['Controle de prazos'], capability_codes: ['prazos'], dependencies: [], incompatibilities: [], linked_plans: [], personalizations: [] }];
const plans = [{ id: 'PLN_1', product_id: 'PRD_LAW', product_code: 'law', product_name: 'Fokus Law', product_status: 'ativo', product_publication_version: 3, code: 'law-essencial', name: 'Essencial', full_name: 'Fokus Law - Essencial', segment: 'advocacia', status: 'ativo', publication_state: 'publicado', featured: true, configured_monthly_amount: null, monthly_amount: 24.9, annual_amount: 249, modules_count: 1, modules: [{ ...modules[0], monthly_amount: 29.9 }], personalization_defaults: [], subscription_count: 2, company_count: 2, voucher_count: 1 }];

const json = (response, payload, status = 200) => {
    response.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    response.end(JSON.stringify(payload));
};

createServer(async (request, response) => {
    const url = new URL(request.url, `http://${request.headers.host}`);
    if (url.pathname === '/api/csrf-token') return json(response, { token: 'visual-test-token' });
    if (url.pathname === '/api/auth/law-context') return json(response, { user: { name: 'Pessoa Teste' }, systems: [{ value: 'CMP_VISUAL', label: 'Fokus Law · Advocacia - Empresa de Demonstração', profiles: [{ value: 'usuario', label: 'Usuário comum' }] }] });
    if (url.pathname === '/api/backoffice/auth/me') return json(response, { admin });
    if (url.pathname === '/api/backoffice/dashboard') return json(response, { user: admin, alerts: [] });
    if (url.pathname === '/api/backoffice/companies') return json(response, { data: [], meta: { total: 0, current_page: 1, per_page: 15, last_page: 1 }, summary: {} });
    if (url.pathname === '/api/backoffice/directory/companies') return json(response, { data: [{ id: 'CMP_VISUAL', label: 'Fokus Law - Empresa de Demonstração' }] });
    if (url.pathname === '/api/backoffice/directory/users' && request.method === 'POST') return json(response, { message: 'Convite enviado.' }, 201);
    if (url.pathname === '/api/backoffice/directory/users') return json(response, { data: [
        { id: 'PAD_VISUAL', name: 'Administração Fokus', email: 'admin@fokuscloud.test', type: 'plataforma', role: 'superadministrador', status: 'ativo', company_count: 0 },
        { id: 'USR_VISUAL', name: 'Ana Empresa', email: 'ana@example.test', type: 'empresa', status: 'ativa', profile: 'Administrador', company_names: ['Empresa de Demonstração'], company_count: 1 },
    ], meta: { total: 2, current_page: Number(url.searchParams.get('page') || 1), per_page: 15, last_page: 1 } });
    if (url.pathname === '/api/backoffice/directory/users/empresa/USR_VISUAL') return json(response, { id: 'USR_VISUAL', name: 'Ana Empresa', email: 'ana@example.test', type: 'empresa', status: 'ativa', cpf: '12345678901', memberships: [{ company_id: 'CMP_VISUAL', company_name: 'Empresa de Demonstração', role: 'admin', status: 'ativo', subscriptions: [{ product_name: 'Fokus Law', plan_name: 'Essencial', status: 'ativa', billing_cycle: 'monthly' }] }] });
    if (url.pathname === '/api/backoffice/directory/users/plataforma/PAD_VISUAL') return json(response, { id: 'PAD_VISUAL', name: 'Administração Fokus', email: 'admin@fokuscloud.test', type: 'plataforma', role: 'superadministrador', status: 'ativo' });
    if (url.pathname === '/api/backoffice/catalog/products') return json(response, { products });
    if (url.pathname === '/api/backoffice/catalog') return json(response, { products: [{ ...products[0], modules }], options: { segments: { law: [{ code: 'advocacia', label: 'Advocacia' }] }, module_codes: [{ code: 'processos', label: 'Processos' }], personalization_types: [{ code: 'usuarios', label: 'Usuários' }] } });
    if (url.pathname === '/api/backoffice/plans') return json(response, plans);
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
