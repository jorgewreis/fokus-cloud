# Modelo de dados do Backoffice e Billing

## Objetivo

Definir o modelo alvo de dados para Backoffice, Billing, alertas, auditoria,
catalogo, assinaturas, pagamentos, vouchers, reembolsos, conciliacao,
notificacoes e incidentes.

Este documento descreve o destino de implementacao. Quando o banco atual ainda
divergir do modelo alvo, a diferenca deve ser tratada como evolucao necessaria
em migrations futuras.

## Principios

- Contas internas do Backoffice usam `platform_admins` e nao se misturam com
  contas de clientes em `users`.
- Dados comerciais e financeiros vinculados a clientes preservam `company_id`.
- Assinaturas, pagamentos, resgates e mudancas comerciais preservam snapshots.
- Tabelas com retencao explicita possuem `expires_at`.
- Payloads de gateway devem ser sanitizados antes de persistir dados sensiveis.
- Correcoes financeiras e operacionais precisam de trilha de auditoria.
- FKs devem usar `ON DELETE RESTRICT` ou equivalente conservador, preservando
  historico comercial.

## Prefixos alvo

| Prefixo | Entidade |
| --- | --- |
| PAD | Administrador interno da plataforma |
| MFA | Desafio de MFA interno |
| AUD | Auditoria interna |
| ALT | Alerta operacional |
| ALC | Comentario de alerta |
| INC | Incidente operacional |
| NTF | Notificacao operacional |
| ASS | Assinatura |
| ITM | Item contratado |
| SCH | Mudanca de assinatura |
| PAG | Pagamento |
| VCH | Voucher |
| VRD | Resgate de voucher |
| REE | Reembolso |
| RCA | Alerta de conciliacao |

## Entidades do modelo alvo

| Entidade | Finalidade |
| --- | --- |
| `platform_admins` | Contas internas do Backoffice. |
| `platform_login_challenges` | Desafios de MFA por e-mail. |
| `platform_audit_events` | Auditoria das acoes internas do Backoffice. |
| `platform_alerts` | Alertas operacionais por fila, severidade e estado. |
| `platform_alert_comments` | Comentarios e historico de suporte interno do alerta. |
| `platform_incidents` | Incidentes criticos e ciclo de resposta. |
| `platform_notifications` | Notificacoes imediatas por e-mail e dashboard. |
| `products`, `plans`, `modules`, `plan_modules` | Catalogo comercial e composicao de planos. |
| `subscriptions` | Assinaturas por empresa e produto. |
| `subscription_items` | Itens contratados e snapshots por modulo. |
| `subscription_changes` | Mudancas de assinatura: upgrade, downgrade, cancelamento, pausa e reativacao. |
| `payments` | Pagamentos e cobrancas recorrentes do Mercado Pago. |
| `vouchers` | Beneficios comerciais administraveis. |
| `voucher_redemptions` | Resgates de vouchers com snapshot. |
| `refund_requests` | Solicitacoes, aprovacao e execucao de reembolso. |
| `payment_reconciliation_alerts` | Divergencias de conciliacao entre Fokus Cloud e Mercado Pago. |

## Contas internas

### `platform_admins`

Representa uma identidade interna do Backoffice.

Campos alvo:

- `id`: prefixed ULID;
- `name`: nome exibido;
- `email`: e-mail unico;
- `password`: hash da senha;
- `role`: `superadministrador` ou `administrador_comercial`;
- `status`: `ativo`, `bloqueado`, `suspenso` ou `desativado`;
- `email_verified_at`;
- `last_login_at`;
- `failed_login_count`;
- `blocked_at`;
- `blocked_reason`;
- timestamps e versionamento quando aplicavel.

Evolucao necessaria:

- o modelo atual ja possui `platform_admins`;
- `role` precisa ser adicionado ou garantido;
- `status` atual precisa incluir `bloqueado` e `desativado`;
- campos de bloqueio e contagem de falhas devem apoiar bloqueio rigido.

### `platform_login_challenges`

Registra cada desafio de MFA por e-mail.

Campos alvo:

