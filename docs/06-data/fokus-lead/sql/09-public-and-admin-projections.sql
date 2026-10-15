-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15
-- Views deliberadamente de projeção, executadas com privilégios do proprietário
-- de migrations (capaz de ler as bases sob FORCE RLS). Não mudar para o runtime.
-- A conta proprietária não é a credencial de execução da aplicação.
CREATE VIEW lead_public.catalog_offers WITH (security_barrier = true) AS
SELECT c.slug AS catalog_slug, c.title AS catalog_title, pub.public_id,
       p.title, p.property_type, p.city, p.state, p.neighborhood,
       p.bedrooms, p.bathrooms, p.parking_spaces, p.private_area_m2,
       p.total_area_m2, p.commercial_description,
       o.purpose, o.price, o.price_on_request, o.condominium_fee,
       o.annual_property_tax, o.status AS availability
FROM lead.publications pub
JOIN lead.catalogs c ON c.company_id = pub.company_id AND c.id = pub.catalog_id
JOIN lead.offers o ON o.company_id = pub.company_id AND o.id = pub.offer_id
JOIN lead.properties p ON p.company_id = o.company_id AND p.id = o.property_id
WHERE c.enabled AND c.archived_at IS NULL
  AND pub.status = 'published' AND pub.archived_at IS NULL
  AND o.status IN ('available','reserved') AND o.archived_at IS NULL
  AND p.archived_at IS NULL;

CREATE VIEW lead_public.catalog_media WITH (security_barrier = true) AS
SELECT pub.public_id, f.public_token AS asset_token, m.caption, m.position
FROM lead.publications pub
JOIN lead.catalogs c ON c.company_id = pub.company_id AND c.id = pub.catalog_id
JOIN lead.offers o ON o.company_id = pub.company_id AND o.id = pub.offer_id
JOIN lead.properties p ON p.company_id = o.company_id AND p.id = o.property_id
JOIN lead.property_media m ON m.company_id = p.company_id AND m.property_id = p.id
JOIN lead_private.file_objects f ON f.company_id = m.company_id AND f.id = m.file_id
WHERE c.enabled AND c.archived_at IS NULL
  AND pub.status = 'published' AND pub.archived_at IS NULL
  AND o.status IN ('available','reserved') AND o.archived_at IS NULL
  AND p.archived_at IS NULL AND m.archived_at IS NULL
  AND m.publication_allowed AND f.visibility = 'public'
  AND f.scan_status = 'approved' AND f.archived_at IS NULL;

CREATE VIEW lead_admin.company_usage WITH (security_barrier = true) AS
SELECT t.company_id,
 (SELECT count(*) FROM lead.properties x WHERE x.company_id = t.company_id AND x.archived_at IS NULL) AS properties_count,
 (SELECT count(*) FROM lead.developments x WHERE x.company_id = t.company_id AND x.archived_at IS NULL) AS developments_count,
 (SELECT count(*) FROM lead_private.contacts x WHERE x.company_id = t.company_id AND x.archived_at IS NULL) AS contacts_count,
 (SELECT count(*) FROM lead.inquiries x WHERE x.company_id = t.company_id AND x.archived_at IS NULL) AS inquiries_count,
 (SELECT count(*) FROM lead.actor_refs x WHERE x.company_id = t.company_id) AS referenced_users_count,
 (SELECT coalesce(sum(x.size_bytes),0) FROM lead_private.file_objects x WHERE x.company_id = t.company_id AND x.archived_at IS NULL) AS storage_bytes
FROM lead.tenant_refs t;

REVOKE ALL ON ALL TABLES IN SCHEMA lead_public, lead_admin FROM PUBLIC;
DO $public_api_roles$
DECLARE r text;
BEGIN
    FOREACH r IN ARRAY ARRAY['anon', 'authenticated', 'service_role'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
            EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA lead_public, lead_admin FROM %I', r);
        END IF;
    END LOOP;
END
$public_api_roles$;
GRANT USAGE ON SCHEMA lead_public TO lead_catalog_reader;
GRANT SELECT ON lead_public.catalog_offers, lead_public.catalog_media TO lead_catalog_reader;
GRANT USAGE ON SCHEMA lead_admin TO lead_admin_metrics;
GRANT SELECT ON lead_admin.company_usage TO lead_admin_metrics;
