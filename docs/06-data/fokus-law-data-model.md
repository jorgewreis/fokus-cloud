# Modelo de dados do Fokus Law

## Objetivo

Definir o modelo relacional alvo da v1 do Fokus Law para Cartorio Criminal, com
base reutilizavel para Cartorio Civel.

Este documento complementa:

- [Fokus Law](../04-products/fokus-law.md);
- [Requisitos do Fokus Law](../05-requirements/fokus-law.md);
- [Modelo relacional multiempresa](relational-model.md);
- [Modelo de empresas, unidades e vinculos](company-and-unit-model.md).

## Principios

- `companies` continua sendo a fronteira de isolamento empresarial.
- `company_units` continua sendo a unidade operacional generica da plataforma.
- `law_units` especializa `company_units` para o dominio juridico.
- Todas as tabelas operacionais do Law devem possuir `company_id`.
- Tabelas vinculadas a unidade juridica devem possuir `law_unit_id`.
- FKs empresariais devem impedir relacionamento entre entidades de empresas
  diferentes.
- Processos sao a entidade central da v1.
- Gestao de Processos e o nome comercial do modulo `processos`; o menu interno
  deve usar `Processos`.
- Gestao de Contatos e o nome comercial do modulo `contatos`; o menu interno
  deve usar `Contatos`.
- Contatos substituem o cadastro restrito de partes no modelo alvo.
- Partes processuais, advogados, instituicoes, orgaos e destinatarios sao
  papeis ou classificacoes contextuais de contatos.
- Cartas recebidas sao classe de processo, nao expediente separado.
- Dados oficiais e operacionais do processo devem ser separados.
- Tags informativas nao substituem classe, prioridade, sigilo ou status.
- Sigilo deve usar nivel no modelo alvo.
- Expedicoes sao expedientes vinculados a processo quando processuais.
- Oficios, mandados, cartas precatorias, cartas rogatorias, cartas de ordem,
  editais, guias de execucao e atos ordinatorios sao tipos de expedicao.
- Oficios possuem sequencia anual por unidade, instancia e setor.
- Cartas expedidas nao possuem numeracao interna propria na v1.
- Dados internos prevalecem para campos operacionais.
- Datajud prevalece apenas para metadados processuais publicos sincronizados.
- Dados sigilosos devem ser protegidos por permissao e evitados em logs.
- Exclusao fisica deve ser evitada em registros operacionais usados no fluxo.

## Prefixos

| Prefixo | Entidade |
| --- | --- |
| LWU | Unidade juridica do Fokus Law |
| LWM | Vinculo usuario-unidade Law |
| LCS | Processo Law |
| LCO | Contato Law |
| LDR | Endereco de contato Law |
| LCL | Vinculo pessoa-empresa de contatos Law |
| LCN | Canal de contato Law |
| LCV | Vinculo processo-contato Law |
| LEV | Vinculo expedicao-contato Law |
| LTV | Vinculo tarefa-contato Law |
| LET | Tipo de expedicao Law |
| LEI | Instancia de expedicao Law |
| LEX | Expedicao Law |
| LES | Sequencia de numeracao de expedicao Law |
| LTK | Prazo ou pendencia Law |
| LTT | Tipo de tarefa Law |
| LOR | Receita operacional Law |
| LTE | Vinculo tarefa-expedicao Law |
| LAL | Alerta operacional Law |
| LAU | Auditoria Law |
| LCA | Acesso a processo sigiloso Law |
| LSR | Solicitacao de suporte Law |
| LDS | Sincronizacao Datajud |
| LDD | Divergencia Datajud |

Todos os IDs devem seguir o padrao geral do projeto:

```text
XXX-ULID_DE_26_CARACTERES
```

## Tabelas alvo

### `law_units`

