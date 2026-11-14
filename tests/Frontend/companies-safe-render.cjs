'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const page = fs.readFileSync(path.join(root, 'public/portal/companies.html'), 'utf8');
const script = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)?.[1];

assert.ok(script, 'O script de seleção de empresa não foi encontrado.');
assert.doesNotMatch(script, /innerHTML/, 'Dados de empresa não podem ser interpolados como HTML.');

class FakeButton {
  constructor(document) {
    this.document = document;
    this.dataset = {};
    this.disabled = false;
    this.textContent = '';
  }

  focus() {
    this.document.activeElement = this;
  }
}

async function render(companies) {
  const requests = [];
  const document = {
    body: {},
    activeElement: null,
    createDocumentFragment() {
      return { children: [], append(child) { this.children.push(child); } };
    },
    createElement(tag) {
      assert.equal(tag, 'button');
      return new FakeButton(document);
    },
    querySelector(selector) {
      return {
        '#companies': companyList,
        '#feedback': feedback,
        '#continue-portal': continuePortal,
        '#login-again': loginAgain,
      }[selector] ?? null;
    },
  };
  document.activeElement = document.body;
  const companyList = {
    children: [], attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; },
    querySelectorAll(selector) { assert.equal(selector, 'button'); return this.children; },
    replaceChildren(fragment) { this.children = fragment.children; },
    contains(element) { return this.children.includes(element); },
  };
  const feedback = { textContent: '' };
  const continuePortal = { hidden: true, focus() { document.activeElement = this; } };
  const loginAgain = { hidden: true };
  const location = { url: '', assign(url) { this.url = url; } };
  const sandbox = {
    document,
    location,
    FokusApi: {
      async request(url, options) {
        requests.push({ url, options });
        if (url === '/auth/me') return { companies };
        if (url === '/auth/select-company') return {};
        throw new Error(`Rota inesperada: ${url}`);
      },
    },
  };
  vm.runInNewContext(script, sandbox);
  await new Promise((resolve) => setImmediate(resolve));
  return { companyList, continuePortal, document, feedback, location, requests };
}

(async () => {
  const payload = [{
    id: 'company-\" onfocus=\"unexpected',
    name: 'Empresa <img src=x onerror=unexpected>',
    role: 'gestor <svg onload=unexpected>',
  }];
  const result = await render(payload);
  const button = result.companyList.children[0];

  assert.equal(result.companyList.children.length, 1);
  assert.equal(button.dataset.id, payload[0].id);
  assert.equal(button.textContent, `${payload[0].name} — ${payload[0].role}`);
  assert.equal(result.companyList.attributes['aria-busy'], undefined);

  await button.onclick();
  assert.equal(result.companyList.attributes['aria-busy'], 'false');
  assert.equal(result.continuePortal.hidden, false);
  assert.equal(result.document.activeElement, result.continuePortal);
  assert.equal(result.location.url, '/portal');
  assert.deepEqual(JSON.parse(JSON.stringify(result.requests)), [
    { url: '/auth/me' },
    { url: '/auth/select-company', options: { method: 'POST', body: { company_id: payload[0].id } } },
  ]);

  await button.onclick();
  assert.equal(result.requests.length, 2, 'A seleção concluída não pode disparar outro POST.');
  console.log('companies-safe-render: literal-data=ok; single-post=ok; recovery-link=ok');
})().catch((error) => { console.error(error); process.exitCode = 1; });
