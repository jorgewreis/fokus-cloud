-- Validação transacional com dados sintéticos; NÃO executar como migration.
-- As associações temporárias de papéis e todas as fixtures são desfeitas por
-- ROLLBACK. Requer a conta postgres de administração, não a credencial runtime.
BEGIN;
GRANT lead_runtime, lead_catalog_reader, lead_admin_metrics TO postgres WITH SET TRUE;

INSERT INTO lead.tenant_refs(company_id) VALUES ('__lead_verify_a'), ('__lead_verify_b');
INSERT INTO lead.actor_refs(company_id,user_id) VALUES
('__lead_verify_a','verify-broker-a'),('__lead_verify_a','verify-broker-b'),
('__lead_verify_a','verify-manager'),('__lead_verify_b','verify-broker-c');
INSERT INTO lead_private.contacts(company_id,id,person_type,display_name,responsible_user_id) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000001','individual','Synthetic A','verify-broker-a'),
('__lead_verify_a','10000000-0000-0000-0000-000000000002','individual','Synthetic B','verify-broker-b'),
('__lead_verify_b','10000000-0000-0000-0000-000000000003','individual','Synthetic C','verify-broker-c');
INSERT INTO lead_private.contact_channels(company_id,contact_id,kind,value_ciphertext) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000001','email','synthetic-cipher-a'),
('__lead_verify_a','10000000-0000-0000-0000-000000000002','phone','synthetic-cipher-b');
INSERT INTO lead.properties(company_id,id,reference_code,title,property_type,responsible_user_id) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000010','VERIFY-A1','Synthetic A','house','verify-broker-a'),
('__lead_verify_a','10000000-0000-0000-0000-000000000011','VERIFY-A2','Synthetic B','apartment','verify-broker-b'),
('__lead_verify_b','10000000-0000-0000-0000-000000000012','VERIFY-B1','Synthetic C','house','verify-broker-c');
INSERT INTO lead_private.property_owners(company_id,property_id,contact_id) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000010','10000000-0000-0000-0000-000000000001'),
('__lead_verify_a','10000000-0000-0000-0000-000000000011','10000000-0000-0000-0000-000000000002');
INSERT INTO lead.offers(company_id,id,property_id,purpose,price) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000020','10000000-0000-0000-0000-000000000010','sale',100000),
('__lead_verify_a','10000000-0000-0000-0000-000000000021','10000000-0000-0000-0000-000000000010','rent',1000);
INSERT INTO lead.funnels(company_id,id,name,purpose) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000030','Synthetic Sales','sale');
INSERT INTO lead.funnel_stages(company_id,id,funnel_id,code,name,position) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000031','10000000-0000-0000-0000-000000000030','new','Novo',0);
INSERT INTO lead.inquiries(company_id,id,interested_contact_id,responsible_user_id,funnel_id,stage_id,purpose,search_title) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000040','10000000-0000-0000-0000-000000000001','verify-broker-a','10000000-0000-0000-0000-000000000030','10000000-0000-0000-0000-000000000031','sale','Synthetic Search A'),
('__lead_verify_a','10000000-0000-0000-0000-000000000041','10000000-0000-0000-0000-000000000002','verify-broker-b','10000000-0000-0000-0000-000000000030','10000000-0000-0000-0000-000000000031','sale','Synthetic Search B');
INSERT INTO lead.inquiry_properties(company_id,id,inquiry_id,offer_id) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000042','10000000-0000-0000-0000-000000000040','10000000-0000-0000-0000-000000000020'),
('__lead_verify_a','10000000-0000-0000-0000-000000000043','10000000-0000-0000-0000-000000000041','10000000-0000-0000-0000-000000000020');
INSERT INTO lead.proposals(company_id,id,inquiry_id,offer_id,inquiry_property_id) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000050','10000000-0000-0000-0000-000000000040','10000000-0000-0000-0000-000000000020','10000000-0000-0000-0000-000000000042'),
('__lead_verify_a','10000000-0000-0000-0000-000000000051','10000000-0000-0000-0000-000000000041','10000000-0000-0000-0000-000000000020','10000000-0000-0000-0000-000000000043');
INSERT INTO lead.proposal_versions(company_id,proposal_id,version,amount,terms,created_by_user_id) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000050',1,95000,'{}','verify-broker-a'),
('__lead_verify_a','10000000-0000-0000-0000-000000000051',1,96000,'{}','verify-broker-b');
INSERT INTO lead.catalogs(company_id,id,slug,title,enabled) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000060','synthetic-verification','Synthetic Catalog',true);
INSERT INTO lead.publications(company_id,catalog_id,offer_id,status,published_at) VALUES
('__lead_verify_a','10000000-0000-0000-0000-000000000060','10000000-0000-0000-0000-000000000020','published',now());
SET CONSTRAINTS ALL IMMEDIATE;

SET LOCAL ROLE lead_runtime;
SELECT set_config('app.company_id','__lead_verify_a',true),
       set_config('app.user_id','verify-broker-a',true),
       set_config('app.is_manager','false',true);