Especializa uma `company_unit` como unidade juridica.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LWU`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `company_unit_id` | char(30) | Sim | Unidade operacional generica. |
| `court_code` | varchar | Sim | Tribunal, exemplo: `TJBA`. |
| `district_name` | varchar | Sim | Comarca. |
| `unit_name` | varchar | Sim | Vara, cartorio ou unidade exibida. |
| `jurisdiction` | varchar | Sim | Competencia principal, exemplo: criminal ou civel. |
| `status` | enum | Sim | `active`, `suspended`, `archived`. |
| `version` | int | Sim | Controle de concorrencia. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |
| `deleted_at`, `deleted_by` | audit | Nao | Inativacao logica quando aplicavel. |

Regras:

- `company_unit_id` deve pertencer a mesma `company_id`.
- Uma `company_unit` pode ter no maximo uma `law_unit` ativa.
- `law_units` nao substitui dados cadastrais da empresa ou da unidade generica.

### `law_unit_memberships`

Define acesso do usuario a unidade juridica e perfil especifico do Fokus Law.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LWM`. |
| `company_id` | char(30) | Sim | Empresa do vinculo. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `company_membership_id` | char(30) | Sim | Vinculo usuario-empresa. |
| `law_access_role_id` | char(30) | Sim | Perfil do mesmo setor, protegido por chave estrangeira composta de empresa e setor. |
| `status` | string | Sim | `pendente`, `ativo`, `suspenso` ou `removido`. Convites permanecem pendentes até o aceite. |
| `version` | int | Sim | Controle de concorrencia otimista. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |
| `deleted_at` | timestamp | Nao | Data de remocao logica. |

Regras:

- O mesmo usuario pode ter perfis diferentes em unidades diferentes.
- Usuario so acessa uma `law_unit` se possuir vinculo ativo.
- Permissoes comerciais nao pertencem a esta tabela.

### `customer_permissions`

Catálogo atômico compartilhado de permissões de produtos clientes. Nesta etapa
os registros publicados pertencem ao produto `law`; permissões `platform.*`
continuam fora deste catálogo.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `CPM`. |
| `code` | varchar(100) | Sim | Código único estável, por exemplo `law.contacts.view`. |
| `product_code` | varchar(32) | Sim | Produto dono da permissão, atualmente `law`. |
| `resource` | varchar(48) | Sim | Recurso funcional, como `contacts` ou `users`. |
| `action` | varchar(48) | Sim | Ação atômica, como `view`, `create`, `update` ou `manage`. |
| `description` | varchar(180) | Sim | Descrição legível da capacidade. |

### `law_access_roles` e `law_access_role_permissions`

`law_access_roles` define perfis no escopo empresa/setor. Guarda `id` (prefixo
`LAR`), `company_id`, `law_unit_id`, `code`, `name`, `is_system`, `version`,
`created_by` e timestamps. `code` é único dentro da empresa e setor. Os perfis
protegidos `unit_admin`, `chief_clerk`, `operator` e `viewer` são semeados para
cada setor e não podem ser editados ou removidos; perfis personalizados usam
`is_system=false`.

`law_access_role_permissions` associa `law_access_role_id` a
`customer_permission_id`, com chave composta e exclusão restrita do catálogo.
Uma atribuição não pode combinar perfis, permissões, empresa ou setor de outro
escopo.

### `law_cases`

Representa processos do Fokus Law.