- `id`: prefixed ULID;
- `platform_admin_id`;
- `code_hash`;
- `expires_at`;
- `used_at`;
- `ip_address`;
- `user_agent`;
- `attempt_count`;
- timestamps.

O codigo MFA nunca deve ser persistido em texto puro.

## Auditoria do Backoffice

### `platform_audit_events`

Registra acoes sensiveis executadas por contas internas ou por processos do
Backoffice.

Campos alvo:

- `id`: prefixed ULID;
- `platform_admin_id`: ator interno, quando houver;
- `actor_type`: `anonymous`, `admin`, `customer`, `gateway` ou `system`;
- `action`;
- `entity_type`;
- `entity_id`;
- `company_id`: opcional, quando a acao afetar cliente;
- `reason`: obrigatorio quando a acao afetar cliente, cobranca ou
  disponibilidade publica;
- `before_masked`;
- `after_masked`;
- `metadata`;
- `ip_address`;
- `user_agent`;
- `origin_channel`: `http`, `webhook`, `scheduler`, `cli` ou `system`;
- `origin_context`: rota ou comando, quando houver;
- `correlation_id`: identificador técnico sanitizado, quando houver;
- `expires_at`;
- `created_at`.

Retencao: 180 dias.

O serviço de auditoria compartilhado grava também em `audit_events` para ações
no contexto da empresa, preservando os limites de leitura atuais. Os dois
gravadores produzem estados JSON, representam lados inexistentes como `{}` e
definem `expires_at` exatamente 180 dias após `created_at`. Motivos ausentes
devem ser marcados explicitamente como não aplicáveis.

Metadados devem continuar sem senhas, tokens, codigos MFA, CPF/CNPJ completo,
dados completos de cartao, payload completo do gateway ou documentos pessoais.

## Alertas operacionais

### `platform_alerts`

Representa alertas das filas operacionais do Backoffice.

Campos alvo:

- `id`: prefixed ULID;
- `queue`: `financeiro`, `seguranca`, `catalogo`, `suporte_interno` ou
  `auditoria_revisao`;
- `type`: tipo do alerta;
- `severity`: `critica`, `alta`, `media` ou `baixa`;
- `status`: `aberto`, `em_revisao`, `aguardando_acao`, `resolvido` ou
  `descartado`;
- `entity_type`;
- `entity_id`;
- `company_id`: opcional;
- `source`: origem do alerta;
- `assigned_platform_admin_id`: responsavel, quando houver;
- `audit_event_id`: vinculo opcional com auditoria;
- `incident_id`: vinculo opcional com incidente;
- `opened_at`;
- `first_reviewed_at`;
- `resolved_at`;
- `due_at`;
- `escalated_at`;
- `escalated_from_severity`;
- `expires_at`;
- timestamps.

Retencao: 90 dias para registros operacionais.

Alertas vencidos devem preencher os campos de escalonamento e, quando
necessario, vincular `incident_id`.

### `platform_alert_comments`

Registra comentarios e historico do alerta.

Campos alvo:

- `id`: prefixed ULID;
- `alert_id`;
- `platform_admin_id`;
- `event_type`: comentario, mudanca de estado, evidencia, resolucao ou
  escalonamento;
- `comment`;
- `evidence_metadata`;
- `expires_at`;
- `created_at`.

Comentarios e evidencias nao podem armazenar dados sensiveis proibidos.
Retencao: 90 dias.

## Incidentes e notificacoes

### `platform_incidents`

Registra incidentes criticos e seu ciclo de resposta.

Campos alvo:

- `id`: prefixed ULID;
- `severity`;
- `status`;
- `title`;
- `impact`;
- `containment`;
- `root_cause`;
- `correction`;
- `prevention`;
- `responsible_platform_admin_id`;
- `opened_at`;
- `contained_at`;
- `resolved_at`;
- `reviewed_at`;
- `created_at`;
- `updated_at`.

Incidentes podem ser vinculados a um ou mais alertas por `platform_alerts`.

### `platform_notifications`

Registra notificacoes imediatas do Backoffice.

Campos alvo:

