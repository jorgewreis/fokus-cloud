# Migrations e seeds

## Migrations

Migrations devem representar alteracoes pequenas, revisaveis e coerentes com o modelo relacional.

## Seeds

Seeds podem criar dados iniciais para desenvolvimento, testes e carga inicial controlada.

## Regras

- Nao usar seed como substituto permanente de backoffice quando o dado for administravel.
- Registrar impacto de dados comerciais versionaveis.
- Validar chaves estrangeiras e indices antes de implementar fluxos dependentes.
- Evolucoes de Backoffice e Billing devem seguir o [Modelo de dados do Backoffice e Billing](backoffice-and-billing-data-model.md).
- Evolucoes do Fokus Law devem seguir o [Modelo de dados do Fokus Law](fokus-law-data-model.md).
- Migrations de status devem tratar conversao do estado atual para o modelo alvo sem perder historico de assinatura, pagamento, voucher ou auditoria.
- Tabelas operacionais com retencao devem incluir `expires_at` quando a regra de negocio exigir limpeza posterior.
- Tabelas do Fokus Law devem preservar `company_id`, `law_unit_id` quando aplicavel, FKs compostas e `ON DELETE RESTRICT`.
- Sequencias de expedicoes numeradas devem ser incrementadas em transacao por unidade, tipo, instancia e ano.
- Tarefas e fluxos devem manter receitas operacionais separadas dos registros executados.
- Vinculos entre tarefas e expedicoes devem evitar duplicacao de dados documentais.

## A complementar

Listar migrations criticas e seeds oficiais do projeto.

Migrations ja existentes relacionadas ao modelo:

- `2026_08_06_000300_create_governance_tables.php`: cria `payments`, `audit_events` e `support_accesses`.
- `2026_08_08_000600_create_backoffice_and_commercial_governance_tables.php`: cria `platform_admins`, `platform_login_challenges`, `platform_audit_events`, `vouchers`, `voucher_redemptions`, `subscription_changes`, `usage_snapshots` e campos de recorrencia em `subscriptions`.
- `2026_09_24_000100_unify_audit_contract_and_scrub_legacy_data.php`: adiciona origem e ator tipados às duas trilhas de auditoria, sanitiza eventos técnicos históricos, preenche expiração de 180 dias e remove o payload bruto legado de pagamentos.
- `2026_08_31_000100_recreate_fokus_law_catalog.php`: recria o catalogo Law
  para os cinco modulos, interrompendo a operacao quando houver itens de
  assinatura dependentes e preservando o historico comercial.
- `2026_08_31_000200_add_law_module_variants.php`: adiciona nucleo tecnico,
  segmento, contexto, variante, capacidades e regras de compatibilidade aos
  modulos.
- `2026_08_31_000300_normalize_law_module_segments.php`: normaliza registros
  legados de `judiciario` para `setor_publico`.
- `2026_08_31_000400_create_law_hearings_tables.php`: cria o nucleo de dados de
  audiencias, historico, alertas e acompanhamento externo.

- `2026_09_02_000700_add_billing_provider_tracking.php`: adiciona sincronização
  do provedor, tentativas de checkout e eventos idempotentes.
- `2026_09_02_000800_create_refunds_and_reconciliation.php`: cria reembolsos,
  divergências e permissões financeiras.

Em produção, executar migrations com `php artisan migrate --force
--no-interaction`; não executar `db:seed`, pois o catálogo é gerenciado pelo
Backoffice.