O detalhamento do modelo alvo da Gestao de Processos esta em [Modelo de dados da gestao de processos Law](law-case-management-data-model.md).

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LCS`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `case_number` | varchar | Sim | Numero do processo, preferencialmente CNJ quando houver. |
| `case_class` | varchar | Sim | Classe processual; cartas recebidas entram como classe. |
| `subjects` | json | Nao | Assuntos processuais. |
| `legal_basis` | json | Nao | Artigos, capitulacoes ou base legal informada. |
| `filing_date` | date | Nao | Data de autuacao. |
| `distribution_date` | date | Nao | Data de distribuicao. |
| `distribution_data` | json | Nao | Dados complementares de distribuicao. |
| `operational_status` | enum | Sim | `active`, `pending`, `suspended`, `archived`, `cancelled`. |
| `official_status_code` | varchar | Nao | Codigo/situacao externa sincronizada. |
| `official_status_text` | varchar | Nao | Texto de situacao externa sincronizada. |
| `operational_priority` | varchar | Nao | Prioridade operacional. |
| `confidentiality_level` | enum | Sim | `public_internal`, `unit_restricted`, `case_confidential`, `enhanced_confidential`. |
| `internal_tags` | json | Nao | Tags informativas da unidade. |
| `responsible_membership_id` | char(30) | Nao | Responsavel operacional. |
| `relevant_dates` | json | Nao | Datas operacionais relevantes. |
| `external_source` | varchar | Nao | Origem externa, exemplo: `datajud`. |
| `external_id` | varchar | Nao | Identificador externo quando disponivel. |
| `last_external_sync_at` | datetime | Nao | Ultima sincronizacao externa aplicada. |
| `notes` | text | Nao | Observacoes internas. |
| `cancelled_at`, `cancelled_by`, `cancel_reason` | audit | Nao | Obrigatorios quando cancelado. |
| `version` | int | Sim | Controle de concorrencia. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |
| `deleted_at`, `deleted_by` | audit | Nao | Inativacao logica. |

Regras:

- `operational_status` e interno e nao deve ser controlado pelo Datajud.
- Campos `official_*` representam situacao processual externa.
- Campos oficiais e operacionais devem permanecer separados.
- Tags nao substituem classe, prioridade, status ou sigilo.
- Cartas recebidas devem usar `case_class`, nao tabela de expediente.
- `confidentiality_level` substitui sigilo apenas binario no modelo alvo.
- Processo sigiloso deve restringir contatos vinculados, partes, expedientes,
  tarefas, listas, buscas, indicadores detalhados e exportacoes.

### `law_contacts`

Representa contatos reutilizaveis dentro da empresa/unidade juridica.

O detalhamento do modelo alvo da Gestao de Contatos esta em [Modelo de dados da gestao de contatos Law](law-contacts-data-model.md).

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LCO`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Nao | Unidade juridica, quando o contato for restrito a uma unidade. |
| `display_name` | varchar | Sim | Nome exibido. |
| `acronym` | varchar(32) | Nao | Sigla exibida para pessoa juridica. |
| `legal_name` | varchar | Nao | Nome completo ou razao social. |
| `document_type` | enum | Nao | `cpf`, `cnpj`, `other`, quando informado. |
| `document_number` | varchar | Nao | Normalizado e protegido quando aplicavel. |
| `oab_number`, `oab_state` | varchar | Nao | Identificacao profissional quando contato for advogado. |
| `contact_type` | enum | Sim | `person`, `organization`, `lawyer`, `law_firm`, `public_body`, `court_unit`, `police_unit`, `prosecutor_office`, `public_defender`, `expert`, `unknown`. |
| `tags` | json | Nao | Tags informativas do contato. |
| `status` | enum | Sim | `active`, `inactive`, `merged`. |
| `notes` | text | Nao | Observacoes internas. |
| `merged_into_contact_id`, `merged_reason` | audit | Nao | Obrigatorios quando mesclado. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |
| `deleted_at`, `deleted_by` | audit | Nao | Inativacao logica. |

Regras:

- O cadastro deve conter apenas dados necessarios para identificacao
  operacional.
- Dados pessoais devem ser minimizados e mascarados quando exibidos em contexto
  sem permissao.
- O contato nao define papel processual sozinho; o papel fica no vinculo com o
  processo.
- O contato pode ser destinatario ou orgao de destino em expedicoes.
- O contato pode ser referencia externa em tarefas.

### `law_contact_company_links`

Relaciona pessoas fisicas a pessoas juridicas por muitos-para-muitos dentro da
mesma empresa proprietaria. A linha guarda `company_id`, `person_contact_id` e
`company_contact_id`; ambos os contatos devem pertencer a essa empresa e ser
ativos quando o vinculo e criado. A ficha consulta a relacao em ambos os
sentidos. A relacao interna nao e incluida em referencias compartilhadas entre
empresas.

### `law_contact_addresses` e `law_contact_channels`

Representam enderecos e meios de contato vinculados a um contato.

Campos detalhados estao em [Modelo de dados da gestao de contatos Law](law-contacts-data-model.md).

Regras:

- Um contato pode possuir multiplos enderecos e canais.
- Um endereco ou canal pode ser marcado como principal.
- Canais e documentos devem ser mascarados quando a permissao nao permitir
  exibicao completa.

### `law_case_contacts`

