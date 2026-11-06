// Validação isolada: nunca abre conexão com Supabase ou com bancos do Cloud.
// Execute com LEAD_PGLITE_MODULE apontando para @electric-sql/pglite instalado.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const modulePath = process.env.LEAD_PGLITE_MODULE;
if (!modulePath) throw new Error('Defina LEAD_PGLITE_MODULE com o caminho absoluto do módulo PGlite.');
const { PGlite } = await import(pathToFileURL(modulePath));
const db = new PGlite();
const directory = path.dirname(fileURLToPath(import.meta.url));
const checks = [];
const run = async (name, callback) => {
  await callback();
  checks.push(name);
  console.log(`OK ${name}`);
};
const rows = async sql => (await db.query(sql)).rows;
const count = async sql => Number((await rows(sql))[0].n);
const scalar = async sql => Object.values((await rows(sql))[0])[0];
const id = n => `00000000-0000-0000-0000-${String(n).padStart(12, '0')}`;
const context = async (company = 'company-a', user = 'broker-a', manager = false) => {
  await db.exec('RESET ROLE');
  await db.query("SELECT set_config('app.company_id',$1,false), set_config('app.user_id',$2,false), set_config('app.is_manager',$3,false)", [company,user,String(manager)]);
  await db.exec('SET ROLE lead_runtime');
};
const expectFailure = async callback => {
  let denied = false;
  try { await callback(); } catch { denied = true; }
  assert.ok(denied, 'A operação deveria ser recusada');
};

