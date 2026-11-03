const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname, '../../public/portal/assets/fokus-law-contacts.js'),'utf8');
class Node {constructor(value=''){this.value=value;this.children=[];this.hidden=false;}append(...nodes){this.children.push(...nodes);}remove(){}}
const context={Array,Object, input:(v='')=>new Node(v), select:(items,v)=>new Node(v), field:(_,c)=>c, section:()=>({content:new Node()}), $:()=>new Node(), button:()=>new Node()};
vm.createContext(context); vm.runInContext(source.slice(source.indexOf('  function sensitiveDetailsEditor('),source.indexOf('  function documentRow(')),context);
const sample={birth_date:'1981-07-10',parentage:[{name:'Synthetic Parent',relationship:'Mãe'}],bank_accounts:[{bank:'Synthetic',branch:'1234',account:'1234-5',account_type:'Corrente',holder_name:'Synthetic Holder'}],pix_keys:[{type:'email',value:'synthetic@example.invalid'}]};
const editor=context.sensitiveDetailsEditor(sample);
assert.deepEqual(JSON.parse(JSON.stringify(editor.serialize('pf'))),sample);
editor.updateNature(true,'pf');assert(editor.sections.every(s=>s.hidden));
editor.updateNature(false,'pj');assert.equal(editor.sections[0].hidden,true);assert.equal(editor.sections[1].hidden,false);
const pj=editor.serialize('pj');assert.equal(pj.birth_date,null);assert.equal(pj.parentage.length,0);assert.equal(pj.bank_accounts.length,1);
const empty=context.sensitiveDetailsEditor(null).serialize('pf');assert.equal(empty.birth_date,null);assert.equal(empty.pix_keys.length,0);
assert(source.includes("Object.prototype.hasOwnProperty.call(contact, 'sensitive_details')"));
assert(source.includes("if (protectedEditor && recordKind.value !== 'unit') body.sensitive_details"));
assert(source.includes('documentRows.children.length < 20'));assert(source.includes('emailCount > 10'));
console.log('PASS: PF/PJ/unit visibility, synthetic round-trip, empty optional fields, missing-key preservation guard, document/channel limits.');
