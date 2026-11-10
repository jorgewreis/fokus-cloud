-- Fokus Lead | modelo PostgreSQL v0.1 | 2026-10-15
-- Somente o backend recebe associação a lead_runtime, fora deste arquivo.
-- Nunca associar anon, authenticated, service_role ou usuários finais a esse papel.
GRANT USAGE ON SCHEMA lead, lead_private TO lead_runtime;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA lead FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lead.current_company_id(), lead.current_user_id(), lead.is_manager() TO lead_runtime;

ALTER TABLE lead.tenant_refs ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.tenant_refs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.tenant_refs AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.tenant_refs FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.tenant_refs TO lead_runtime;

ALTER TABLE lead.actor_refs ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.actor_refs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.actor_refs AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.actor_refs FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.actor_refs TO lead_runtime;

ALTER TABLE lead.tenant_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.tenant_settings FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.tenant_settings AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.tenant_settings FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.tenant_settings TO lead_runtime;
CREATE POLICY scoped_insert ON lead.tenant_settings FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.tenant_settings TO lead_runtime;
CREATE POLICY scoped_update ON lead.tenant_settings FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.tenant_settings TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.tenant_settings FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead_private.contacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.contacts FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.contacts AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.contacts FOR SELECT TO lead_runtime USING (((lead.is_manager() OR responsible_user_id = lead.current_user_id()) OR EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead_private.contacts.company_id AND p.interested_contact_id = lead_private.contacts.id) OR EXISTS (SELECT 1 FROM lead_private.property_owners p WHERE p.company_id = lead_private.contacts.company_id AND p.contact_id = lead_private.contacts.id)));
GRANT SELECT ON lead_private.contacts TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.contacts FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT INSERT ON lead_private.contacts TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.contacts FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR responsible_user_id = lead.current_user_id())) WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT UPDATE ON lead_private.contacts TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.contacts FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead_private.contact_channels ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.contact_channels FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.contact_channels AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.contact_channels FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.contact_channels.company_id AND p.id = lead_private.contact_channels.contact_id AND (true)));
GRANT SELECT ON lead_private.contact_channels TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.contact_channels FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.contact_channels.company_id AND p.id = lead_private.contact_channels.contact_id AND (true)));
GRANT INSERT ON lead_private.contact_channels TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.contact_channels FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.contact_channels.company_id AND p.id = lead_private.contact_channels.contact_id AND (true))) WITH CHECK (EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.contact_channels.company_id AND p.id = lead_private.contact_channels.contact_id AND (true)));
GRANT UPDATE ON lead_private.contact_channels TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.contact_channels FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.developments ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.developments FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.developments AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.developments FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.developments TO lead_runtime;
CREATE POLICY scoped_insert ON lead.developments FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT INSERT ON lead.developments TO lead_runtime;
CREATE POLICY scoped_update ON lead.developments FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR responsible_user_id = lead.current_user_id())) WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT UPDATE ON lead.developments TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.developments FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.properties ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.properties FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.properties AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.properties FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.properties TO lead_runtime;
CREATE POLICY scoped_insert ON lead.properties FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT INSERT ON lead.properties TO lead_runtime;
CREATE POLICY scoped_update ON lead.properties FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR responsible_user_id = lead.current_user_id())) WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT UPDATE ON lead.properties TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.properties FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead_private.property_locations ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.property_locations FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.property_locations AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.property_locations FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_locations.company_id AND p.id = lead_private.property_locations.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT SELECT ON lead_private.property_locations TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.property_locations FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_locations.company_id AND p.id = lead_private.property_locations.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT INSERT ON lead_private.property_locations TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.property_locations FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_locations.company_id AND p.id = lead_private.property_locations.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id()))) WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_locations.company_id AND p.id = lead_private.property_locations.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT UPDATE ON lead_private.property_locations TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.property_locations FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead_private.property_owners ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.property_owners FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.property_owners AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.property_owners FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_owners.company_id AND p.id = lead_private.property_owners.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT SELECT ON lead_private.property_owners TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.property_owners FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_owners.company_id AND p.id = lead_private.property_owners.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT INSERT ON lead_private.property_owners TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.property_owners FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_owners.company_id AND p.id = lead_private.property_owners.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id()))) WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.property_owners.company_id AND p.id = lead_private.property_owners.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT UPDATE ON lead_private.property_owners TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.property_owners FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.offers ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.offers FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.offers AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.offers FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.offers TO lead_runtime;
CREATE POLICY scoped_insert ON lead.offers FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead.offers.company_id AND p.id = lead.offers.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT INSERT ON lead.offers TO lead_runtime;
CREATE POLICY scoped_update ON lead.offers FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead.offers.company_id AND p.id = lead.offers.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id()))) WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead.offers.company_id AND p.id = lead.offers.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT UPDATE ON lead.offers TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.offers FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.offer_versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.offer_versions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.offer_versions AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.offer_versions FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.offer_versions TO lead_runtime;
CREATE POLICY scoped_insert ON lead.offer_versions FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.offers p WHERE p.company_id = lead.offer_versions.company_id AND p.id = lead.offer_versions.offer_id AND (EXISTS (SELECT 1 FROM lead.properties r WHERE r.company_id = p.company_id AND r.id = p.property_id AND (lead.is_manager() OR r.responsible_user_id = lead.current_user_id())))));
GRANT INSERT ON lead.offer_versions TO lead_runtime;

