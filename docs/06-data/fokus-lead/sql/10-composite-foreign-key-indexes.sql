-- Fokus Lead | complemento após revisão do Supabase | 2026-10-15
-- Cobertura explícita das FKs compostas adicionadas às relações principais.
CREATE INDEX inquiries_funnel_stage_fk_idx
    ON lead.inquiries (company_id, funnel_id, stage_id);
CREATE INDEX proposals_current_version_fk_idx
    ON lead.proposals (company_id, id, current_version);
CREATE INDEX proposals_inquiry_property_fk_idx
    ON lead.proposals (company_id, inquiry_property_id, inquiry_id, offer_id);
CREATE INDEX reservations_offer_property_fk_idx
    ON lead.reservations (company_id, offer_id, property_id);
CREATE INDEX reservations_proposal_offer_fk_idx
    ON lead.reservations (company_id, proposal_id, offer_id);
CREATE INDEX visits_inquiry_property_fk_idx
    ON lead.visits (company_id, inquiry_property_id, inquiry_id, offer_id);
