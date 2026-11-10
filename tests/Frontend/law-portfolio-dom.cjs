const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../../public/portal/assets/fokus-law-processes.js'), 'utf8');
const code = source.slice(source.indexOf('    async function importPortfolio(event)'), source.indexOf('    async function create(event)'));
class Node {
  constructor(value = '') { this.value = value; this.children = []; this.isConnected = true; this.listeners = {}; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute() {}
  addEventListener(event, fn) { this.listeners[event] = fn; }
}
async function scenario(mode) {
  let submit, sent = [], resolve, pending;
  const controls = {};
  const modal = { element: new Node(), body: new Node(), footer: new Node(), close() { this.element.isConnected = false; } };
  const context = { viewToken: 1, context: { active_unit_id: 'unit-a' }, session: 1, active: () => true,
    getRefs: async () => ({ units: [{id:'unit-a',name:'A'},{id:'unit-b',name:'B'}], confidentiality_levels: [{code:'public',label:'Public'},{code:'cnj_5',label:'Secret'}] }),
    dialog: () => modal, $: () => new Node(), message: () => new Node(),
    select: (_options, value) => new Node(value), field: (_label, node, name) => { controls[name] = node; return node; },
    button: (label, fn) => { const node = new Node(); node.textContent = label; node.click = fn; return node; },
    bindForm: (_form, _button, _error, fn) => { submit = fn; },
    request: async (_path, options) => { sent.push(options.body); if(pending) await pending; return { rows:[{number:'123',status:'new'}], created_count:1 }; }, list: () => {}, JSON,
  };
  vm.createContext(context); vm.runInContext(code + '\nthis.run = importPortfolio;', context); await context.run();
  controls.numbers.value = '123\n456'; controls.internal_confidentiality_level.value = 'cnj_5';
  await submit(); assert.equal(sent[0].confirmed, false);
  assert.equal(controls.internal_confidentiality_level.value, 'cnj_5');
  if(mode === 'change') { controls.law_unit_id.value = 'unit-b'; controls.law_unit_id.listeners.change(); }
  if(mode === 'late-edit' || mode === 'cancel') {
    pending = new Promise(done => { resolve = done; }); const operation = submit();
    if(mode === 'cancel') modal.close(); else { controls.internal_confidentiality_level.value = 'public'; controls.internal_confidentiality_level.listeners.change(); }
    resolve(); await operation;
    if(mode === 'late-edit') { pending = null; await submit(); assert.equal(sent.at(-1).confirmed, false); }
  } else { await submit(); assert.equal(sent[1].confirmed, mode !== 'change'); }
  assert.equal(sent[0].internal_confidentiality_level, 'cnj_5'); assert.equal(sent[0].law_unit_id, 'unit-a');
  if(mode === 'unchanged') assert.equal(JSON.stringify({...sent[0],confirmed:true}), JSON.stringify(sent[1]));
}
(async () => { for(const mode of ['unchanged','change','late-edit','cancel']) await scenario(mode); console.log('PASS: preview/confirm snapshot, internal confidentiality, unit changes, late input and cancel (simulated DOM).'); })().catch(error => { console.error(error); process.exitCode = 1; });