ALTER TABLE lead.sources ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.sources FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.sources AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.sources FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.sources TO lead_runtime;
CREATE POLICY scoped_insert ON lead.sources FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.sources TO lead_runtime;
CREATE POLICY scoped_update ON lead.sources FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.sources TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.sources FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.funnels ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.funnels FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.funnels AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.funnels FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.funnels TO lead_runtime;
CREATE POLICY scoped_insert ON lead.funnels FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.funnels TO lead_runtime;
CREATE POLICY scoped_update ON lead.funnels FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.funnels TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.funnels FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.funnel_stages ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.funnel_stages FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.funnel_stages AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.funnel_stages FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.funnel_stages TO lead_runtime;
CREATE POLICY scoped_insert ON lead.funnel_stages FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.funnel_stages TO lead_runtime;
CREATE POLICY scoped_update ON lead.funnel_stages FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.funnel_stages TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.funnel_stages FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.inquiries ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.inquiries FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.inquiries AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.inquiries FOR SELECT TO lead_runtime USING ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT SELECT ON lead.inquiries TO lead_runtime;
CREATE POLICY scoped_insert ON lead.inquiries FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT INSERT ON lead.inquiries TO lead_runtime;
CREATE POLICY scoped_update ON lead.inquiries FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR responsible_user_id = lead.current_user_id())) WITH CHECK ((lead.is_manager() OR responsible_user_id = lead.current_user_id()));
GRANT UPDATE ON lead.inquiries TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.inquiries FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.inquiry_properties ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.inquiry_properties FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.inquiry_properties AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.inquiry_properties FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.inquiry_properties.company_id AND p.id = lead.inquiry_properties.inquiry_id AND (true)));
GRANT SELECT ON lead.inquiry_properties TO lead_runtime;
CREATE POLICY scoped_insert ON lead.inquiry_properties FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.inquiry_properties.company_id AND p.id = lead.inquiry_properties.inquiry_id AND (true)));
GRANT INSERT ON lead.inquiry_properties TO lead_runtime;
CREATE POLICY scoped_update ON lead.inquiry_properties FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.inquiry_properties.company_id AND p.id = lead.inquiry_properties.inquiry_id AND (true))) WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.inquiry_properties.company_id AND p.id = lead.inquiry_properties.inquiry_id AND (true)));
GRANT UPDATE ON lead.inquiry_properties TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.inquiry_properties FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.stage_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.stage_history FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.stage_history AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.stage_history FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.stage_history.company_id AND p.id = lead.stage_history.inquiry_id AND (true)));
GRANT SELECT ON lead.stage_history TO lead_runtime;
CREATE POLICY scoped_insert ON lead.stage_history FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.stage_history.company_id AND p.id = lead.stage_history.inquiry_id AND (true)));
GRANT INSERT ON lead.stage_history TO lead_runtime;

