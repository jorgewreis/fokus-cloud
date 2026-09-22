import { readFile, readdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import process from 'node:process';

const root = resolve(import.meta.dirname, '..');
const pagesDirectory = resolve(root, 'public/backoffice/pages');
const router = await readFile(resolve(root, 'public/backoffice/assets/js/backoffice-router.js'), 'utf8');
const template = await readFile(resolve(root, 'docs/templates/backoffice-record-page.html'), 'utf8');
const pages = (await readdir(pagesDirectory)).filter((file) => file.endsWith('.html'));
const errors = [];

for (const requirement of ['mount(pageId, page, markup, signal)', 'unmountCurrent()', 'AbortController', 'BackofficePageRegistry']) {
    if (!router.includes(requirement)) errors.push(`Router não expõe o contrato obrigatório: ${requirement}`);
}

for (const requirement of ['fs-page-layout', 'fs-page-header-display', 'fs-card fs-card-panel', 'fs-filter-form', 'fs-table fs-table-records', 'backoffice-records-drawer']) {
    if (!template.includes(requirement)) errors.push(`Template não contém a composição obrigatória: ${requirement}`);
}

const legacyFragments = new Set([
    'companies.html', 'modules.html', 'pagamentos.html', 'platform-dashboard-final.html',
    'product-interests.html', 'products.html', 'security.html', 'subscription-plans.html',
    'subscriptions.html', 'vouchers.html',
]);

for (const page of pages) {
    const contents = await readFile(resolve(pagesDirectory, page), 'utf8');
    if ((!contents.includes('<main') || !contents.includes('fs-container-fluid')) && !legacyFragments.has(page)) {
        errors.push(`${page} não possui container oficial de página.`);
    }
    if ((contents.includes('<style') || contents.includes('style=')) && !legacyFragments.has(page)) {
        errors.push(`${page} contém estilo local inline.`);
    }
}

if (errors.length) {
    console.error('Contrato visual do Backoffice inválido:\n- ' + errors.join('\n- '));
    process.exitCode = 1;
} else {
    console.log(`Contrato visual do Backoffice válido para ${pages.length} páginas.`);
}
