-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15

CREATE TABLE lead_private.contacts (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    person_type text NOT NULL CHECK (person_type IN ('individual','organization')),
    display_name text NOT NULL CHECK (length(trim(display_name)) > 0),
    tax_id_ciphertext text,
    responsible_user_id varchar(30) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, responsible_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.contacts (company_id, responsible_user_id);

CREATE TABLE lead_private.contact_channels (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    contact_id uuid NOT NULL,
    kind text NOT NULL CHECK (kind IN ('phone','email','whatsapp','other')),
    value_ciphertext text NOT NULL,
    deduplication_hash text,
    is_primary boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, contact_id) REFERENCES lead_private.contacts (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.contact_channels (company_id, contact_id);

CREATE TABLE lead.developments (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    name text NOT NULL,
    kind text NOT NULL CHECK (kind IN ('building','condominium','subdivision','other')),
    city text,
    state char(2),
    description text,
    responsible_user_id varchar(30) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, responsible_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.developments (company_id, responsible_user_id);

CREATE TABLE lead.properties (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    reference_code text NOT NULL,
    title text NOT NULL,
    property_type text NOT NULL,
    development_id uuid,
    unit_reference text,
    responsible_user_id varchar(30) NOT NULL,
    city text,
    state char(2),
    neighborhood text,
    bedrooms smallint CHECK (bedrooms >= 0),
    bathrooms smallint CHECK (bathrooms >= 0),
    parking_spaces smallint CHECK (parking_spaces >= 0),
    private_area_m2 numeric(14,2) CHECK (private_area_m2 >= 0),
    total_area_m2 numeric(14,2) CHECK (total_area_m2 >= 0),
    commercial_description text,
    features jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(features) = 'object'),
    UNIQUE (company_id, reference_code),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, development_id) REFERENCES lead.developments (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, responsible_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.properties (company_id, development_id);
CREATE INDEX ON lead.properties (company_id, responsible_user_id);

CREATE TABLE lead_private.property_locations (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    property_id uuid NOT NULL,
    address_ciphertext text NOT NULL,
    latitude numeric(10,7) CHECK (latitude BETWEEN -90 AND 90),
    longitude numeric(10,7) CHECK (longitude BETWEEN -180 AND 180),
    UNIQUE (company_id, property_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.property_locations (company_id, property_id);

CREATE TABLE lead_private.property_owners (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    property_id uuid NOT NULL,
    contact_id uuid NOT NULL,
    ownership_percentage numeric(7,4) CHECK (ownership_percentage > 0 AND ownership_percentage <= 100),
    is_representative boolean NOT NULL DEFAULT false,
    UNIQUE (company_id, property_id, contact_id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, contact_id) REFERENCES lead_private.contacts (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead_private.property_owners (company_id, property_id);
CREATE INDEX ON lead_private.property_owners (company_id, contact_id);

CREATE TABLE lead.offers (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    property_id uuid NOT NULL,
    purpose text NOT NULL CHECK (purpose IN ('sale','rent')),
    price numeric(18,2) CHECK (price >= 0),
    price_on_request boolean NOT NULL DEFAULT false,
    condominium_fee numeric(18,2) CHECK (condominium_fee >= 0),
    annual_property_tax numeric(18,2) CHECK (annual_property_tax >= 0),
    conditions text,
    status text NOT NULL DEFAULT 'available' CHECK (status IN ('available','reserved','sold','rented','unavailable')),
    CHECK ((price_on_request AND price IS NULL) OR (NOT price_on_request AND price IS NOT NULL)),
    CHECK ((status <> 'sold' OR purpose = 'sale') AND (status <> 'rented' OR purpose = 'rent')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, property_id) REFERENCES lead.properties (company_id, id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.offers (company_id, property_id);

CREATE UNIQUE INDEX offers_active_purpose_unique ON lead.offers (company_id, property_id, purpose) WHERE archived_at IS NULL;

CREATE TABLE lead.offer_versions (
    company_id varchar(30) NOT NULL,
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    offer_id uuid NOT NULL,
    version integer NOT NULL CHECK (version > 0),
    snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
    recorded_by_user_id varchar(30) NOT NULL,
    UNIQUE (company_id, offer_id, version),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    FOREIGN KEY (company_id) REFERENCES lead.tenant_refs(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, offer_id) REFERENCES lead.offers (company_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id, recorded_by_user_id) REFERENCES lead.actor_refs (company_id, user_id) ON DELETE RESTRICT
);
CREATE INDEX ON lead.offer_versions (company_id, offer_id);
CREATE INDEX ON lead.offer_versions (company_id, recorded_by_user_id);