ALTER TABLE lead.assignment_rules ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.assignment_rules FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.assignment_rules AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.assignment_rules FOR SELECT TO lead_runtime USING (lead.is_manager());
GRANT SELECT ON lead.assignment_rules TO lead_runtime;
CREATE POLICY scoped_insert ON lead.assignment_rules FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.assignment_rules TO lead_runtime;
CREATE POLICY scoped_update ON lead.assignment_rules FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.assignment_rules TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.assignment_rules FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.assignment_candidates ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.assignment_candidates FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.assignment_candidates AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.assignment_candidates FOR SELECT TO lead_runtime USING (lead.is_manager());
GRANT SELECT ON lead.assignment_candidates TO lead_runtime;
CREATE POLICY scoped_insert ON lead.assignment_candidates FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.assignment_candidates TO lead_runtime;
CREATE POLICY scoped_update ON lead.assignment_candidates FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.assignment_candidates TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.assignment_candidates FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.assignment_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.assignment_history FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.assignment_history AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.assignment_history FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.assignment_history.company_id AND p.id = lead.assignment_history.inquiry_id AND (true)));
GRANT SELECT ON lead.assignment_history TO lead_runtime;
CREATE POLICY scoped_insert ON lead.assignment_history FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.assignment_history.company_id AND p.id = lead.assignment_history.inquiry_id AND (true)));
GRANT INSERT ON lead.assignment_history TO lead_runtime;

ALTER TABLE lead_private.intake_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.intake_events FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.intake_events AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.intake_events FOR SELECT TO lead_runtime USING (lead.is_manager());
GRANT SELECT ON lead_private.intake_events TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.intake_events FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead_private.intake_events TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.intake_events FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead_private.intake_events TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.intake_events FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.activities ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.activities FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.activities AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.activities FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.activities.company_id AND p.id = lead.activities.inquiry_id AND (true)));
GRANT SELECT ON lead.activities TO lead_runtime;
CREATE POLICY scoped_insert ON lead.activities FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.activities.company_id AND p.id = lead.activities.inquiry_id AND (true)));
GRANT INSERT ON lead.activities TO lead_runtime;

