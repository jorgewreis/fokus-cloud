import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE
  ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const root = new URL('../../', import.meta.url);
const recordSource = await readFile(new URL('public/portal/assets/law-record-ui.js', root), 'utf8');
const processSource = await readFile(new URL('public/portal/assets/fokus-law-processes.js', root), 'utf8');

async function setup({restricted = false} = {}) {
  const dom = new JSDOM('<!doctype html><main id="root"></main><button id="outside">Outro controle</button>', {runScripts:'outside-only', url:'http://localhost/'});
  const { window } = dom, doc = window.document;
  window.HTMLElement.prototype.scrollIntoView = () => {};
  let resume;
  let notifyLateReferences;
  let lateResponseReleased = false;
  let releaseAccess, notifyAccessPending, accessCount = 0;
  const accessPending = new Promise(resolve => { notifyAccessPending = resolve; });
  const lateReferences = new Promise(resolve => { notifyLateReferences = resolve; });
  const calls = [];
  const references = { members: [], units: [], tags: [], confidentiality_levels: [], procedural_priorities: [] };
  const makeCase = page => ({
    case: { id:'case-test', law_unit_id:'unit-test', case_number_formatted:'0000000-00.0000.0.00.0000', unit_name:'Unidade fictícia', operational_status:'active', operational_status_label:'Ativo', operational_priority:'normal', confidentiality_level:restricted ? 'cnj_5' : 'public', datajud_sync_status:'not_found', djen_sync_status:'not_requested' },
    events: [{ title:`Evento da página ${page}`, before_state:{}, after_state:{} }], history_total:100, history_page:page,
    contacts:[], tags:[], relations:[], movements:[], publications:[], can_manage_access:restricted,
  });
  window.FokusApi = { request: async url => {
    calls.push(url);
    if (url.includes('/references?')) { if (lateResponseReleased) notifyLateReferences(); return references; }
    if (url.startsWith('/law/cases?')) return { cases:[], pagination:{total:0, per_page:15}, summary:{recent:[]}, summary_by_class:[] };
    if (url.endsWith('/case-test/access')) {
      accessCount++;
      if (restricted && accessCount === 2) await new Promise(resolve => { releaseAccess = resolve; notifyAccessPending(); });
      return {users:[]};
    }
    if (url.includes('/case-test?history_page=')) {
      const page = Number(new URL(url, 'http://localhost').searchParams.get('history_page'));
      if (page === 2) await new Promise(resolve => { resume = resolve; });
      return makeCase(page);
    }
    throw new Error(`Unexpected request: ${url}`);
  } };
  window.eval(recordSource); window.eval(processSource);
  await window.FokusLawProcesses.openCase(doc.getElementById('root'), { company:{role:'reader'}, law_permissions:[] }, 'case-test');
  const history = () => doc.querySelector('.law-record-detail-updates');
  assert.ok(history(), `Histórico não renderizado: ${doc.getElementById('root').textContent}`);
  return {dom, doc, history, calls, lateReferences, accessPending, finishAccess: () => { assert.ok(releaseAccess); releaseAccess(); }, finish: () => { assert.ok(resume); lateResponseReleased = true; resume(); }};
}

test('resposta tardia não restaura foco nem recria histórico após desmontagem', {timeout: 10000}, async () => {
  const {dom, doc, history, finish, lateReferences} = await setup();
  try {
    const root = doc.getElementById('root');
    const trigger = history().querySelector('button[aria-label^="Página 2 de"]');
    trigger.focus(); trigger.click();
    root.remove();
    const outside = doc.getElementById('outside'); outside.focus();
    finish();
    // Wait for the delayed response to reach the next API boundary, then for cleanup.
    await lateReferences;
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(doc.querySelector('.law-record-detail-updates'), null);
    assert.equal(doc.activeElement, outside);
    assert.equal(root.isConnected, false);
  } finally { dom.window.close(); }
});

test('resposta tardia não sobrescreve nova abertura do mesmo processo', {timeout: 10000}, async () => {
  const {dom, doc, history, finish, lateReferences} = await setup();
  const {window} = dom;
  try {
    const root = doc.getElementById('root');
    const trigger = history().querySelector('button[aria-label^="Página 2 de"]');
    trigger.focus(); trigger.click();
    await window.FokusLawProcesses.openCase(root, { company:{role:'reader'}, law_permissions:[] }, 'case-test');
    const newHistory = history();
    assert.match(newHistory.textContent, /Evento da página 1/);
    const outside = doc.getElementById('outside'); outside.focus();
    finish();
    await lateReferences;
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(history(), newHistory);
    assert.match(history().textContent, /Evento da página 1/);
    assert.equal(doc.activeElement, outside);
  } finally { dom.window.close(); }
});

test('resposta tardia não substitui lista após voltar na mesma sessão', {timeout: 10000}, async () => {
  const {dom, doc, history, finish, lateReferences} = await setup();
  try {
    const trigger = history().querySelector('button[aria-label^="Página 2 de"]');
    trigger.focus(); trigger.click();
    const back = [...doc.querySelectorAll('button')].find(button => button.textContent === 'Voltar à lista');
    assert.ok(back); back.click();
    for (let i = 0; i < 20 && !doc.querySelector('.law-record-overview-banner'); i++) await new Promise(resolve => setTimeout(resolve, 0));
    assert.ok(doc.querySelector('.law-record-overview-banner'));
    const outside = doc.getElementById('outside'); outside.focus();
    finish(); await lateReferences; await new Promise(resolve => setImmediate(resolve));
    assert.ok(doc.querySelector('.law-record-overview-banner'));
    assert.equal(history(), null);
    assert.equal(doc.activeElement, outside);
  } finally { dom.window.close(); }
});

test('histórico sigiloso recupera foco após espera da API de autorizações', {timeout: 10000}, async () => {
  const {dom, doc, history, finish, accessPending, finishAccess} = await setup({restricted:true});
  try {
    const oldHistory = history();
    const trigger = oldHistory.querySelector('button[aria-label^="Página 2 de"]');
    trigger.focus(); trigger.click(); finish();
    await accessPending;
    assert.equal(oldHistory.isConnected, false);
    assert.equal(history(), null);
    finishAccess();
    for (let i = 0; i < 20 && !history(); i++) await new Promise(resolve => setTimeout(resolve, 0));
    assert.ok(history(), 'O histórico deve reaparecer após a resposta de autorizações');
    assert.match(history().textContent, /Evento da página 2/);
    assert.equal(doc.activeElement, history().querySelector('button[aria-current="page"]'));
  } finally { dom.window.close(); }
});

for (const moveFocus of [false, true]) {
  test(`histórico real reconstrói página 2 ${moveFocus ? 'sem tomar foco externo' : 'e recupera foco'}`, async () => {
    const {dom, doc, history, calls, finish} = await setup();
    try {
      const oldHistory = history();
      const trigger = oldHistory.querySelector('button[aria-label^="Página 2 de"]');
      assert.ok(trigger); trigger.focus(); trigger.click();
      assert.ok(calls.some(url => url.includes('history_page=2')));
      if (moveFocus) doc.getElementById('outside').focus();
      finish();
      for (let i = 0; i < 20 && history() === oldHistory; i++) await new Promise(resolve => setTimeout(resolve, 0));
      assert.notEqual(history(), oldHistory);
      assert.match(history().textContent, /Evento da página 2/);
      const current = history().querySelector('button[aria-current="page"]');
      assert.equal(current.textContent, '2');
      assert.equal(doc.activeElement, moveFocus ? doc.getElementById('outside') : current);
    } finally { dom.window.close(); }
  });
}