- `id`: prefixed ULID;
- `alert_id`: opcional;
- `incident_id`: opcional;
- `channel`: e-mail transacional ou dashboard;
- `recipient_platform_admin_id`;
- `status`: `pendente`, `enviada`, `falhou` ou `cancelada`;
- `attempt_count`;
- `last_error`;
- `sent_at`;
- timestamps.

Notificacao nao substitui alerta ou auditoria; ela registra tentativa de aviso.

## Assinaturas

### `subscriptions`

Representa assinatura de uma empresa para um produto.

Status alvo:

- `aguardando_pagamento`;
- `ativa`;
- `inadimplente`;
- `suspensa`;
- `cancelamento_agendado`;
- `encerrada`.

Campos alvo adicionais:

- `billing_cycle`: `monthly` ou `annual`;
- `current_period_starts_at`;
- `current_period_ends_at`;
- `provider`: `mercado_pago`;
- `provider_subscription_id`;
- `cancel_at`;
- `commercial_snapshot`;
- `open_company_product`;
- `version`.

Evolucao necessaria:

- o modelo atual ja possui `subscriptions` e campos de recorrencia;
- `status` atual ainda usa `pendente` e precisa evoluir para
  `aguardando_pagamento`;
- `inadimplente` e `cancelamento_agendado` precisam entrar no status alvo;
- snapshot consolidado deve ser adicionado quando houver alteracao comercial.

### `subscription_items`

Preserva o snapshot por modulo contratado.

Campos alvo:

- `id`;
- `company_id`;
- `subscription_id`;
- `module_id`;
- `name_snapshot`;
- `quantity`;
- `unit_price_snapshot`;
- `conditions_snapshot`;
- timestamps.

Esses registros nao mudam retroativamente quando catalogo, preco, modulo ou
plano evoluirem.

### `subscription_changes`

Registra mudancas de assinatura.

Tipos alvo:

- `upgrade`;
- `downgrade`;
- `cancelamento`;
- `suspensao`;
- `reativacao`.

Status alvo:

- `solicitada`;
- `aguardando_pagamento`;
- `agendada`;
- `aplicada`;
- `cancelada`;
- `falhou`.

Campos alvo:

- `id`;
- `company_id`;
- `subscription_id`;
- `type`;
- `status`;
- `effective_at`;
- `before_snapshot`;
- `after_snapshot`;
- `proration_amount`;
- `reason`;
- `requested_by_user_id`;
- `requested_by_platform_admin_id`;
- `approved_by_platform_admin_id`;
- timestamps.

Evolucao necessaria:

- a tabela atual ja existe;
- `status` atual usa `pendente_pagamento` e deve evoluir para
  `aguardando_pagamento`;
- precisa guardar snapshots antes/depois e aprovador quando aplicavel.

## Pagamentos

### `payments`

Cada cobranca do Mercado Pago deve gerar ou atualizar um pagamento local.

Status alvo:

- `aguardando_pagamento`;
- `aprovado`;
- `recusado`;
- `cancelado`;
- `estornado`;
- `em_disputa`.

Campos alvo:

- `id`;
- `company_id`;
- `subscription_id`;
- `provider`: `mercado_pago`;
- `provider_payment_id`;
- `provider_subscription_id`;
- `status`;
- `amount`;
- `currency`: BRL;
- `billing_period_starts_at`;
- `billing_period_ends_at`;
- `paid_at`;
- `provider_payload_sanitized`;
- timestamps;
- `version`.

Evolucao necessaria:

- a tabela atual ja existe;
- `status` atual usa `pendente` e precisa evoluir para
  `aguardando_pagamento`;
- `estornado` e `em_disputa` precisam entrar no status alvo;
- payload bruto deve ser sanitizado antes de persistir;
- cobrancas recorrentes devem preservar historico por pagamento.

## Vouchers e resgates

### `vouchers`

Tipos alvo:

- `trial_free`;
- `percentage`;
- `fixed`;
- `commercial_credit`.

Campos alvo:

- `id`;
- `code`;
- `discount_type`;
- `discount_value`;
- `product_id`;
- `plan_id`: opcional;
- `module_codes`;
- `redemption_limit`;
- `redemption_limit_per_company`;
- `starts_at`;
- `ends_at`;
- `benefit_duration`;
- `status`;
- `created_by_platform_admin_id`;
- timestamps.

