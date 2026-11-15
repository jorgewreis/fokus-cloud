const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const page = fs.readFileSync(path.join(root, 'public/marketing/products/fokus-law.html'), 'utf8');
const script = fs.readFileSync(path.join(root, 'public/marketing/products/fokus-law.js'), 'utf8');

assert.match(page, /BETA FOKUS LAW · ADVOCACIA/);
assert.match(page, /seis meses de acesso gratuito ao beta do Fokus Law/);
assert.match(page, /name="beta_acknowledged"[^>]*required/);
assert.match(page, /Não envie dados pessoais de clientes nem informações de processos/);
assert.match(page, /Gestão de Contatos/);
assert.match(page, /Gestão de Processos/);
assert.match(script, /'\/law\/beta-applications'/);
assert.match(script, /beta_terms_accepted/);
assert.doesNotMatch(script, /request\('\/product-interests'/);

console.log('Law beta interest form contract passed.');
