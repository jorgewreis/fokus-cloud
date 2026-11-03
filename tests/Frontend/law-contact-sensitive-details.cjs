const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

const repo = path.join(__dirname, '../..');
const source = fs.readFileSync(path.join(repo, 'public/portal/assets/fokus-law-contacts.js'), 'utf8');
const styles = fs.readFileSync(path.join(repo, 'public/portal/assets/fokus-law-shell.css'), 'utf8');
const catalogSource = fs.readFileSync(path.join(repo, 'public/portal/assets/fokus-law-bank-catalog.js'), 'utf8');
const catalogContext = { window: {} };
vm.createContext(catalogContext);
vm.runInContext(catalogSource, catalogContext);

class Node {
  constructor(value = '') { this.value = value; this.children = []; this.listeners = {}; this.hidden = false; this.attributes = {}; this.classList = { add: () => {} }; }
  append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
  insertBefore(node, reference) { node.parent = this; const index = this.children.indexOf(reference); this.children.splice(index < 0 ? this.children.length : index, 0, node); }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); if (this.isSelect) this.options = nodes.map((node) => [node.value, node.label]); }
  setAttribute(name, value) { this.attributes[name] = value; }
  addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
  dispatch(name) { for (const callback of this.listeners[name] || []) callback({ target: this }); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter((node) => node !== this); this.removed = true; }
}
class Option { constructor(label, value) { this.label = label; this.value = value; } }

const context = {
  Array,
  Object,
  Option,
  window: catalogContext.window,
  input: (value = '') => new Node(value),
  select: (items, value) => { const control = new Node(value); control.isSelect = true; control.options = items; return control; },
  field: (label, control) => { const container = new Node(); container.label = label; control.label = label; container.append(control); return container; },
  section: () => ({ content: new Node() }),
  $: () => new Node(),
  button: (label, _className, callback) => { const control = new Node(label); control.click = callback; return control; },
};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('  function sensitiveDetailsEditor('), source.indexOf('  function documentRow(')), context);

const sample = {
  birth_date: '1981-07-10',
  parentage: [
    { name: 'Synthetic Grandparent', relationship: 'Avó' },
    { name: 'Synthetic Mother', relationship: 'Mãe' },
    { name: 'Synthetic Father', relationship: 'Pai' },
    { name: 'Second Synthetic Mother', relationship: 'Mãe' },
  ],
  bank_accounts: [{ bank: 'Synthetic legacy bank', branch: '1234', account: '1234-5', account_type: 'Synthetic legacy type', holder_name: 'Synthetic Holder' }],
  pix_keys: [{ type: 'email', value: 'synthetic@example.invalid' }],
};

const editor = context.sensitiveDetailsEditor(sample);
const serialize = () => JSON.parse(JSON.stringify(editor.serialize('pf')));
assert.deepEqual(serialize(), sample, 'existing encrypted values must round-trip exactly');
assert.deepEqual(serialize(), sample, 'repeated serialization must not mutate editor values');
assert.deepEqual(sample.parentage[0], { name: 'Synthetic Grandparent', relationship: 'Avó' }, 'serialization must not mutate source values');

const personalControls = editor.sections[0].content.children;
assert.equal(personalControls[1].label, 'Pai');
assert.equal(personalControls[1].children[0].value, 'Synthetic Father');
assert.equal(personalControls[2].label, 'Mãe');
assert.equal(personalControls[2].children[0].value, 'Synthetic Mother');
personalControls[1].children[0].value = 'Updated Synthetic Father';
const editedParents = serialize().parentage;
assert.equal(editedParents[2].name, 'Updated Synthetic Father');
assert.deepEqual(editedParents[3], { name: 'Second Synthetic Mother', relationship: 'Mãe' }, 'duplicate explicit parentage records must remain');
assert.deepEqual(editedParents[0], sample.parentage[0], 'unclassified parentage must not be inferred as father or mother');

