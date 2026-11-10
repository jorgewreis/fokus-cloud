-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15

CREATE TABLE lead_private.file_objects (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    uploaded_by_user_id varchar(30) NOT NULL,
    storage_provider text NOT NULL DEFAULT 'self_hosted' CHECK (storage_provider = 'self_hosted'),
    storage_key text NOT NULL,
    original_name text NOT NULL,
    mime_type text NOT NULL,
    size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
    sha256 char(64) NOT NULL,
    visibility text NOT NULL DEFAULT 'private' CHECK (visibility IN ('private','public')),
    scan_status text NOT NULL DEFAULT 'pending' CHECK (scan_status IN ('pending','approved','rejected','failed')),
    public_token uuid NOT NULL DEFAULT gen_random_uuid(),
    UNIQUE (company_id, storage_key),
    UNIQUE (public_token),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, uploaded_by_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.file_objects (company_id, uploaded_by_user_id);

CREATE TABLE lead.property_media (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    property_id uuid NOT NULL,
    file_id uuid NOT NULL,
    caption text,
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    publication_allowed boolean NOT NULL DEFAULT false,
    UNIQUE (company_id, property_id, file_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, file_id) REFERENCES lead_private.file_objects (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.property_media (company_id, property_id);
CREATE INDEX ON lead.property_media (company_id, file_id);

CREATE TABLE lead_private.document_links (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    file_id uuid NOT NULL,
    contact_id uuid,
    property_id uuid,
    inquiry_id uuid,
    proposal_id uuid,
    deal_id uuid,
    document_kind text NOT NULL,
    CHECK (num_nonnulls(contact_id,property_id,inquiry_id,proposal_id,deal_id) = 1),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, file_id) REFERENCES lead_private.file_objects (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, contact_id) REFERENCES lead_private.contacts (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, inquiry_id) REFERENCES lead.inquiries (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, proposal_id) REFERENCES lead.proposals (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, deal_id) REFERENCES lead.deals (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.document_links (company_id, file_id);
CREATE INDEX ON lead_private.document_links (company_id, contact_id);
CREATE INDEX ON lead_private.document_links (company_id, property_id);
CREATE INDEX ON lead_private.document_links (company_id, inquiry_id);
CREATE INDEX ON lead_private.document_links (company_id, proposal_id);
CREATE INDEX ON lead_private.document_links (company_id, deal_id);

CREATE TABLE lead.catalogs (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    slug text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    title text NOT NULL,
    enabled boolean NOT NULL DEFAULT false,
    UNIQUE (company_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT
);

CREATE TABLE lead.publications (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    catalog_id uuid NOT NULL,
    offer_id uuid NOT NULL,
    public_id uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','paused','closed')),
    published_at timestamptz,
    CHECK (status <> 'published' OR published_at IS NOT NULL),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, catalog_id) REFERENCES lead.catalogs (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, offer_id) REFERENCES lead.offers (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.publications (company_id, catalog_id);
CREATE INDEX ON lead.publications (company_id, offer_id);

CREATE UNIQUE INDEX one_publication_per_offer ON lead.publications (company_id, catalog_id, offer_id) WHERE archived_at IS NULL;
