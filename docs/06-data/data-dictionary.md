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
| customer_permissions | code | string | Sim | Código atômico único de permissão cliente, como `law.contacts.view`; não inclui permissões `platform.*`. |
| customer_permissions | product_code | string | Sim | Produto dono da capacidade, nesta etapa `law`. |
| law_access_roles | code | string | Sim | Código único do perfil dentro da empresa e do setor. Perfis padrão protegidos: `unit_admin`, `chief_clerk`, `operator`, `viewer`. |
| law_access_roles | is_system | boolean | Sim | Marca perfis padrão protegidos, que não podem ter permissões editadas ou ser removidos. |
| law_access_roles | version | integer | Sim | Versão usada para detectar edição concorrente de perfil. |
| law_access_role_permissions | law_access_role_id | string | Sim | Perfil Law relacionado à permissão atômica do catálogo. |
| law_access_role_permissions | customer_permission_id | string | Sim | Permissão do catálogo concedida ao perfil. |
| law_unit_memberships | law_access_role_id | string | Sim | Perfil associado ao vínculo usuário-empresa naquele setor. |
| law_unit_memberships | status | enum | Sim | Estado do acesso ao setor: `pendente`, `ativo`, `suspenso` ou `removido`. |
| law_unit_memberships | version | integer | Sim | Versão para concorrência otimista de atribuições de acesso. |
| law_cases | operational_status | enum | Sim | Estado operacional interno do processo: `active`, `pending`, `suspended`, `archived` ou `cancelled`. |
| law_cases | official_status_code | string | Nao | Codigo/situacao oficial sincronizada de fonte externa, sem controlar o status interno. |
| law_cases | subjects | json | Nao | Assuntos processuais sincronizados ou informados. |
| law_cases | legal_basis | json | Nao | Artigos, capitulacoes ou base legal informada. |
| law_cases | filing_date | date | Nao | Data de autuacao. |
| law_cases | distribution_date | date | Nao | Data de distribuicao. |
| law_cases | operational_priority | string | Nao | Prioridade operacional interna, sem substituir sigilo. |
| law_cases | confidentiality_level | string | Sim | Nivel de sigilo: `public`, `confidential` ou `secret`. |
| law_cases | internal_tags | json | Nao | Tags informativas configuraveis da unidade. |
| law_contacts | legal_nature | enum/null | Sim | Natureza PF/PJ; nula em unidade independente. |
| law_contacts | record_kind | string | Sim | `contact` para PF/PJ ou `unit` para unidade independente. |
| law_contacts | parent_contact_id | ULID/null | Sim | Pai imediato do registro; FK composta limita vínculo à mesma empresa. |
| law_contacts | sharing_excluded | boolean | Sim | Retira contato individual das regras de compartilhamento externo. |
| law_contact_addresses | address_type | enum | Sim | Tipo estruturado: residencial, comercial, correspondência ou outro. |
| law_contact_channels | channel_type | enum | Sim | Escopo aceita telefone ou e-mail; departamento opcional identifica canal departamental. |
| law_contact_channels | is_personal | boolean | Sim | Marca canal pessoal como dado sensível e nunca compartilhável. |
| law_contact_documents | document_number_encrypted | text | Sim | Documento cifrado; valor aberto não é guardado. |
| law_contact_documents | document_fingerprint | string | Nao | HMAC para comparação de CPF/CNPJ e detecção de duplicidade. |
| law_contact_departments | migrated_contact_id | ULID/null | Sim | Registro `unit` que recebeu o departamento legado; nulo para linhas ainda não promovidas. |
| law_contact_institutional_data | is_primary | boolean | Sim | Marca o tipo institucional principal; identificadores seguem opcionais. |
| law_contact_company_settings | context_code | string | Não | Contexto único da base de Contatos da empresa: `escritorio`, `orgao_publico` ou `judiciario`. |
| law_contact_company_settings | segment_code | string | Não | Segmento associado ao contexto ativo da empresa. |
| law_contact_tags | normalized_name | string | Sim | Nome normalizado e único por empresa para sugestão/reuso. |
| law_contact_activity | activity_type | string | Sim | Ação recente do usuário sem armazenar o termo de busca. |
| law_contact_sharing_policies | recipient_company_id | string | Sim | Empresa que recebe uma referência somente leitura. |
| law_contact_sharing_policies | legal_natures | json | Sim | Naturezas PF/PJ disponibilizadas pelo lado da empresa; acordo só ativa com política recíproca. |
| law_contact_sharing_policies | profession_names | json | Sim | Profissões vinculadas a contatos PF permitidas nesse lado do acordo bilateral. |
| law_contact_sharing_policies | shared_fields | json | Sim | Campos expostos: canais profissionais, endereço comercial e, opcionalmente, documentos. |
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