const withoutExplicitRoles = context.sensitiveDetailsEditor({ parentage: [{ name: 'First Synthetic Record', relationship: '' }, { name: 'Second Synthetic Record', relationship: 'Responsável' }] });
const unclassifiedControls = withoutExplicitRoles.sections[0].content.children;
assert.equal(unclassifiedControls[1].children[0].value, '', 'first entry must not be presumed to be the father');
assert.equal(unclassifiedControls[2].children[0].value, '', 'second entry must not be presumed to be the mother');
assert.deepEqual(JSON.parse(JSON.stringify(withoutExplicitRoles.serialize('pf').parentage)), [
  { name: 'First Synthetic Record', relationship: '' },
  { name: 'Second Synthetic Record', relationship: 'Responsável' },
]);
const parentageOverflow = context.sensitiveDetailsEditor({ parentage: [
  { name: 'Synthetic Record One', relationship: 'Responsável' },
  { name: 'Synthetic Record Two', relationship: 'Outro' },
  { name: 'Synthetic Record Three', relationship: 'Tutor' },
] });
parentageOverflow.sections[0].content.children[1].children[0].value = 'Synthetic Father';
parentageOverflow.sections[0].content.children[2].children[0].value = 'Synthetic Mother';
assert.match(parentageOverflow.validationError('pf'), /máximo 4/);

const financialControls = editor.sections[1].content.children;
const accountRows = financialControls[0].children;
const accountRow = accountRows[0];
const bankField = accountRow.children[0];
const bankSearch = bankField.children[0];
const bankSelect = bankField.children[1];
const accountTypeSelect = accountRow.children[4].children[0];
assert.equal(bankField.label, 'Banco / instituição (número-código BCB)');
assert(bankSelect.options.some(([value]) => value === '001 - BCO DO BRASIL S.A.'), 'bank options must include a string code with leading zeroes');
assert.equal(bankSelect.options.find(([value]) => /^\d{3} - /.test(value))?.[0].slice(0, 3), '001', 'bank select values must be sorted by code');
assert(bankSelect.options.some(([value]) => value === 'Synthetic legacy bank'), 'legacy bank values must remain selectable');
assert(accountTypeSelect.options.some(([value]) => value === 'Synthetic legacy type'), 'legacy account type must remain selectable');
assert.deepEqual(serialize().bank_accounts[0], sample.bank_accounts[0], 'legacy bank and account type must remain byte-for-byte equivalent');
bankSearch.value = 'BRASILIA';
bankSearch.dispatch('input');
assert(bankSelect.options.some(([value]) => value === '070 - BRB - BCO DE BRASILIA S.A.'), 'bank search must match institution names without accents');
assert.equal(bankSelect.value, 'Synthetic legacy bank', 'filtering must retain a selected legacy value');
bankSearch.value = '001';
bankSearch.dispatch('input');
assert(bankSelect.options.some(([value]) => value === '001 - BCO DO BRASIL S.A.'), 'bank search must match the three-digit code');
bankSearch.value = 'no-bank-matches-this';
bankSearch.dispatch('input');
assert(bankSelect.options.some(([_, label]) => label === 'Nenhum banco encontrado'));
assert.equal(bankSelect.value, 'Synthetic legacy bank', 'an empty search result must not clear a legacy selection');
bankSearch.value = '';
bankSearch.dispatch('input');
assert(bankSelect.options.some(([value]) => value === '001 - BCO DO BRASIL S.A.'), 'clearing search must restore the full list');
assert.equal(bankSelect.value, 'Synthetic legacy bank', 'clearing search must preserve the selected legacy value');
const otherBank = bankSelect.options.find(([_, label]) => label === 'Outra instituição / banco não listado');
bankSelect.value = otherBank[0];
bankSelect.dispatch('change');
accountRow.children[1].children[0].value = 'Synthetic unlisted bank';
assert.equal(serialize().bank_accounts[0].bank, 'Synthetic unlisted bank', 'unlisted institution should be stored as entered');
assert(!serialize().bank_accounts[0].bank.startsWith('__fokus_other_bank_'), 'manual bank choices must never serialize internal sentinel values');
assert.equal(accountRow.children[1].hidden, false, 'choosing an unlisted bank should expose its name input');