ALTER TABLE lead.tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.tasks FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.tasks AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.tasks FOR SELECT TO lead_runtime USING ((lead.is_manager() OR assigned_user_id = lead.current_user_id()));
GRANT SELECT ON lead.tasks TO lead_runtime;
CREATE POLICY scoped_insert ON lead.tasks FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR assigned_user_id = lead.current_user_id()));
GRANT INSERT ON lead.tasks TO lead_runtime;
CREATE POLICY scoped_update ON lead.tasks FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR assigned_user_id = lead.current_user_id())) WITH CHECK ((lead.is_manager() OR assigned_user_id = lead.current_user_id()));
GRANT UPDATE ON lead.tasks TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.tasks FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.visits ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.visits FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.visits AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.visits FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.visits.company_id AND p.id = lead.visits.inquiry_id AND (true)));
GRANT SELECT ON lead.visits TO lead_runtime;
CREATE POLICY scoped_insert ON lead.visits FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.visits.company_id AND p.id = lead.visits.inquiry_id AND (true)));
GRANT INSERT ON lead.visits TO lead_runtime;
CREATE POLICY scoped_update ON lead.visits FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.visits.company_id AND p.id = lead.visits.inquiry_id AND (true))) WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.visits.company_id AND p.id = lead.visits.inquiry_id AND (true)));
GRANT UPDATE ON lead.visits TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.visits FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.reminders ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.reminders FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.reminders AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.reminders FOR SELECT TO lead_runtime USING ((lead.is_manager() OR user_id = lead.current_user_id()));
GRANT SELECT ON lead.reminders TO lead_runtime;
CREATE POLICY scoped_insert ON lead.reminders FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR user_id = lead.current_user_id()));
GRANT INSERT ON lead.reminders TO lead_runtime;
CREATE POLICY scoped_update ON lead.reminders FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR user_id = lead.current_user_id())) WITH CHECK ((lead.is_manager() OR user_id = lead.current_user_id()));
GRANT UPDATE ON lead.reminders TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.reminders FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.proposals ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.proposals FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.proposals AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.proposals FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.proposals.company_id AND p.id = lead.proposals.inquiry_id AND (true)));
GRANT SELECT ON lead.proposals TO lead_runtime;
CREATE POLICY scoped_insert ON lead.proposals FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.proposals.company_id AND p.id = lead.proposals.inquiry_id AND (true)));
GRANT INSERT ON lead.proposals TO lead_runtime;
CREATE POLICY scoped_update ON lead.proposals FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.proposals.company_id AND p.id = lead.proposals.inquiry_id AND (true))) WITH CHECK (EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead.proposals.company_id AND p.id = lead.proposals.inquiry_id AND (true)));
GRANT UPDATE ON lead.proposals TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.proposals FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.proposal_versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.proposal_versions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.proposal_versions AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.proposal_versions FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.proposal_versions.company_id AND p.id = lead.proposal_versions.proposal_id AND (true)));
GRANT SELECT ON lead.proposal_versions TO lead_runtime;
CREATE POLICY scoped_insert ON lead.proposal_versions FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.proposal_versions.company_id AND p.id = lead.proposal_versions.proposal_id AND (true)));
GRANT INSERT ON lead.proposal_versions TO lead_runtime;

