-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15

CREATE TABLE lead_private.audit_events (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    actor_user_id varchar(30),
    support_session_ref varchar(30),
    request_id uuid NOT NULL,
    entity_type text NOT NULL,
    entity_id text NOT NULL,
    action text NOT NULL,
    reason text,
    sanitized_changes jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(sanitized_changes) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, actor_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.audit_events (company_id, actor_user_id);

CREATE TABLE lead_private.outbox_events (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    event_type text NOT NULL,
    aggregate_id uuid NOT NULL,
    idempotency_key text NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    available_at timestamptz NOT NULL DEFAULT now(),
    processed_at timestamptz,
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    UNIQUE (company_id, idempotency_key),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT
);