Relaciona contatos a processos com papel contextual.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LCV`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `law_case_id` | char(30) | Sim | Processo vinculado. |
| `law_contact_id` | char(30) | Sim | Contato vinculado. |
| `case_role` | enum | Sim | `author`, `defendant`, `victim`, `witness`, `lawyer`, `prosecutor`, `defender`, `representative`, `interested`, `origin_body`, `other`. |
| `role_detail` | varchar | Nao | Complemento quando `role = other` ou quando necessario. |
| `status` | enum | Sim | `active`, `inactive`. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |

Regras:

- O mesmo contato pode ter papeis diferentes em processos diferentes.
- O vinculo deve respeitar a mesma `company_id` e `law_unit_id` de processo e
  contato.

### `law_expedition_contacts` e `law_task_contacts`

Relacionam contatos a expedicoes e tarefas.

Campos detalhados estao em [Modelo de dados da gestao de contatos Law](law-contacts-data-model.md).

Regras:

- Expedicoes podem usar contatos como destinatario, orgao de destino, unidade
  externa, responsavel por recebimento ou copia.
- Tarefas podem referenciar contatos externos sem substituir responsavel interno.
- Expedicoes devem preservar snapshot minimo do contato quando necessario para
  historico documental.

### Expedicoes

O nucleo de expedicoes substitui as tabelas separadas de oficios e cartas
expedidas.

Tabelas alvo:

- `law_expedition_types`;
- `law_expedition_instances`;
- `law_expeditions`;
- `law_expedition_number_sequences`.

O detalhamento das tabelas, campos, indices e regras condicionais esta em
[Modelo de dados das expedicoes Law](law-expeditions-data-model.md).

Regras principais:

- oficio e tipo de expedicao com numeracao interna obrigatoria;
- cartas precatoria, rogatoria e de ordem, mandados, editais, guias e atos
  ordinatorios sao tipos de expedicao;
- cartas expedidas nao possuem numeracao interna propria na v1;
- numero externo de destino e opcional quando o tipo aceitar;
- expedicoes processuais sempre devem possuir processo de origem;
- geracao do proximo numero deve ocorrer em transacao;
- alteracoes de tipo, numero, instancia, processo ou status devem ser auditadas.

### Tarefas e fluxos operacionais

O nucleo de tarefas e fluxos representa o trabalho a cumprir e sua conexao com
expedicoes, prazos e alertas.

Tabelas alvo:

- `law_task_types`;
- `law_operation_recipes`;
- `law_task_expeditions`.

O detalhamento esta em [Modelo de dados de tarefas e fluxos Law](law-operational-workflows-data-model.md).

### Audiencias

Audiencias possuem nucleo proprio, participantes, historico de status, alertas
e acessos externos. O detalhamento esta em [Modelo de dados de audiencias Law](law-hearings-data-model.md).

### `law_tasks`

Representa prazos e pendencias.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LTK`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `target_type` | enum | Sim | `case`, `expedition`, `task`. |
| `target_id` | char(30) | Sim | Entidade relacionada. |
| `task_type` | enum | Sim | `deadline`, `pending_item`. |
| `title` | varchar | Sim | Titulo curto. |
| `description` | text | Nao | Detalhamento. |
| `responsible_membership_id` | char(30) | Nao | Responsavel. |
| `due_at` | datetime | Nao | Data limite. |
| `priority` | enum | Sim | `low`, `normal`, `high`, `urgent`. |
| `status` | enum | Sim | `open`, `in_progress`, `waiting`, `done`, `cancelled`, `overdue`. |
| `completed_at`, `completed_by` | audit | Nao | Preenchidos quando concluida. |
| `cancelled_at`, `cancelled_by`, `cancel_reason` | audit | Nao | Obrigatorios quando cancelada. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |

Regras:

- `due_at` pode ser nulo para pendencia sem prazo.
- Prazos vencidos devem ser identificaveis por consulta e dashboard.
- Alteracao de data limite, responsavel, status ou cancelamento deve ser
  auditada.

### `law_alerts`

