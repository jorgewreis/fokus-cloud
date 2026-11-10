-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15
-- MODELO DE REFERÊNCIA: não integra as migrations Laravel do Cloud.
-- Executar somente em banco de teste vazio, com o proprietário de migrations.
-- Não cria logins, senhas, contas Supabase Auth ou tabelas de identidade.
CREATE SCHEMA lead;
CREATE SCHEMA lead_private;
CREATE SCHEMA lead_public;
CREATE SCHEMA lead_admin;
REVOKE ALL ON SCHEMA lead, lead_private, lead_public, lead_admin FROM PUBLIC;

DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lead_runtime') THEN
        CREATE ROLE lead_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lead_catalog_reader') THEN
        CREATE ROLE lead_catalog_reader NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lead_admin_metrics') THEN
        CREATE ROLE lead_admin_metrics NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$roles$;

-- GUCs são preenchidas EXCLUSIVAMENTE pelo backend confiável, com SET LOCAL
-- dentro de transação. Não são prova de identidade e não podem vir do cliente.
CREATE FUNCTION lead.current_company_id() RETURNS text LANGUAGE sql STABLE
SET search_path = pg_catalog AS $$ SELECT nullif(current_setting('app.company_id', true), '') $$;
CREATE FUNCTION lead.current_user_id() RETURNS text LANGUAGE sql STABLE
SET search_path = pg_catalog AS $$ SELECT nullif(current_setting('app.user_id', true), '') $$;
CREATE FUNCTION lead.is_manager() RETURNS boolean LANGUAGE sql STABLE
SET search_path = pg_catalog AS $$ SELECT coalesce(current_setting('app.is_manager', true) = 'true', false) $$;
CREATE FUNCTION lead.touch_updated_at() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END $$;

-- Referências técnicas, não novas empresas ou usuários. IDs compatíveis com o
-- Cloud atual (char(30)); não se inferem UUIDs para identidades já existentes.
CREATE TABLE lead.tenant_refs (
    company_id varchar(30) PRIMARY KEY,
    provisioned_at timestamptz NOT NULL DEFAULT now(),
    CHECK (length(company_id) BETWEEN 1 AND 30)
);
CREATE TABLE lead.actor_refs (
    company_id varchar(30) NOT NULL REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    user_id varchar(30) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, user_id),
    CHECK (length(user_id) BETWEEN 1 AND 30)
);

CREATE TABLE lead.tenant_settings (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    timezone text NOT NULL DEFAULT 'America/Bahia',
    currency char(3) NOT NULL DEFAULT 'BRL' CHECK (currency = 'BRL'),
    assignment_mode text NOT NULL DEFAULT 'manual' CHECK (assignment_mode IN ('manual','round_robin')),
    reservation_hours integer NOT NULL DEFAULT 48 CHECK (reservation_hours BETWEEN 1 AND 8760),
    UNIQUE (company_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT
);