const fullParentage = context.sensitiveDetailsEditor({ parentage: [
  { name: 'Synthetic Father', relationship: 'Pai' },
  { name: 'Synthetic Mother', relationship: 'Mãe' },
  { name: 'Synthetic Parent Three', relationship: 'Responsável' },
  { name: 'Synthetic Parent Four', relationship: 'Outro' },
] });
const parentageRows = fullParentage.sections[0].content.children[3];
const rowsBeforeLimitAttempt = parentageRows.children.length;
fullParentage.sections[0].content.children[4].click();
assert.equal(parentageRows.children.length, rowsBeforeLimitAttempt, 'the combined father, mother, and extra parentage limit must remain four');
const duplicateEditor = context.sensitiveDetailsEditor(sample);
const duplicateRows = duplicateEditor.sections[0].content.children[3];
duplicateRows.children[1].children[2].click();
const afterDuplicateRemoval = JSON.parse(JSON.stringify(duplicateEditor.serialize('pf').parentage));
assert.equal(afterDuplicateRemoval.length, 3, 'removing one duplicate must remove only that row');
assert.deepEqual(afterDuplicateRemoval, sample.parentage.slice(0, 3), 'removing a duplicate must preserve other parentage values');
const clearedRoleEditor = context.sensitiveDetailsEditor(sample);
clearedRoleEditor.sections[0].content.children[1].children[0].value = '';
clearedRoleEditor.sections[0].content.children[2].children[0].value = '';
assert.deepEqual(JSON.parse(JSON.stringify(clearedRoleEditor.serialize('pf').parentage)), [sample.parentage[0], sample.parentage[3]], 'clearing explicit parent fields should preserve unrelated and duplicate records');

const financialRows = context.sensitiveDetailsEditor({ bank_accounts: [{}, {}, {}, {}, {}] }).sections[1].content.children[0];
assert.equal(financialRows.children.length, 5, 'existing accounts at the five-row limit must stay editable');
assert.equal(context.sensitiveDetailsEditor({ bank_accounts: Array.from({ length: 6 }, () => ({ bank: 'Synthetic bank' })) }).validationError('pf'), 'Cadastre no máximo 5 contas bancárias.');
const units = editor;
units.updateNature(true, 'pf');
assert(units.sections.every((section) => section.hidden));
units.updateNature(false, 'pj');
assert.equal(units.sections[0].hidden, true);
assert.equal(units.sections[1].hidden, false);
const pj = units.serialize('pj');
assert.equal(pj.birth_date, null);
assert.equal(pj.parentage.length, 0);
assert.equal(pj.bank_accounts.length, 1);
const empty = context.sensitiveDetailsEditor(null).serialize('pf');
assert.equal(empty.birth_date, null);
assert.deepEqual(JSON.parse(JSON.stringify(empty.parentage)), [], 'empty father and mother fields must not create records');
assert.equal(empty.pix_keys.length, 0);
const cancelledEditor = context.sensitiveDetailsEditor(sample);
cancelledEditor.sections[0].content.children[1].children[0].value = 'Uncommitted Synthetic Father';
assert.deepEqual(JSON.parse(JSON.stringify(context.sensitiveDetailsEditor(sample).serialize('pf'))), sample, 'discarding an editor must not persist or mutate source values');
assert.deepEqual(serialize().parentage, editedParents, 'switching PF/PJ visibility must not mutate the editor parentage values');
assert.equal(sample.bank_accounts[0].bank, 'Synthetic legacy bank', 'editor selection changes must not mutate stored source values');

assert.equal(catalogContext.window.FokusLawBankCatalog.banks.length, 459);
assert.equal(catalogContext.window.FokusLawBankCatalog.updatedAt, '2026-11-03');
assert(catalogContext.window.FokusLawBankCatalog.banks.every((bank) => /^\d{3}$/.test(bank.code)));
assert(!catalogContext.window.FokusLawBankCatalog.banks.some((bank) => bank.code === '000'));
assert(!catalogContext.window.FokusLawBankCatalog.banks.some((bank) => bank.code === '0'));
assert(source.includes("Object.prototype.hasOwnProperty.call(contact, 'sensitive_details')"));
assert(source.includes('if (protectedEditor && recordKind.value !== \'unit\') body.sensitive_details'));
assert(source.includes('documentRows.children.length < 20'));
assert(source.includes('emailCount > 10'));
assert(!source.includes('Não cadastre senhas, tokens ou credenciais bancárias.'));
assert(/\.law-contact-repeat-row\s*>\s*\.law-contact-remove\s*\{[^}]*align-self:\s*end/s.test(styles), 'repeat-row remove buttons must align with the bottom of the controls');
assert(styles.includes('@media (max-width: 900px)') && styles.includes('.law-contact-repeat-row.law-contact-document-row'), 'document rows must retain responsive tablet/mobile layout rules');
assert(styles.includes('@media (max-width: 620px)') && styles.includes('.law-contact-repeat-row, .law-contact-address-row'), 'repeat rows must retain their small-screen layout rules');

console.log('PASS: explicit father/mother mapping, duplicate/unclassified parentage retention, parentage limit, legacy bank/account type round-trip, unlisted banks, local BCB catalog, sensitive-field access, and existing contact limits.');
