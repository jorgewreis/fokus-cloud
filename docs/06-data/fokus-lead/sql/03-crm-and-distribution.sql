-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15

CREATE TABLE lead.sources (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    code text NOT NULL,
    name text NOT NULL,
    UNIQUE (company_id, code),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT
);

CREATE TABLE lead.funnels (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    name text NOT NULL,
    purpose text NOT NULL CHECK (purpose IN ('sale','rent')),
    is_default boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT
);

CREATE TABLE lead.funnel_stages (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    funnel_id uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    outcome text NOT NULL DEFAULT 'open' CHECK (outcome IN ('open','won','lost')),
    UNIQUE (company_id, funnel_id, id),
    UNIQUE (company_id, funnel_id, code),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, funnel_id) REFERENCES lead.funnels (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.funnel_stages (company_id, funnel_id);

CREATE TABLE lead.inquiries (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    interested_contact_id uuid NOT NULL,
    responsible_user_id varchar(30) NOT NULL,
    source_id uuid,
    funnel_id uuid NOT NULL,
    stage_id uuid NOT NULL,
    purpose text NOT NULL CHECK (purpose IN ('sale','rent')),
    search_title text NOT NULL,
    preferences jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(preferences) = 'object'),
    budget_min numeric(18,2) CHECK (budget_min >= 0),
    budget_max numeric(18,2) CHECK (budget_max >= 0),
    outcome text NOT NULL DEFAULT 'open' CHECK (outcome IN ('open','won','lost')),
    loss_reason text,
    CHECK (budget_min IS NULL OR budget_max IS NULL OR budget_min <= budget_max),
    CHECK (outcome <> 'lost' OR nullif(trim(loss_reason),'') IS NOT NULL),
    FOREIGN KEY (company_id, funnel_id, stage_id) REFERENCES lead.funnel_stages (company_id, funnel_id, id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, interested_contact_id) REFERENCES lead_private.contacts (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, responsible_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, source_id) REFERENCES lead.sources (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, funnel_id) REFERENCES lead.funnels (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.inquiries (company_id, interested_contact_id);
CREATE INDEX ON lead.inquiries (company_id, responsible_user_id);
CREATE INDEX ON lead.inquiries (company_id, source_id);
CREATE INDEX ON lead.inquiries (company_id, funnel_id);

CREATE TABLE lead.inquiry_properties (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    inquiry_id uuid NOT NULL,
    offer_id uuid NOT NULL,
    status text NOT NULL DEFAULT 'suggested' CHECK (status IN ('suggested','interested','visited','discarded','proposed')),
    UNIQUE (company_id, inquiry_id, offer_id),
    UNIQUE (company_id, id, inquiry_id, offer_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, offer_id) REFERENCES lead.offers (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.inquiry_properties (company_id, inquiry_id);
CREATE INDEX ON lead.inquiry_properties (company_id, offer_id);

CREATE TABLE lead.stage_history (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    inquiry_id uuid NOT NULL,
    from_stage_id uuid,
    to_stage_id uuid NOT NULL,
    changed_by_user_id varchar(30) NOT NULL,
    reason text,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, from_stage_id) REFERENCES lead.funnel_stages (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, to_stage_id) REFERENCES lead.funnel_stages (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, changed_by_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.stage_history (company_id, inquiry_id);
CREATE INDEX ON lead.stage_history (company_id, from_stage_id);
CREATE INDEX ON lead.stage_history (company_id, to_stage_id);
CREATE INDEX ON lead.stage_history (company_id, changed_by_user_id);

CREATE TABLE lead.assignment_rules (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    name text NOT NULL,
    source_id uuid,
    property_id uuid,
    mode text NOT NULL CHECK (mode IN ('manual','round_robin')),
    priority integer NOT NULL DEFAULT 0,
    enabled boolean NOT NULL DEFAULT true,
    last_assigned_user_id varchar(30),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, source_id) REFERENCES lead.sources (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, last_assigned_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.assignment_rules (company_id, source_id);
CREATE INDEX ON lead.assignment_rules (company_id, property_id);
CREATE INDEX ON lead.assignment_rules (company_id, last_assigned_user_id);

CREATE TABLE lead.assignment_candidates (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    rule_id uuid NOT NULL,
    user_id varchar(30) NOT NULL,
    position integer NOT NULL DEFAULT 0,
    enabled boolean NOT NULL DEFAULT true,
    UNIQUE (company_id, rule_id, user_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, rule_id) REFERENCES lead.assignment_rules (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.assignment_candidates (company_id, rule_id);
CREATE INDEX ON lead.assignment_candidates (company_id, user_id);

CREATE TABLE lead.assignment_history (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    inquiry_id uuid NOT NULL,
    from_user_id varchar(30),
    to_user_id varchar(30) NOT NULL,
    assigned_by_user_id varchar(30),
    method text NOT NULL CHECK (method IN ('manual','round_robin','reassignment')),
    reason text,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, from_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, to_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, assigned_by_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.assignment_history (company_id, inquiry_id);
CREATE INDEX ON lead.assignment_history (company_id, from_user_id);
CREATE INDEX ON lead.assignment_history (company_id, to_user_id);
CREATE INDEX ON lead.assignment_history (company_id, assigned_by_user_id);

CREATE TABLE lead_private.intake_events (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    source_id uuid,
    inquiry_id uuid,
    idempotency_key text NOT NULL,
    payload_ciphertext text NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','assigned','duplicate','rejected')),
    marketing_consent boolean NOT NULL DEFAULT false,
    consent_notice_version text,
    UNIQUE (company_id, idempotency_key),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, source_id) REFERENCES lead.sources (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.intake_events (company_id, source_id);
CREATE INDEX ON lead_private.intake_events (company_id, inquiry_id);