DO $assert$
DECLARE affected integer;
BEGIN
    IF (SELECT count(*) FROM lead.properties) <> 2 THEN RAISE EXCEPTION 'FAIL: tenant property isolation'; END IF;
    IF (SELECT count(*) FROM lead.inquiries) <> 1 THEN RAISE EXCEPTION 'FAIL: broker inquiry visibility'; END IF;
    IF (SELECT count(*) FROM lead_private.contacts) <> 1 THEN RAISE EXCEPTION 'FAIL: broker contact visibility'; END IF;
    IF (SELECT count(*) FROM lead_private.contact_channels) <> 1 THEN RAISE EXCEPTION 'FAIL: broker channels visibility'; END IF;
    IF (SELECT count(*) FROM lead_private.property_owners) <> 1 THEN RAISE EXCEPTION 'FAIL: property owner visibility'; END IF;
    UPDATE lead.properties SET title='Synthetic forbidden' WHERE id='10000000-0000-0000-0000-000000000011';
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> 0 THEN RAISE EXCEPTION 'FAIL: broker edited another broker property'; END IF;
    UPDATE lead.properties SET title='Synthetic own update' WHERE id='10000000-0000-0000-0000-000000000010';
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> 1 THEN RAISE EXCEPTION 'FAIL: own property not editable'; END IF;
    BEGIN
        INSERT INTO lead_private.property_owners(company_id,property_id,contact_id)
        VALUES ('__lead_verify_a','10000000-0000-0000-0000-000000000010','10000000-0000-0000-0000-000000000003');
        RAISE EXCEPTION 'FAIL: cross-company FK accepted';
    EXCEPTION WHEN foreign_key_violation THEN NULL;
    END;
    IF has_table_privilege(current_user,'lead_private.audit_events','DELETE')
       OR has_table_privilege(current_user,'lead.proposal_versions','UPDATE')
       OR has_table_privilege(current_user,'lead.properties','TRUNCATE')
    THEN RAISE EXCEPTION 'FAIL: destructive privileges'; END IF;
END
$assert$;

SELECT set_config('app.company_id','',true),set_config('app.user_id','',true);
DO $assert$
BEGIN
    IF EXISTS (SELECT 1 FROM lead.properties) THEN RAISE EXCEPTION 'FAIL: context-free read'; END IF;
END
$assert$;
SELECT set_config('app.company_id','__lead_verify_a',true),set_config('app.user_id','verify-manager',true),set_config('app.is_manager','true',true);
DO $assert$
BEGIN
    IF (SELECT count(*) FROM lead.inquiries) <> 2 OR (SELECT count(*) FROM lead_private.contacts) <> 2
    THEN RAISE EXCEPTION 'FAIL: manager visibility'; END IF;
    INSERT INTO lead.reservations(company_id,property_id,offer_id,proposal_id,expires_at)
    VALUES ('__lead_verify_a','10000000-0000-0000-0000-000000000010','10000000-0000-0000-0000-000000000020','10000000-0000-0000-0000-000000000050',now()+interval '2 days');
    BEGIN
        INSERT INTO lead.reservations(company_id,property_id,offer_id,proposal_id,expires_at)
        VALUES ('__lead_verify_a','10000000-0000-0000-0000-000000000010','10000000-0000-0000-0000-000000000020','10000000-0000-0000-0000-000000000051',now()+interval '2 days');
        RAISE EXCEPTION 'FAIL: concurrent active reservation accepted';
    EXCEPTION WHEN unique_violation THEN NULL;
    END;
    BEGIN
        INSERT INTO lead.deals(company_id,proposal_id,closed_by_user_id,amount)
        VALUES ('__lead_verify_a','10000000-0000-0000-0000-000000000050','verify-manager',95000);
        RAISE EXCEPTION 'FAIL: unsigned deal accepted';
    EXCEPTION WHEN not_null_violation THEN NULL;
    END;
END
$assert$;

RESET ROLE;
SET LOCAL ROLE lead_admin_metrics;
DO $assert$
BEGIN
    IF (SELECT count(*) FROM lead_admin.company_usage WHERE company_id LIKE '__lead_verify_%') <> 2
    THEN RAISE EXCEPTION 'FAIL: administrative metrics'; END IF;
    BEGIN
        PERFORM 1 FROM lead_private.contacts;
        RAISE EXCEPTION 'FAIL: metrics role reads contacts';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
END
$assert$;
RESET ROLE;
SET LOCAL ROLE lead_catalog_reader;
DO $assert$
BEGIN
    IF (SELECT count(*) FROM lead_public.catalog_offers WHERE catalog_slug='synthetic-verification') <> 1
    THEN RAISE EXCEPTION 'FAIL: public catalog'; END IF;
    BEGIN
        PERFORM 1 FROM lead_private.contacts;
        RAISE EXCEPTION 'FAIL: public role reads contacts';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
END
$assert$;
RESET ROLE;
ROLLBACK;

SELECT 'PASS: native RLS, ownership, tenant FKs, reservations, signature, public and admin scopes' AS verification,
       (SELECT count(*) FROM lead.tenant_refs WHERE company_id IN ('__lead_verify_a','__lead_verify_b')) AS remaining_fixture_tenants,
       pg_has_role(current_user,'lead_runtime','SET') AS persistent_runtime_membership;