Representa alertas operacionais do Fokus Law.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LAL`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `alert_type` | enum | Sim | `deadline`, `confidentiality`, `datajud`, `operation`. |
| `severity` | enum | Sim | `low`, `medium`, `high`, `critical`. |
| `status` | enum | Sim | `open`, `acknowledged`, `resolved`, `dismissed`. |
| `target_type` | enum | Nao | `case`, `expedition`, `task`, `datajud_divergence`. |
| `target_id` | char(30) | Nao | Entidade relacionada. |
| `message` | varchar | Sim | Mensagem resumida. |
| `assigned_membership_id` | char(30) | Nao | Responsavel pelo tratamento. |
| `opened_at` | datetime | Sim | Data de abertura. |
| `resolved_at`, `resolved_by` | audit | Nao | Dados de resolucao. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |

Regras:

- Alertas Law nao devem usar `platform_alerts`, que pertence ao Backoffice.
- Alertas devem respeitar unidade ativa e sigilo do processo relacionado.

### `law_confidential_case_accesses`

Registra autorizacao explicita para processos sigilosos.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LCA`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `law_case_id` | char(30) | Sim | Processo sigiloso. |
| `law_unit_membership_id` | char(30) | Sim | Usuario/vinculo autorizado. |
| `access_level` | enum | Sim | `view`, `operate`, `manage_confidentiality`. |
| `granted_by` | char(30) | Sim | Usuario que concedeu acesso. |
| `grant_reason` | varchar | Sim | Motivo da concessao. |
| `expires_at` | datetime | Nao | Validade opcional. |
| `revoked_at`, `revoked_by`, `revoke_reason` | audit | Nao | Dados de revogacao. |
| `status` | enum | Sim | `active`, `expired`, `revoked`. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |

Regras:

- Acesso sigiloso e sempre explicito por processo.
- Concessao e revogacao devem gerar auditoria.
- Processo sigiloso deve continuar mascarado para usuarios sem acesso ativo.

### `law_audit_events`

Registra auditoria operacional do Fokus Law.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LAU`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `user_id` | char(30) | Sim | Usuario global que executou a acao. |
| `law_unit_membership_id` | char(30) | Nao | Perfil usado na unidade. |
| `entity_type` | varchar | Sim | Tipo da entidade auditada. |
| `entity_id` | char(30) | Sim | Entidade auditada. |
| `action` | varchar | Sim | Acao executada. |
| `reason` | varchar | Nao | Motivo, quando obrigatorio. |
| `before_values` | json | Nao | Valores anteriores minimizados. |
| `after_values` | json | Nao | Valores posteriores minimizados. |
| `metadata` | json | Nao | Metadados tecnicos minimos. |
| `created_at` | datetime | Sim | Data do evento. |

Regras:

- Auditoria Law e separada de `platform_audit_events`.
- Motivo e obrigatorio para sigilo, cancelamento, inativacao, prazo, mudanca de
  responsavel e alteracao que afete historico operacional relevante.
- Dados sensiveis devem ser mascarados ou minimizados em `before_values`,
  `after_values` e `metadata`.

### `law_support_requests`

Registra solicitacoes de suporte orientativo abertas dentro do produto.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LSR`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `requested_by` | char(30) | Sim | Usuario solicitante. |
| `category` | enum | Sim | `access_permissions`, `usage_question`, `technical_error`, `subscription_modules`. |
| `status` | enum | Sim | `open`, `in_review`, `answered`, `closed`, `cancelled`. |
| `description` | text | Sim | Descricao informada pelo usuario, sem dados sensiveis. |
| `sanitized_attachments` | json | Nao | Referencias a prints/anexos sanitizados. |
| `technical_metadata` | json | Nao | Metadados tecnicos minimos e sanitizados. |
| `answered_at`, `answered_by` | audit | Nao | Dados de resposta orientativa. |
| `closed_at`, `closed_by` | audit | Nao | Dados de encerramento. |
| `expires_at` | datetime | Sim | Retencao de 90 dias. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |

Regras:

- Suporte e orientativo e nao concede acesso operacional ao Backoffice.
- Prints/anexos nao sanitizados nao devem ser armazenados.
- Metadados nao devem incluir dados juridicos sensiveis, payload Datajud bruto
  ou conteudo sigiloso.

### `law_datajud_syncs`

