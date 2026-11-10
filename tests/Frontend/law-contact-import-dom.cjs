const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/portal/assets/fokus-law-contacts.js'), 'utf8');
class Node {
  constructor(value = '') { this.value = value; this.children = []; this.listeners = {}; this.checked = false; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute() {}
  addEventListener(event, callback) { this.listeners[event] = callback; }
  async change() { await this.listeners.change?.({ target: this }); }
}
let requests = []; let resolvePreview; let resolveCommit; let mode = 'normal'; let saves = 0;
const context = {
  Uint8Array, String, btoa: (value) => Buffer.from(value, 'binary').toString('base64'),
  $: () => new Node(), input: () => new Node(), field: (_label, control) => control,
  button: (_label, _class, callback) => Object.assign(new Node(), { click: callback }),
  createModal: () => {
    let hidden;
    return { body: new Node(), footer: new Node(), onHidden: (callback) => { hidden = callback; }, close: () => hidden?.(), dismiss: () => hidden?.() };
  },
  window: { FokusApi: { request: async (url, options) => {
    requests.push({ url, body: options.body });
    if (url.endsWith('preview')) {
      const result = { batch_id: 'opaque-batch', rows: [{ index: 0, status: 'ready', display_name: '<script>synthetic</script>' }, { index: 1, status: 'duplicate', display_name: 'Duplicate' }, { index: 2, status: 'invalid', display_name: 'Invalid' }] };
      if (mode === 'delayed') return new Promise((resolve) => { resolvePreview = () => resolve(result); });
      return result;
    }
    if (mode === 'commit-delayed') return new Promise((resolve) => { resolveCommit = () => resolve({ imported_count: 1 }); });
    if (mode === 'conflict') throw new Error('Conflict');
    return { imported_count: 1 };
  } } },
};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('  function openImport('), source.indexOf('  async function exportContacts(')), context);
const file = () => ({ name: 'synthetic.csv', size: 3, arrayBuffer: async () => new Uint8Array([97, 98, 99]).buffer });
const open = () => context.openImport(new Node(), async () => { saves++; });
const tick = () => new Promise((resolve) => setImmediate(resolve));
(async () => {
  const ui = open(); ui.file.files = [file()]; await ui.file.change();
  assert.equal(ui.rows.children.length, 3); assert.equal(ui.rows.children[1].disabled, true);
  assert.equal(ui.commit.disabled, true); ui.confirmed.checked = true; await ui.confirmed.change(); assert.equal(ui.commit.disabled, false);
  mode = 'commit-delayed'; const first = ui.commit.click(); await tick(); await ui.commit.click();
  assert.equal(requests.filter((r) => r.url.endsWith('commit')).length, 1); assert.equal(ui.cancel.disabled, true);
  assert.deepEqual(JSON.parse(JSON.stringify(requests.at(-1).body)), { batch_id: 'opaque-batch', rows: [0], confirmed: true });
  resolveCommit(); await first; assert.equal(saves, 1);
  mode = 'delayed'; const late = open(); late.file.files = [file()]; const pending = late.file.change(); await tick(); late.modal.dismiss(); resolvePreview(); await pending;
  assert.equal(late.rows.children.length, 0); assert.equal(late.commit.disabled, true);
  const newer = open(); newer.file.files = [file()]; const old = newer.file.change(); await tick();
  const resolveOld = resolvePreview; mode = 'normal'; newer.file.files = [file()]; await newer.file.change(); resolveOld(); await old;
  assert.equal(newer.rows.children.length, 3);
  mode = 'conflict'; newer.confirmed.checked = true; await newer.confirmed.change(); await newer.commit.click(); assert.equal(newer.commit.disabled, true);
  const bad = open(); const before = requests.length; bad.file.files = [{ name: 'bad.zip', size: 5 }]; await bad.file.change(); assert.equal(requests.length, before);
  bad.file.files = [{ name: 'big.csv', size: 2097153 }]; await bad.file.change(); assert.equal(requests.length, before);
  console.log('PASS: preview, zero-based selection, duplicates, confirmation, double-submit, close, stale responses, conflicts and size/type limits.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
