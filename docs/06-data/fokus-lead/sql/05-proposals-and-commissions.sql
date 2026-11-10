-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15

CREATE TABLE lead.proposals (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    inquiry_id uuid NOT NULL,
    offer_id uuid NOT NULL,
    inquiry_property_id uuid NOT NULL,
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','sent','accepted','refused','withdrawn','expired','superseded')),
    expires_at timestamptz,
    accepted_at timestamptz,
    current_version integer NOT NULL DEFAULT 1 CHECK (current_version > 0),
    CHECK (status = 'draft' OR expires_at IS NOT NULL),
    CHECK (status <> 'accepted' OR accepted_at IS NOT NULL),
    UNIQUE (company_id, id, offer_id),
    FOREIGN KEY (company_id, inquiry_property_id, inquiry_id, offer_id) REFERENCES lead.inquiry_properties (company_id, id, inquiry_id, offer_id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, offer_id) REFERENCES lead.offers (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.proposals (company_id, inquiry_id);
CREATE INDEX ON lead.proposals (company_id, offer_id);

CREATE TABLE lead.proposal_versions (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    proposal_id uuid NOT NULL,
    version integer NOT NULL CHECK (version > 0),
    amount numeric(18,2) NOT NULL CHECK (amount >= 0),
    terms jsonb NOT NULL CHECK (jsonb_typeof(terms) = 'object'),
    created_by_user_id varchar(30) NOT NULL,
    UNIQUE (company_id, proposal_id, version),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, proposal_id) REFERENCES lead.proposals (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, created_by_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.proposal_versions (company_id, proposal_id);
CREATE INDEX ON lead.proposal_versions (company_id, created_by_user_id);

ALTER TABLE lead.proposals ADD CONSTRAINT proposal_current_version_fk
FOREIGN KEY (company_id, id, current_version) REFERENCES lead.proposal_versions (company_id, proposal_id, version)
DEFERRABLE INITIALLY DEFERRED;

CREATE TABLE lead.reservations (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    property_id uuid NOT NULL,
    offer_id uuid NOT NULL,
    proposal_id uuid NOT NULL,
    starts_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','expired','released','converted')),
    ended_at timestamptz,
    CHECK (expires_at > starts_at),
    FOREIGN KEY (company_id, proposal_id, offer_id) REFERENCES lead.proposals (company_id, id, offer_id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, offer_id) REFERENCES lead.offers (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, proposal_id) REFERENCES lead.proposals (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.reservations (company_id, property_id);
CREATE INDEX ON lead.reservations (company_id, offer_id);
CREATE INDEX ON lead.reservations (company_id, proposal_id);

-- Conservador: um compromisso ativo por bem. Expiração deve mudar o estado em
-- transação; não se usa now() no predicado de índice. Ver decisões técnicas.
CREATE UNIQUE INDEX one_active_reservation_per_property ON lead.reservations (company_id, property_id) WHERE status = 'active';
CREATE UNIQUE INDEX one_active_reservation_per_proposal ON lead.reservations (company_id, proposal_id) WHERE status = 'active';
ALTER TABLE lead.offers ADD CONSTRAINT offer_property_identity UNIQUE (company_id, id, property_id);
ALTER TABLE lead.reservations ADD CONSTRAINT reservation_offer_property_fk FOREIGN KEY (company_id, offer_id, property_id) REFERENCES lead.offers (company_id, id, property_id) ON DELETE RESTRICT;

CREATE TABLE lead.deals (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    proposal_id uuid NOT NULL,
    contract_signed_at timestamptz NOT NULL,
    closed_by_user_id varchar(30) NOT NULL,
    amount numeric(18,2) NOT NULL CHECK (amount >= 0),
    status text NOT NULL DEFAULT 'closed' CHECK (status IN ('closed','reversed')),
    reversal_reason text,
    UNIQUE (company_id, proposal_id),
    CHECK (status <> 'reversed' OR nullif(trim(reversal_reason),'') IS NOT NULL),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, proposal_id) REFERENCES lead.proposals (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, closed_by_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.deals (company_id, proposal_id);
CREATE INDEX ON lead.deals (company_id, closed_by_user_id);

CREATE TABLE lead.commissions (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    deal_id uuid NOT NULL,
    amount numeric(18,2) NOT NULL CHECK (amount >= 0),
    calculation_snapshot jsonb NOT NULL CHECK (jsonb_typeof(calculation_snapshot) = 'object'),
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','approved','cancelled')),
    approved_at timestamptz,
    UNIQUE (company_id, deal_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, deal_id) REFERENCES lead.deals (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.commissions (company_id, deal_id);

CREATE TABLE lead.commission_shares (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    commission_id uuid NOT NULL,
    beneficiary_kind text NOT NULL CHECK (beneficiary_kind IN ('company','user')),
    user_id varchar(30),
    role_in_deal text NOT NULL CHECK (role_in_deal IN ('company','capture','closing','other')),
    amount numeric(18,2) NOT NULL CHECK (amount >= 0),
    CHECK ((beneficiary_kind = 'company' AND user_id IS NULL) OR (beneficiary_kind = 'user' AND user_id IS NOT NULL)),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, commission_id) REFERENCES lead.commissions (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.commission_shares (company_id, commission_id);
CREATE INDEX ON lead.commission_shares (company_id, user_id);
