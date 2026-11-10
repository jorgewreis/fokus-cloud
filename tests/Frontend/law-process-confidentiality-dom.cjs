const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../../public/portal/assets/fokus-law-processes.js'), 'utf8');
const editor = source.slice(source.indexOf('    async function edit(event)'), source.indexOf('    async function detail('));
class Node {
  constructor(value = '') { this.value = value; this.children = []; this.isConnected = true; this.listeners = {}; }
  append(...nodes) { this.children.push(...nodes); }
  setAttribute() {}
  addEventListener(name, callback) { this.listeners[name] = callback; }
  querySelectorAll() { return []; }
}
async function scenario({ cancel = false, late = false, level = 'cnj_5' } = {}) {
  let submit, payload, reloads = 0, opened = 0;
  const controls = {};
  const modal = { element: new Node(), body: new Node(), footer: new Node(), close() { this.element.isConnected = false; } };
  const local = { statuses: [{ code: 'active', label: 'Active' }], members: [], confidentiality_levels: ['public','cnj_5'].map(code => ({ code, label: code })), procedural_priorities: [] };
  const c = { id: 'case-1', version: 7, law_unit_id: 'unit-1', operational_status: 'active', operational_priority: 'normal', confidentiality_level: 'public', internal_confidentiality_level: level, source_confidentiality_level: 'public', subjects: [], official_fields: [] };
  const context = { detailData: { case: c, can_manage_access: true }, viewToken: 1, currentId: c.id,
    getRefs: async () => local, active: () => true,
    dialog: () => { opened++; return modal; }, $: () => new Node(), section: () => ({ body: new Node() }),
    select: (options, value) => { const n = new Node(value); n.options = options; return n; }, input: value => new Node(value),
    field: (label, control, name) => { controls[name] = control; return control; }, text: value => value, button: () => new Node(),
    metadataEditor: () => ({ classSelect: new Node(), classOther: new Node(), subjectsSelect: new Node(), customHost: new Node(), values: () => ({ subjects: [], klass: null }) }),
    keyValues: () => new Node(), message: () => new Node(), confidentialityAlert: '', needsConfidentialityAlert: () => false,
    bindForm: (_form, _save, _error, fn) => { submit = fn; }, apiPath: id => '/' + id,
    request: async (_url, options) => { payload = options.body; if (cancel) modal.close(); if (late) context.viewToken++; },
    detail: async () => { reloads++; }, Date, Object,
  };
  vm.createContext(context); vm.runInContext(editor + '\nthis.runEdit = edit;', context);
  await context.runEdit(); assert.equal(opened, 1);
  assert.equal(controls.internal_confidentiality_level.value, level);
  assert.equal(controls.source_confidentiality_level, undefined);
  await submit();
  assert.equal(payload.internal_confidentiality_level, level);
  assert.equal(payload.source_confidentiality_level, undefined);
  assert.equal(payload.confidentiality_level, undefined);
  assert.equal(payload.version, 7);
  assert.equal(reloads, cancel || late ? 0 : 1);
}
(async () => {
  for (const level of ['public', 'cnj_5', 'secret', 'confidential']) await scenario({ level });
  await scenario({ cancel: true }); await scenario({ late: true });
  console.log('PASS: internal/source separation, legacy levels, cancelled and superseded edit responses (simulated DOM).');
})().catch(error => { console.error(error); process.exitCode = 1; });