Registra consultas e sincronizacoes Datajud.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LDS`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `law_case_id` | char(30) | Nao | Processo relacionado, quando identificado. |
| `query_case_number` | varchar | Sim | Numero consultado. |
| `status` | enum | Sim | `requested`, `success`, `partial`, `failed`, `ignored`. |
| `result_summary` | json | Nao | Resumo sanitizado do retorno. |
| `applied_metadata` | json | Nao | Campos aplicados no processo. |
| `error_code` | varchar | Nao | Codigo de erro. |
| `error_message` | varchar | Nao | Mensagem resumida, sem payload bruto sensivel. |
| `requested_by` | char(30) | Nao | Usuario relacionado, quando houver; jobs automaticos podem usar ator de sistema. |
| `trigger_type` | enum | Sim | `case_created`, `case_moved`, `retry`. |
| `requested_at` | datetime | Sim | Data da consulta. |
| `finished_at` | datetime | Nao | Data de conclusao. |

Regras:

- Payload bruto do Datajud nao deve ser armazenado sem sanitizacao e necessidade
  explicita.
- Falha de sincronizacao nao pode corromper nem bloquear dados internos.
- Metadados aplicados devem ser auditaveis.

### `law_datajud_divergences`

Registra divergencias entre dados internos e Datajud.

| Campo | Tipo | Obrigatorio | Regra |
| --- | --- | --- | --- |
| `id` | char(30) | Sim | Prefixo `LDD`. |
| `company_id` | char(30) | Sim | Empresa proprietaria. |
| `law_unit_id` | char(30) | Sim | Unidade juridica. |
| `law_case_id` | char(30) | Sim | Processo relacionado. |
| `law_datajud_sync_id` | char(30) | Nao | Sincronizacao que detectou a divergencia. |
| `field_name` | varchar | Sim | Campo divergente. |
| `internal_value` | json | Nao | Valor interno minimizado. |
| `external_value` | json | Nao | Valor externo minimizado. |
| `status` | enum | Sim | `open`, `reviewed`, `applied`, `dismissed`. |
| `reviewed_by`, `reviewed_at` | audit | Nao | Dados de revisao. |
| `resolution_reason` | varchar | Nao | Motivo da decisao. |
| `created_at`, `created_by` | audit | Sim | Metadados de criacao. |
| `updated_at`, `updated_by` | audit | Sim | Metadados de alteracao. |

Regras:

- Divergencias nao devem sobrescrever campos operacionais automaticamente.
- Resolucao deve gerar auditoria quando alterar dados internos.

## Relacionamentos

```text
companies ──< company_units ──< law_units
companies ──< company_memberships ──< law_unit_memberships >── law_units

law_units ──< law_cases
law_units ──< law_contacts
law_contacts ──< law_contact_addresses
law_contacts ──< law_contact_channels
law_cases ──< law_case_contacts >── law_contacts

law_cases ──< law_expeditions
law_expeditions ──< law_expedition_contacts >── law_contacts

law_cases ──< law_tasks
law_expeditions ──< law_tasks
law_tasks ──< law_task_expeditions >── law_expeditions
law_tasks ──< law_task_contacts >── law_contacts