Evolucao necessaria:

- a tabela atual ja existe;
- `discount_type` atual precisa incluir `trial_free` e `commercial_credit`;
- regras de elegibilidade e duracao do beneficio devem ser persistidas de forma
  suficiente para recalculo no backend.

### `voucher_redemptions`

Preserva o snapshot do resgate.

Campos alvo:

- `id`;
- `voucher_id`;
- `company_id`;
- `subscription_id`;
- `code_snapshot`;
- `product_snapshot`;
- `plan_snapshot`;
- `base_price_snapshot`;
- `benefit_type_snapshot`;
- `benefit_value_snapshot`;
- `discount_amount`;
- `final_price_snapshot`;
- `benefit_starts_at`;
- `benefit_ends_at`;
- `created_at`.

Evolucao necessaria:

- a tabela atual existe de forma inicial;
- precisa guardar snapshot completo do resgate para nao depender do catalogo
  atual nem da auditoria expirada.

## Reembolsos

### `refund_requests`

Representa solicitacao, aprovacao e execucao de reembolso.

Status alvo:

- `solicitado`;
- `aprovado`;
- `executado`;
- `recusado`.

Campos alvo:

- `id`;
- `company_id`;
- `subscription_id`;
- `payment_id`;
- `requested_by_platform_admin_id`;
- `approved_by_platform_admin_id`;
- `reason`;
- `allowed_case`: cobranca duplicada, erro tecnico, acordo comercial ou
  arrependimento em ate 7 dias apos renovacao;
- `amount`;
- `status`;
- `provider_refund_id`;
- `provider_payload_sanitized`;
- `requested_at`;
- `approved_at`;
- `executed_at`;
- `refused_at`;
- timestamps.

Reembolso aprovado e executado via Mercado Pago deve atualizar o pagamento
relacionado para `estornado` quando o gateway confirmar o estorno.

## Conciliacao

### `payment_reconciliation_alerts`

Registra divergencias entre Fokus Cloud e Mercado Pago.

Status alvo:

- `aberta`;
- `em_revisao`;
- `corrigida`;
- `descartada`.

Campos alvo:

- `id`;
- `company_id`;
- `subscription_id`;
- `payment_id`;
- `platform_alert_id`;
- `type`;
- `internal_status`;
- `mercado_pago_status`;
- `impact`;
- `reviewed_by_platform_admin_id`;
- `corrected_by_platform_admin_id`;
- `correction_reason`;
- `correction_snapshot`;
- `audit_event_id`;
- `opened_at`;
- `reviewed_at`;
- `corrected_at`;
- `discarded_at`;
- timestamps.

O Mercado Pago e a referencia prevalente para pagamento e recorrencia, mas a
correcao interna exige revisao manual e acao de superadministrador.

## Relacionamentos principais

```text
platform_admins ──< platform_login_challenges
platform_admins ──< platform_audit_events
platform_admins ──< platform_alert_comments
platform_admins ──< platform_notifications

platform_alerts ──< platform_alert_comments
platform_incidents ──< platform_alerts
platform_alerts ──< platform_notifications
platform_incidents ──< platform_notifications

companies ──< subscriptions ──< subscription_items
subscriptions ──< subscription_changes
subscriptions ──< payments
payments ──< refund_requests
payments ──< payment_reconciliation_alerts

vouchers ──< voucher_redemptions
companies ──< voucher_redemptions
subscriptions ──< voucher_redemptions
```

Entidades financeiras e comerciais que pertencem a uma empresa devem preservar
`company_id` para reforcar isolamento e consultas por cliente.

## Consultas principais

O modelo alvo deve permitir:

- listar alertas por fila, severidade, estado e vencimento;
- calcular tempo de primeira revisao e tempo de resolucao;
- listar incidentes criticos e alertas vencidos;
- consultar auditoria por ator, acao, entidade, empresa e periodo;
- calcular receita recebida por pagamentos aprovados;
- calcular receita prevista por assinaturas ativas e snapshots;
- localizar divergencias abertas ou em revisao;
- consultar reembolsos por status, empresa, assinatura e pagamento;
- reconstruir condicao comercial de assinatura ou resgate por snapshot.