try {
  // Simula papéis API que já existem no Supabase e verifica os revokes.
  await db.exec('CREATE ROLE anon; CREATE ROLE authenticated; CREATE ROLE service_role BYPASSRLS;');
  const sqlFiles = fs.readdirSync(path.join(directory,'sql')).filter(x => x.endsWith('.sql')).sort();
  for (const file of sqlFiles) {
    await db.exec(fs.readFileSync(path.join(directory,'sql',file),'utf8'));
  }
  await run(`${sqlFiles.length} scripts executam em PostgreSQL isolado`, async () => {
    assert.equal(await count("SELECT count(*) n FROM pg_tables WHERE schemaname IN ('lead','lead_private')"),38);
  });
  await run('38 tabelas com RLS habilitada e forçada', async () => {
    assert.equal(await count("SELECT count(*) n FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('lead','lead_private') AND c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity"),38);
  });
  await db.exec(`
    INSERT INTO lead.tenant_refs(company_id) VALUES ('company-a'),('company-b');
    INSERT INTO lead.actor_refs(company_id,user_id) VALUES ('company-a','broker-a'),('company-a','broker-b'),('company-a','manager-a'),('company-b','broker-c');
    INSERT INTO lead_private.contacts(company_id,id,person_type,display_name,responsible_user_id) VALUES
      ('company-a','${id(1)}','individual','Owner A','broker-a'),
      ('company-a','${id(2)}','individual','Owner B','broker-b'),
      ('company-b','${id(3)}','individual','Owner C','broker-c');
    INSERT INTO lead_private.contact_channels(company_id,id,contact_id,kind,value_ciphertext) VALUES
      ('company-a','${id(4)}','${id(1)}','email','cipher-a'),('company-a','${id(5)}','${id(2)}','phone','cipher-b');
    INSERT INTO lead.properties(company_id,id,reference_code,title,property_type,responsible_user_id) VALUES
      ('company-a','${id(10)}','A-1','Property A','apartment','broker-a'),
      ('company-a','${id(11)}','A-2','Property B','house','broker-b'),
      ('company-b','${id(12)}','B-1','Property C','house','broker-c');
    INSERT INTO lead_private.property_owners(company_id,id,property_id,contact_id) VALUES
      ('company-a','${id(13)}','${id(10)}','${id(1)}'),('company-a','${id(14)}','${id(11)}','${id(2)}');
    INSERT INTO lead.offers(company_id,id,property_id,purpose,price) VALUES
      ('company-a','${id(20)}','${id(10)}','sale',100000),('company-a','${id(21)}','${id(10)}','rent',1000),
      ('company-a','${id(22)}','${id(11)}','sale',200000);
    INSERT INTO lead.funnels(company_id,id,name,purpose) VALUES ('company-a','${id(30)}','Sales','sale');
    INSERT INTO lead.funnel_stages(company_id,id,funnel_id,code,name,position) VALUES ('company-a','${id(31)}','${id(30)}','new','Novo',0);
    INSERT INTO lead.inquiries(company_id,id,interested_contact_id,responsible_user_id,funnel_id,stage_id,purpose,search_title) VALUES
      ('company-a','${id(40)}','${id(1)}','broker-a','${id(30)}','${id(31)}','sale','Search A'),
      ('company-a','${id(41)}','${id(2)}','broker-b','${id(30)}','${id(31)}','sale','Search B');
    INSERT INTO lead.inquiry_properties(company_id,id,inquiry_id,offer_id) VALUES
      ('company-a','${id(42)}','${id(40)}','${id(20)}'),('company-a','${id(43)}','${id(41)}','${id(20)}');
    BEGIN;
    INSERT INTO lead.proposals(company_id,id,inquiry_id,offer_id,inquiry_property_id) VALUES
      ('company-a','${id(50)}','${id(40)}','${id(20)}','${id(42)}'),('company-a','${id(51)}','${id(41)}','${id(20)}','${id(43)}');
    INSERT INTO lead.proposal_versions(company_id,id,proposal_id,version,amount,terms,created_by_user_id) VALUES
      ('company-a','${id(52)}','${id(50)}',1,95000,'{}','broker-a'),('company-a','${id(53)}','${id(51)}',1,96000,'{}','broker-b');
    COMMIT;
    INSERT INTO lead.catalogs(company_id,id,slug,title,enabled) VALUES ('company-a','${id(60)}','agency-a','Agency A',true);
    INSERT INTO lead.publications(company_id,id,catalog_id,offer_id,status,published_at) VALUES ('company-a','${id(61)}','${id(60)}','${id(20)}','published',now());
  `);
  await run('sem contexto, nenhuma empresa ou imóvel fica visível', async () => {
    await context('','',false);
    assert.equal(await count('SELECT count(*) n FROM lead.properties'),0);
    assert.equal(await count('SELECT count(*) n FROM lead.tenant_refs'),0);
  });
  await run('corretor consulta carteira da empresa sem ver outra empresa', async () => {
    await context();
    assert.equal(await count('SELECT count(*) n FROM lead.properties'),2);
    assert.equal(await count("SELECT count(*) n FROM lead.properties WHERE company_id='company-b'"),0);
  });
  await run('corretor vê apenas seus atendimentos, proprietários e canais', async () => {
    assert.equal(await count('SELECT count(*) n FROM lead.inquiries'),1);
    assert.equal(await count('SELECT count(*) n FROM lead_private.property_owners'),1);
    assert.equal(await count('SELECT count(*) n FROM lead_private.contacts'),1);
    assert.equal(await count('SELECT count(*) n FROM lead_private.contact_channels'),1);
  });
  await run('corretor altera somente imóvel sob sua responsabilidade', async () => {
    assert.equal((await rows(`UPDATE lead.properties SET title='Updated own' WHERE id='${id(10)}' RETURNING id`)).length,1);
    assert.equal((await rows(`UPDATE lead.properties SET title='Forbidden' WHERE id='${id(11)}' RETURNING id`)).length,0);
    await expectFailure(()=>db.exec(`INSERT INTO lead.properties(company_id,reference_code,title,property_type,responsible_user_id) VALUES ('company-a','A-3','Forbidden','house','broker-b')`));
  });
  await run('gestor vê todos da sua empresa e nenhum de outra', async () => {
    await context('company-a','manager-a',true);
    assert.equal(await count('SELECT count(*) n FROM lead.inquiries'),2);
    assert.equal(await count('SELECT count(*) n FROM lead_private.contacts'),2);
    assert.equal(await count('SELECT count(*) n FROM lead.properties'),2);
  });
  await run('FK composta recusa proprietário de outra empresa', async () => {
    await expectFailure(()=>db.exec(`INSERT INTO lead_private.property_owners(company_id,property_id,contact_id) VALUES ('company-a','${id(10)}','${id(3)}')`));
  });
  await run('mesmo imóvel admite ofertas independentes de venda e locação', async () => {
    assert.equal(await count(`SELECT count(*) n FROM lead.offers WHERE property_id='${id(10)}'`),2);
    await expectFailure(()=>db.exec(`INSERT INTO lead.offers(company_id,property_id,purpose,price) VALUES ('company-a','${id(10)}','sale',123)`));
  });
  await run('reserva única bloqueia compromisso ativo concorrente', async () => {
    await db.exec(`INSERT INTO lead.reservations(company_id,property_id,offer_id,proposal_id,expires_at) VALUES ('company-a','${id(10)}','${id(20)}','${id(50)}',now()+interval '2 days')`);
    await expectFailure(()=>db.exec(`INSERT INTO lead.reservations(company_id,property_id,offer_id,proposal_id,expires_at) VALUES ('company-a','${id(10)}','${id(20)}','${id(51)}',now()+interval '2 days')`));
  });
  await run('reserva não pode apontar para imóvel diferente da oferta', async () => {
    await expectFailure(()=>db.exec(`INSERT INTO lead.reservations(company_id,property_id,offer_id,proposal_id,expires_at) VALUES ('company-a','${id(11)}','${id(20)}','${id(51)}',now()+interval '2 days')`));
  });
  await run('fechamento exige data de assinatura do contrato', async () => {
    await expectFailure(()=>db.exec(`INSERT INTO lead.deals(company_id,proposal_id,closed_by_user_id,amount) VALUES ('company-a','${id(50)}','manager-a',95000)`));
  });
  await run('históricos e auditoria não permitem atualização ou exclusão pelo runtime', async () => {
    await expectFailure(()=>db.exec('UPDATE lead.proposal_versions SET amount=0'));
    await expectFailure(()=>db.exec('DELETE FROM lead_private.audit_events'));
    await expectFailure(()=>db.exec('TRUNCATE lead.properties'));
  });
  await run('projeção pública mostra só dados comerciais publicados', async () => {
    await db.exec('RESET ROLE; SET ROLE lead_catalog_reader');
    const publicRows = await rows('SELECT * FROM lead_public.catalog_offers');
    assert.equal(publicRows.length,1);
    for(const forbidden of ['company_id','responsible_user_id','contact_id','storage_key','latitude','longitude','address_ciphertext']) assert.ok(!(forbidden in publicRows[0]));
    await expectFailure(()=>db.exec('SELECT * FROM lead_private.contacts'));
  });
  await run('mídia pública exige autorização, visibilidade pública e aprovação do arquivo', async () => {
    await db.exec('RESET ROLE');
    await db.exec(`
      INSERT INTO lead_private.file_objects(company_id,id,uploaded_by_user_id,storage_key,original_name,mime_type,size_bytes,sha256,visibility,scan_status) VALUES
      ('company-a','${id(70)}','broker-a','opaque-pending','pending.jpg','image/jpeg',10,repeat('a',64),'public','pending'),
      ('company-a','${id(71)}','broker-a','opaque-private','private.jpg','image/jpeg',10,repeat('b',64),'private','approved'),
      ('company-a','${id(72)}','broker-a','opaque-approved','approved.jpg','image/jpeg',10,repeat('c',64),'public','approved');
      INSERT INTO lead.property_media(company_id,property_id,file_id,publication_allowed) VALUES
      ('company-a','${id(10)}','${id(70)}',true),('company-a','${id(10)}','${id(71)}',true),('company-a','${id(10)}','${id(72)}',true);
      SET ROLE lead_catalog_reader;
    `);
    const media = await rows('SELECT * FROM lead_public.catalog_media');
    assert.equal(media.length,1);
    assert.ok(!('storage_key' in media[0]));
    assert.ok(!('original_name' in media[0]));
  });
  await run('corretor consulta somente sua parcela de comissão, sem totais ou parcelas alheias', async () => {
    await db.exec('RESET ROLE');
    await db.exec(`
      INSERT INTO lead.deals(company_id,id,proposal_id,contract_signed_at,closed_by_user_id,amount) VALUES ('company-a','${id(80)}','${id(50)}',now(),'manager-a',95000);
      INSERT INTO lead.commissions(company_id,id,deal_id,amount,calculation_snapshot) VALUES ('company-a','${id(81)}','${id(80)}',1000,'{}');
      INSERT INTO lead.commission_shares(company_id,commission_id,beneficiary_kind,user_id,role_in_deal,amount) VALUES
      ('company-a','${id(81)}','user','broker-a','capture',200),
      ('company-a','${id(81)}','user','broker-b','closing',300),
      ('company-a','${id(81)}','company',NULL,'company',500);
    `);
    await context();
    assert.equal(await count('SELECT count(*) n FROM lead.commissions'),0);
    assert.equal(await count('SELECT count(*) n FROM lead.commission_shares'),1);
    assert.equal(Number(await scalar('SELECT amount FROM lead.commission_shares')),200);
  });
  await run('catálogo desativado retira ofertas da projeção pública', async () => {
    await context('company-a','manager-a',true);
    await db.exec('UPDATE lead.catalogs SET enabled=false');
    await db.exec('RESET ROLE; SET ROLE lead_catalog_reader');
    assert.equal(await count('SELECT count(*) n FROM lead_public.catalog_offers'),0);
  });
  await run('admin global consulta métricas, sem acesso aos registros operacionais', async () => {
    await db.exec('RESET ROLE; SET ROLE lead_admin_metrics');
    assert.equal(await count('SELECT count(*) n FROM lead_admin.company_usage'),2);
    await expectFailure(()=>db.exec('SELECT * FROM lead.inquiries'));
    await expectFailure(()=>db.exec('SELECT * FROM lead_private.contacts'));
  });
  await run('papéis públicos do Supabase não acessam tabelas operacionais', async () => {
    for(const role of ['anon','authenticated','service_role']) {
      await db.exec(`RESET ROLE; SET ROLE ${role}`);
      await expectFailure(()=>db.exec('SELECT * FROM lead.properties'));
      await expectFailure(()=>db.exec('SELECT * FROM lead_private.contacts'));
    }
  });
  await run('contexto transacional não permanece após COMMIT', async () => {
    await db.exec('RESET ROLE');
    await db.exec("RESET app.company_id; RESET app.user_id; RESET app.is_manager; BEGIN; SET LOCAL ROLE lead_runtime; SET LOCAL app.company_id='company-a'; SET LOCAL app.user_id='broker-a'; SET LOCAL app.is_manager='false';");
    assert.equal(await count('SELECT count(*) n FROM lead.properties'),2);
    await db.exec('COMMIT; SET ROLE lead_runtime');
    assert.equal(await count('SELECT count(*) n FROM lead.properties'),0);
  });
  console.log(JSON.stringify({passed:checks.length,checks},null,2));
} finally {
  await db.close();
}