law_units ──< law_alerts
law_units ──< law_audit_events
law_cases ──< law_confidential_case_accesses
law_units ──< law_support_requests
law_cases ──< law_datajud_syncs
law_datajud_syncs ──< law_datajud_divergences
```

Como `law_tasks` e `law_alerts` podem apontar para mais de um tipo de entidade,
o modelo deve validar a integridade polimorfica na camada de dominio e manter
indices por `company_id`, `law_unit_id`, `target_type` e `target_id`.

## Indices recomendados

- `law_units`: unico por `(company_id, company_unit_id)`.
- `law_unit_memberships`: unico por `(company_id, law_unit_id, company_membership_id)`.
- `law_cases`: indice por `(company_id, law_unit_id, case_number)`.
- `law_cases`: indice por `(company_id, law_unit_id, operational_status)`.
- `law_cases`: indice por `(company_id, law_unit_id, confidentiality_level)`.
- `law_contacts`: indice por `(company_id, law_unit_id, display_name)`.
- `law_contacts`: indice por `(company_id, law_unit_id, contact_type)`.
- `law_case_contacts`: unico por `(company_id, law_case_id, law_contact_id, case_role)`.
- `law_expedition_contacts`: indice por `(company_id, law_expedition_id, law_contact_id)`.
- `law_expedition_types`: unico por `(company_id, code)`.
- `law_expedition_instances`: unico por `(company_id, law_unit_id, sector_code)`.
- `law_expeditions`: indice por `(company_id, law_unit_id, law_case_id)`.
- `law_expeditions`: indice por `(company_id, law_unit_id, law_expedition_type_id, status)`.
- `law_expedition_number_sequences`: unico por `(company_id, law_unit_id, law_expedition_type_id, law_expedition_instance_id, sequence_year)`.
- `law_tasks`: indice por `(company_id, law_unit_id, status, due_at)`.
- `law_task_types`: unico por `(company_id, code)`.
- `law_operation_recipes`: indice por `(company_id, law_unit_id, task_type_id)`.
- `law_task_expeditions`: indice por `(company_id, law_unit_id, law_task_id)`.
- `law_alerts`: indice por `(company_id, law_unit_id, status, severity)`.
- `law_audit_events`: indice por `(company_id, law_unit_id, entity_type, entity_id, created_at)`.
- `law_confidential_case_accesses`: indice por `(company_id, law_unit_id, law_case_id, status)`.
- `law_support_requests`: indice por `(company_id, law_unit_id, status, category)`.
- `law_datajud_syncs`: indice por `(company_id, law_unit_id, query_case_number, requested_at)`.
- `law_datajud_divergences`: indice por `(company_id, law_unit_id, law_case_id, status)`.

## Pseudo-DDL de referencia

```sql
CREATE TABLE law_units (
  id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  company_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  company_unit_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  court_code VARCHAR(30) NOT NULL,
  district_name VARCHAR(120) NOT NULL,
  unit_name VARCHAR(160) NOT NULL,
  jurisdiction VARCHAR(80) NOT NULL,
  status ENUM('active', 'suspended', 'archived') NOT NULL DEFAULT 'active',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  created_by CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  updated_by CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  deleted_at DATETIME(6) NULL,
  deleted_by CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
  UNIQUE KEY uq_law_unit_company_unit (company_id, company_unit_id),
  UNIQUE KEY uq_law_unit_company_id (company_id, id),
  CONSTRAINT fk_law_unit_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
  CONSTRAINT fk_law_unit_company_unit FOREIGN KEY (company_id, company_unit_id)
    REFERENCES company_units (company_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE law_cases (
  id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  company_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  law_unit_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  case_number VARCHAR(40) NOT NULL,
  case_class VARCHAR(120) NOT NULL,
  subjects JSON NULL,
  legal_basis JSON NULL,
  filing_date DATE NULL,
  distribution_date DATE NULL,
  distribution_data JSON NULL,
  operational_status ENUM('active', 'pending', 'suspended', 'archived', 'cancelled') NOT NULL DEFAULT 'pending',
  official_status_code VARCHAR(80) NULL,
  official_status_text VARCHAR(255) NULL,
  operational_priority VARCHAR(80) NULL,
  confidentiality_level ENUM('public_internal', 'unit_restricted', 'case_confidential', 'enhanced_confidential') NOT NULL DEFAULT 'public_internal',
  internal_tags JSON NULL,
  responsible_membership_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
  relevant_dates JSON NULL,
  external_source VARCHAR(40) NULL,
  external_id VARCHAR(120) NULL,
  last_external_sync_at DATETIME(6) NULL,
  notes TEXT NULL,
  cancelled_at DATETIME(6) NULL,
  cancelled_by CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
  cancel_reason VARCHAR(255) NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  created_by CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  updated_by CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  deleted_at DATETIME(6) NULL,
  deleted_by CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
  UNIQUE KEY uq_law_case_company_id (company_id, id),
  KEY ix_law_case_number (company_id, law_unit_id, case_number),
  KEY ix_law_case_status (company_id, law_unit_id, operational_status),
  KEY ix_law_case_confidentiality (company_id, law_unit_id, confidentiality_level),
  CONSTRAINT fk_law_case_unit FOREIGN KEY (company_id, law_unit_id)
    REFERENCES law_units (company_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

As demais tabelas devem seguir os mesmos padroes de `company_id`, chave unica
auxiliar `(company_id, id)`, FKs compostas, `ON DELETE RESTRICT`, metadados de
criacao/alteracao e versionamento quando houver edicao concorrente.

## Consultas essenciais

- listar processos ativos por unidade e status;
- listar processos por classe, assunto, prioridade, tag e nivel de sigilo;
- buscar processo por numero dentro da unidade;
- listar processos sigilosos apenas para usuarios autorizados;
- listar contatos de um processo por papel;
- listar processos vinculados a um contato;
- listar expedicoes vinculadas a um contato;
- listar expedicoes por unidade, tipo, instancia, ano e status;
- obter proximo numero de expedicao numerada por unidade, tipo, instancia e ano;
- listar expedicoes por processo, destino e status;
- listar receitas operacionais habilitadas por unidade;
- listar vinculos entre tarefas e expedicoes;
- listar tarefas vencidas e a vencer por responsavel;
- consolidar dashboard por unidade;
- listar alertas abertos por unidade;
- consultar auditoria por entidade;
- listar acessos sigilosos ativos por processo;
- listar solicitacoes de suporte por unidade, categoria e status;
- consultar historico de sincronizacao Datajud por processo;
- listar divergencias Datajud abertas.
- montar linha do tempo do processo por movimentacoes, tarefas, expedicoes,
  prazos, contatos, partes e auditoria.

## Regras para migrations

- Criar tabelas em ordem de dependencia: `law_units`, `law_unit_memberships`,
  `law_cases`, `law_contacts`, `law_contact_addresses`,
  `law_contact_channels`, `law_case_contacts`,
  `law_expedition_types`, `law_expedition_instances`,
  `law_expedition_number_sequences`, `law_expeditions`, `law_task_types`,
  `law_operation_recipes`, `law_tasks`, `law_expedition_contacts`,
  `law_task_contacts`, `law_task_expeditions`, `law_alerts`,
  `law_confidential_case_accesses`, `law_audit_events`,
  `law_support_requests`, `law_datajud_syncs`, `law_datajud_divergences`.
- Criar FKs compostas com `company_id` sempre que o pai tambem for empresarial.
- Nao usar cascade delete automatico.
- Incluir indices de listagem operacional antes de criar telas dependentes.
- Tratar sequencias de expedicoes numeradas em transacao.
- Proteger campos sensiveis em logs, auditoria e payloads de integracao.
- Validar no backend assinatura ativa, modulo contratado, unidade ativa,
  permissao e sigilo em todas as rotas.

## Criterios de aceite

- Todas as tabelas alvo possuem prefixo de ID definido.
- O modelo separa `company_units` de `law_units`.
- O modelo permite perfil Law por unidade.
- Processos sao entidade central e pertencem a empresa e unidade juridica.
- Gestao de Processos separa dados oficiais e operacionais.
- Gestao de Contatos substitui o cadastro restrito de partes no modelo alvo.
- Partes, advogados, instituicoes, orgaos e destinatarios sao papeis ou
  classificacoes de contatos.
- Tags processuais sao informativas.
- Sigilo usa `confidentiality_level` no modelo alvo.
- Cartas recebidas nao possuem tabela propria de expediente.
- Expedicoes usam tipos e instancias configuraveis.
- Oficios possuem sequencia anual por unidade, instancia e ano.
- Cartas expedidas nao possuem numeracao interna propria na v1.
- Prazos e pendencias usam `law_tasks`.
- Tarefas e fluxos usam tipos, receitas operacionais e vinculos com expedicoes.
- Alertas Law usam `law_alerts`, separados de `platform_alerts`.
- Auditoria Law usa `law_audit_events`, separada de `platform_audit_events`.
- Acesso sigiloso usa `law_confidential_case_accesses`.
- Suporte orientativo usa `law_support_requests`.
- Datajud usa `law_datajud_syncs` e `law_datajud_divergences`.
- Dados operacionais internos nao sao sobrescritos automaticamente pelo Datajud.
- Sigilo pode ser aplicado de forma transversal em consultas e indicadores.
