-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15

CREATE TABLE lead.activities (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    inquiry_id uuid NOT NULL,
    actor_user_id varchar(30) NOT NULL,
    kind text NOT NULL CHECK (kind IN ('note','call','email','message','other')),
    body text NOT NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, actor_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.activities (company_id, inquiry_id);
CREATE INDEX ON lead.activities (company_id, actor_user_id);

CREATE TABLE lead.tasks (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    inquiry_id uuid,
    property_id uuid,
    assigned_user_id varchar(30) NOT NULL,
    title text NOT NULL,
    description text,
    due_at timestamptz,
    completed_at timestamptz,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','completed','cancelled')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, assigned_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.tasks (company_id, inquiry_id);
CREATE INDEX ON lead.tasks (company_id, property_id);
CREATE INDEX ON lead.tasks (company_id, assigned_user_id);

CREATE TABLE lead.visits (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    inquiry_property_id uuid NOT NULL,
    inquiry_id uuid NOT NULL,
    offer_id uuid NOT NULL,
    assigned_user_id varchar(30) NOT NULL,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    status text NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','completed','cancelled','no_show')),
    feedback text,
    CHECK (ends_at > starts_at),
    FOREIGN KEY (company_id, inquiry_property_id, inquiry_id, offer_id) REFERENCES lead.inquiry_properties (company_id, id, inquiry_id, offer_id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, offer_id) REFERENCES lead.offers (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, assigned_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.visits (company_id, inquiry_id);
CREATE INDEX ON lead.visits (company_id, offer_id);
CREATE INDEX ON lead.visits (company_id, assigned_user_id);

CREATE TABLE lead.reminders (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    user_id varchar(30) NOT NULL,
    task_id uuid,
    visit_id uuid,
    scheduled_at timestamptz NOT NULL,
    delivered_at timestamptz,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','delivered','cancelled','failed')),
    CHECK (num_nonnulls(task_id,visit_id) = 1),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, task_id) REFERENCES lead.tasks (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, visit_id) REFERENCES lead.visits (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.reminders (company_id, user_id);
CREATE INDEX ON lead.reminders (company_id, task_id);
CREATE INDEX ON lead.reminders (company_id, visit_id);