ALTER TABLE lead.reservations ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.reservations FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.reservations AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.reservations FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.reservations.company_id AND p.id = lead.reservations.proposal_id AND (true)));
GRANT SELECT ON lead.reservations TO lead_runtime;
CREATE POLICY scoped_insert ON lead.reservations FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.reservations.company_id AND p.id = lead.reservations.proposal_id AND (true)));
GRANT INSERT ON lead.reservations TO lead_runtime;
CREATE POLICY scoped_update ON lead.reservations FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.reservations.company_id AND p.id = lead.reservations.proposal_id AND (true))) WITH CHECK (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.reservations.company_id AND p.id = lead.reservations.proposal_id AND (true)));
GRANT UPDATE ON lead.reservations TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.reservations FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.deals ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.deals FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.deals AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.deals FOR SELECT TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.deals.company_id AND p.id = lead.deals.proposal_id AND (true)));
GRANT SELECT ON lead.deals TO lead_runtime;
CREATE POLICY scoped_insert ON lead.deals FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.deals.company_id AND p.id = lead.deals.proposal_id AND (true)));
GRANT INSERT ON lead.deals TO lead_runtime;
CREATE POLICY scoped_update ON lead.deals FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.deals.company_id AND p.id = lead.deals.proposal_id AND (true))) WITH CHECK (EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead.deals.company_id AND p.id = lead.deals.proposal_id AND (true)));
GRANT UPDATE ON lead.deals TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.deals FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.commissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.commissions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.commissions AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.commissions FOR SELECT TO lead_runtime USING (lead.is_manager());
GRANT SELECT ON lead.commissions TO lead_runtime;
CREATE POLICY scoped_insert ON lead.commissions FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.commissions TO lead_runtime;
CREATE POLICY scoped_update ON lead.commissions FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.commissions TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.commissions FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.commission_shares ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.commission_shares FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.commission_shares AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.commission_shares FOR SELECT TO lead_runtime USING ((lead.is_manager() OR user_id = lead.current_user_id()));
GRANT SELECT ON lead.commission_shares TO lead_runtime;
CREATE POLICY scoped_insert ON lead.commission_shares FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.commission_shares TO lead_runtime;
CREATE POLICY scoped_update ON lead.commission_shares FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.commission_shares TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.commission_shares FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead_private.file_objects ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.file_objects FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.file_objects AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.file_objects FOR SELECT TO lead_runtime USING (((lead.is_manager() OR uploaded_by_user_id = lead.current_user_id()) OR EXISTS (SELECT 1 FROM lead_private.document_links p WHERE p.company_id = lead_private.file_objects.company_id AND p.file_id = lead_private.file_objects.id) OR (scan_status = 'approved' AND EXISTS (SELECT 1 FROM lead.property_media p WHERE p.company_id = lead_private.file_objects.company_id AND p.file_id = lead_private.file_objects.id))));
GRANT SELECT ON lead_private.file_objects TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.file_objects FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR uploaded_by_user_id = lead.current_user_id()));
GRANT INSERT ON lead_private.file_objects TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.file_objects FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR uploaded_by_user_id = lead.current_user_id())) WITH CHECK ((lead.is_manager() OR uploaded_by_user_id = lead.current_user_id()));
GRANT UPDATE ON lead_private.file_objects TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.file_objects FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.property_media ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.property_media FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.property_media AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.property_media FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.property_media TO lead_runtime;
CREATE POLICY scoped_insert ON lead.property_media FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead.property_media.company_id AND p.id = lead.property_media.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT INSERT ON lead.property_media TO lead_runtime;
CREATE POLICY scoped_update ON lead.property_media FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead.property_media.company_id AND p.id = lead.property_media.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id()))) WITH CHECK (EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead.property_media.company_id AND p.id = lead.property_media.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())));
GRANT UPDATE ON lead.property_media TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.property_media FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead_private.document_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.document_links FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.document_links AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.document_links FOR SELECT TO lead_runtime USING ((lead.is_manager() OR EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())) OR EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.contact_id AND (true)) OR EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.inquiry_id AND (true)) OR EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.proposal_id AND (true)) OR EXISTS (SELECT 1 FROM lead.deals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.deal_id AND (true))));
GRANT SELECT ON lead_private.document_links TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.document_links FOR INSERT TO lead_runtime WITH CHECK ((lead.is_manager() OR EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())) OR EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.contact_id AND (true)) OR EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.inquiry_id AND (true)) OR EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.proposal_id AND (true)) OR EXISTS (SELECT 1 FROM lead.deals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.deal_id AND (true))));
GRANT INSERT ON lead_private.document_links TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.document_links FOR UPDATE TO lead_runtime USING ((lead.is_manager() OR EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())) OR EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.contact_id AND (true)) OR EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.inquiry_id AND (true)) OR EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.proposal_id AND (true)) OR EXISTS (SELECT 1 FROM lead.deals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.deal_id AND (true)))) WITH CHECK ((lead.is_manager() OR EXISTS (SELECT 1 FROM lead.properties p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.property_id AND (lead.is_manager() OR p.responsible_user_id = lead.current_user_id())) OR EXISTS (SELECT 1 FROM lead_private.contacts p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.contact_id AND (true)) OR EXISTS (SELECT 1 FROM lead.inquiries p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.inquiry_id AND (true)) OR EXISTS (SELECT 1 FROM lead.proposals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.proposal_id AND (true)) OR EXISTS (SELECT 1 FROM lead.deals p WHERE p.company_id = lead_private.document_links.company_id AND p.id = lead_private.document_links.deal_id AND (true))));
GRANT UPDATE ON lead_private.document_links TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.document_links FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.catalogs ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.catalogs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.catalogs AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.catalogs FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.catalogs TO lead_runtime;
CREATE POLICY scoped_insert ON lead.catalogs FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead.catalogs TO lead_runtime;
CREATE POLICY scoped_update ON lead.catalogs FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead.catalogs TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.catalogs FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead.publications ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead.publications FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead.publications AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead.publications FOR SELECT TO lead_runtime USING (true);
GRANT SELECT ON lead.publications TO lead_runtime;
CREATE POLICY scoped_insert ON lead.publications FOR INSERT TO lead_runtime WITH CHECK (EXISTS (SELECT 1 FROM lead.offers p WHERE p.company_id = lead.publications.company_id AND p.id = lead.publications.offer_id AND (EXISTS (SELECT 1 FROM lead.properties r WHERE r.company_id = p.company_id AND r.id = p.property_id AND (lead.is_manager() OR r.responsible_user_id = lead.current_user_id())))));
GRANT INSERT ON lead.publications TO lead_runtime;
CREATE POLICY scoped_update ON lead.publications FOR UPDATE TO lead_runtime USING (EXISTS (SELECT 1 FROM lead.offers p WHERE p.company_id = lead.publications.company_id AND p.id = lead.publications.offer_id AND (EXISTS (SELECT 1 FROM lead.properties r WHERE r.company_id = p.company_id AND r.id = p.property_id AND (lead.is_manager() OR r.responsible_user_id = lead.current_user_id()))))) WITH CHECK (EXISTS (SELECT 1 FROM lead.offers p WHERE p.company_id = lead.publications.company_id AND p.id = lead.publications.offer_id AND (EXISTS (SELECT 1 FROM lead.properties r WHERE r.company_id = p.company_id AND r.id = p.property_id AND (lead.is_manager() OR r.responsible_user_id = lead.current_user_id())))));
GRANT UPDATE ON lead.publications TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead.publications FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

