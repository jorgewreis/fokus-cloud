# Dicionario de dados

Este arquivo deve descrever tabelas, colunas, tipos, obrigatoriedade e significado de cada campo relevante.

## Modelo

| Tabela | Coluna | Tipo | Obrigatoria | Descricao |
| --- | --- | --- | --- | --- |
| users | email | string | Sim | E-mail usado para identificacao do usuario. |
| companies | name | string | Sim | Nome da empresa cliente. |
| platform_admins | role | enum | Sim | Perfil interno do Backoffice: `superadministrador` ou `administrador_comercial`. |
| platform_admins | status | enum | Sim | Estado da conta interna: `ativo`, `bloqueado`, `suspenso` ou `desativado`. |
| platform_audit_events | expires_at | timestamp | Sim | Data de expiracao da auditoria do Backoffice, com retencao de 180 dias. |
| platform_audit_events | actor_type | enum | Sim | Tipo de ator auditado: anonimo, administrador, cliente, gateway ou sistema. |
| platform_audit_events | origin_channel | enum | Sim | Origem tipada: HTTP, webhook, scheduler, CLI ou sistema. |
| platform_audit_events | correlation_id | string | Nao | Identificador técnico de correlação sanitizado. |
| platform_audit_events | reason | text | Sim | Motivo sanitizado ou indicação explícita de não aplicabilidade. |
| audit_events | expires_at | timestamp | Sim | Data de expiracao da auditoria da empresa, 180 dias após a criação. |
| audit_events | actor_type | enum | Sim | Tipo do ator que iniciou a ação auditada. |
| audit_events | metadata | json | Nao | Campos tecnicos e lista explícita de campos não aplicáveis ao evento. |
| platform_alerts | queue | enum | Sim | Fila operacional do alerta: financeiro, seguranca, catalogo, suporte interno ou auditoria/revisao. |
| platform_alerts | due_at | timestamp | Sim | Prazo limite de atendimento conforme severidade. |
| platform_alert_comments | expires_at | timestamp | Sim | Data de expiracao do comentario operacional, com retencao de 90 dias. |
| subscriptions | status | enum | Sim | Estado alvo da assinatura no ciclo de billing. |
| payments | status | enum | Sim | Estado alvo do pagamento normalizado a partir do Mercado Pago. |
| refund_requests | status | enum | Sim | Estado da solicitacao de reembolso. |
| payment_reconciliation_alerts | status | enum | Sim | Estado da divergencia de conciliacao. |
| law_units | status | enum | Sim | Estado da unidade juridica: `active`, `suspended` ou `archived`. |
| law_unit_memberships | role | enum | Sim | Perfil Law por unidade: `unit_admin`, `chief_clerk`, `operator` ou `viewer`. |
| law_cases | operational_status | enum | Sim | Estado operacional interno do processo: `active`, `pending`, `suspended`, `archived` ou `cancelled`. |
| law_cases | official_status_code | string | Nao | Codigo/situacao oficial sincronizada de fonte externa, sem controlar o status interno. |
| law_cases | subjects | json | Nao | Assuntos processuais sincronizados ou informados. |
| law_cases | legal_basis | json | Nao | Artigos, capitulacoes ou base legal informada. |
| law_cases | filing_date | date | Nao | Data de autuacao. |
| law_cases | distribution_date | date | Nao | Data de distribuicao. |
| law_cases | operational_priority | string | Nao | Prioridade operacional interna, sem substituir sigilo. |
| law_cases | confidentiality_level | enum | Sim | Nivel de sigilo: `public_internal`, `unit_restricted`, `case_confidential` ou `enhanced_confidential`. |
| law_cases | internal_tags | json | Nao | Tags informativas configuraveis da unidade. |
| law_contacts | contact_type | enum | Sim | Tipo do contato: pessoa, advogado, instituicao, orgao publico, unidade judicial, perito ou outro tipo permitido. |
| law_contacts | document_number | string | Nao | Documento normalizado e protegido quando necessario. |
| law_contact_addresses | address_type | enum | Sim | Tipo de endereco do contato. |
| law_contact_channels | channel_type | enum | Sim | Tipo de canal: telefone, celular, e-mail, WhatsApp, site ou outro. |
| law_case_contacts | case_role | enum | Sim | Papel do contato no processo: autor, reu, vitima, testemunha, advogado, defensor, promotor, representante, interessado, orgao de origem ou outro. |
| law_expedition_contacts | expedition_role | enum | Sim | Papel do contato na expedicao: destinatario, orgao de destino, unidade externa, responsavel por recebimento, copia ou outro. |
| law_task_contacts | task_contact_role | enum | Sim | Papel opcional do contato na tarefa como referencia ou envolvido externo. |
| law_expedition_types | code | string | Sim | Codigo do tipo de expedicao, como `oficio`, `carta_precatoria`, `carta_rogatoria` ou `carta_de_ordem`. |
| law_expedition_types | uses_internal_number | boolean | Sim | Indica se o tipo exige numeracao interna. |
| law_expedition_instances | sector_code | string | Sim | Codigo do setor ou origem operacional da expedicao. |
| law_expeditions | status | enum | Sim | Estado da expedicao: `created`, `signed`, `sent`, `received`, `returned`, `closed` ou `cancelled`. |
| law_expeditions | external_number | string | Nao | Numero atribuido posteriormente por comarca ou orgao de destino. |
| law_expedition_number_sequences | next_number | integer | Sim | Proximo numero interno disponivel para tipo, instancia e ano. |
| law_task_types | code | string | Sim | Codigo do tipo de tarefa, como `expedir_oficio`, `expedir_mandado` ou `publicar_edital`. |
| law_operation_recipes | creates_expedition | boolean | Sim | Indica se a receita operacional gera expedicao. |
| law_operation_recipes | creates_followup_task | boolean | Sim | Indica se a receita gera tarefa posterior de retorno, cumprimento ou conferencia. |
| law_task_expeditions | relation_type | enum | Sim | Relacao entre tarefa e expedicao: `created`, `tracks`, `followup` ou `review`. |
| law_tasks | status | enum | Sim | Estado do prazo ou pendencia: `open`, `in_progress`, `waiting`, `done`, `cancelled` ou `overdue`. |
| law_alerts | status | enum | Sim | Estado do alerta operacional Law: `open`, `acknowledged`, `resolved` ou `dismissed`. |
| law_audit_events | reason | string | Nao | Motivo da acao auditada, obrigatorio para alteracoes sensiveis definidas no modelo Law. |
| law_confidential_case_accesses | access_level | enum | Sim | Nivel de acesso ao processo sigiloso: `view`, `operate` ou `manage_confidentiality`. |
| law_support_requests | category | enum | Sim | Categoria de suporte: `access_permissions`, `usage_question`, `technical_error` ou `subscription_modules`. |
| law_support_requests | expires_at | timestamp | Sim | Data de expiracao da solicitacao de suporte, com retencao de 90 dias. |
| law_datajud_syncs | status | enum | Sim | Estado da consulta/sincronizacao Datajud: `requested`, `success`, `partial`, `failed` ou `ignored`. |
| law_datajud_syncs | trigger_type | enum | Sim | Gatilho da sincronizacao automatica: `case_created`, `case_moved` ou `retry`. |
| law_datajud_divergences | status | enum | Sim | Estado da divergencia Datajud: `open`, `reviewed`, `applied` ou `dismissed`. |

## A complementar

Expandir conforme novas migrations forem criadas.

O modelo detalhado das tabelas de Backoffice, Billing, alertas, reembolsos e
conciliacao esta em [Modelo de dados do Backoffice e Billing](backoffice-and-billing-data-model.md).

O modelo detalhado das tabelas do Fokus Law esta em [Modelo de dados do Fokus Law](fokus-law-data-model.md). O nucleo de contatos esta detalhado em [Modelo de dados da gestao de contatos Law](law-contacts-data-model.md), expedicoes em [Modelo de dados das expedicoes Law](law-expeditions-data-model.md), e tarefas/fluxos em [Modelo de dados de tarefas e fluxos Law](law-operational-workflows-data-model.md).

Para o Marco 6, consultar também `billing_checkout_attempts`,
`billing_provider_events`, `refund_requests` e
`payment_reconciliation_alerts`, criadas nas migrations de Billing sandbox.