## Estado atual e evolucao necessaria

Ja existem estruturas iniciais para:

- `platform_admins`;
- `platform_login_challenges`;
- `platform_audit_events`;
- `payments`;
- `vouchers`;
- `voucher_redemptions`;
- `subscription_changes`;
- campos de recorrencia em `subscriptions`.

Evolucoes necessarias:

- adicionar ou garantir `platform_admins.role`;
- ampliar `platform_admins.status` para `ativo`, `bloqueado`, `suspenso` e
  `desativado`;
- evoluir `subscriptions.status` para incluir `aguardando_pagamento`,
  `inadimplente` e `cancelamento_agendado`;
- evoluir `payments.status` para incluir `aguardando_pagamento`, `estornado` e
  `em_disputa`;
- evoluir `vouchers.discount_type` para incluir `trial_free` e
  `commercial_credit`;
- adicionar before/after e `expires_at` em `platform_audit_events`;
- adicionar snapshots antes/depois em `subscription_changes`;
- adicionar snapshot completo em `voucher_redemptions`;
- criar `platform_alerts`;
- criar `platform_alert_comments`;
- criar `platform_incidents`;
- criar `platform_notifications`;
- criar `refund_requests`;
- criar `payment_reconciliation_alerts`.

## Criterios minimos de teste

- Conta interna do Backoffice nao se mistura com usuario cliente.
- Perfil interno permite distinguir `superadministrador` e
  `administrador_comercial`.
- Conta interna bloqueada nao autentica ate desbloqueio formal.
- Auditoria do Backoffice registra before/after e expira em 180 dias.
- Alerta operacional expira em 90 dias e preserva estado, fila, severidade e
  vencimento.
- Comentario de alerta expira em 90 dias e nao aceita dados sensiveis
  proibidos.
- Incidente critico pode ser vinculado a alerta vencido.
- Notificacao registra status `pendente`, `enviada`, `falhou` ou `cancelada`.
- Assinatura usa status alvo de billing.
- Pagamento usa status alvo de billing e preserva payload sanitizado.
- Cada cobranca recorrente gera ou atualiza um pagamento local sem perder
  historico.
- Mudanca de assinatura preserva snapshot antes/depois.
- Voucher suporta `trial_free`, `percentage`, `fixed` e `commercial_credit`.
- Resgate de voucher preserva snapshot completo.
- Reembolso percorre `solicitado`, `aprovado`, `executado` ou `recusado`.
- Divergencia de conciliacao exige revisao manual e correcao por
  superadministrador.

## Dependencias

- [Modelo relacional](relational-model.md)
- [Modelo de dados do catalogo](catalog-data-model.md)
- [Backoffice Fokus Cloud](../04-products/backoffice.md)
- [Billing e conciliacao com Mercado Pago](../08-commercial/billing-and-reconciliation.md)
- [Monitoramento, suporte e operacao do Backoffice](../09-operations/backoffice-monitoring-and-support.md)
- [Requisitos do Backoffice Fokus Cloud](../05-requirements/backoffice.md)

## Criterio de pronto

Este documento esta suficiente quando um implementador consegue planejar
migrations, status, chaves, relacionamentos, retencao, snapshots e consultas
principais sem tomar novas decisoes de dados.
## Modelo de reserva e snapshot de voucher

voucher_redemption_reservations guarda request_key único, empresa, voucher, assinatura opcional, estado pending/confirmed/released/expired, snapshot e janela de expiração. voucher_redemptions.snapshot preserva o contexto comercial confirmado, incluindo o período do benefício. As FKs e verificações de dependência protegem histórico de catálogo e resgates.

## Estruturas do Billing sandbox

As migrations `2026_09_02_000700` e `2026_09_02_000800` adicionam rastreamento
de provedor em assinaturas e pagamentos, `billing_checkout_attempts`,
`billing_provider_events`, `refund_requests` e
`payment_reconciliation_alerts`. Payloads novos são sanitizados e eventos,
reembolsos e divergências preservam histórico por FKs restritivas e índices de
provedor/status.