ALTER TABLE lead_private.audit_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.audit_events FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.audit_events AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.audit_events FOR SELECT TO lead_runtime USING (lead.is_manager());
GRANT SELECT ON lead_private.audit_events TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.audit_events FOR INSERT TO lead_runtime WITH CHECK (true);
GRANT INSERT ON lead_private.audit_events TO lead_runtime;

ALTER TABLE lead_private.outbox_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_private.outbox_events FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_boundary ON lead_private.outbox_events AS RESTRICTIVE FOR ALL TO lead_runtime
USING (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL)
WITH CHECK (company_id = lead.current_company_id() AND lead.current_user_id() IS NOT NULL);
CREATE POLICY scoped_select ON lead_private.outbox_events FOR SELECT TO lead_runtime USING (lead.is_manager());
GRANT SELECT ON lead_private.outbox_events TO lead_runtime;
CREATE POLICY scoped_insert ON lead_private.outbox_events FOR INSERT TO lead_runtime WITH CHECK (lead.is_manager());
GRANT INSERT ON lead_private.outbox_events TO lead_runtime;
CREATE POLICY scoped_update ON lead_private.outbox_events FOR UPDATE TO lead_runtime USING (lead.is_manager()) WITH CHECK (lead.is_manager());
GRANT UPDATE ON lead_private.outbox_events TO lead_runtime;
CREATE TRIGGER touch_updated_at BEFORE UPDATE ON lead_private.outbox_events FOR EACH ROW EXECUTE FUNCTION lead.touch_updated_at();

-- Reforço contra privilégios padrão do ambiente Supabase.
REVOKE ALL ON ALL TABLES IN SCHEMA lead, lead_private FROM PUBLIC;
DO $api_roles$
DECLARE r text;
BEGIN
    FOREACH r IN ARRAY ARRAY['anon', 'authenticated', 'service_role'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
            EXECUTE format('REVOKE ALL ON SCHEMA lead, lead_private, lead_public, lead_admin FROM %I', r);
            EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA lead, lead_private, lead_public, lead_admin FROM %I', r);
            EXECUTE format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA lead FROM %I', r);
        END IF;
    END LOOP;
END
$api_roles$;
-- Não conceder DELETE, TRUNCATE, CREATE ou BYPASSRLS ao runtime.
ALTER DEFAULT PRIVILEGES IN SCHEMA lead, lead_private REVOKE ALL ON TABLES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA lead REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
