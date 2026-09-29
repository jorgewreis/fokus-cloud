# Modelo relacional multiempresa

## Convenções

Este modelo é a referência para MySQL/Percona Server 8.4 com InnoDB. A conta
da pessoa é global; dados operacionais, comerciais e de auditoria pertencem a
uma única empresa.

Todo cadastro usa ID textual, imutável e exclusivo no formato abaixo:

```text
XXX-ULID_DE_26_CARACTERES
EMP-01J8K2M3N4P5Q6R7S8T9V0WXYZ
```

O campo é `CHAR(30) CHARACTER SET ascii COLLATE ascii_bin`, chave primária,
com prefixo e ULID em maiúsculas. Os IDs são imutáveis.

| Prefixo | Entidade |
| --- | --- |
| EMP | Empresa |
| USR | Usuário global |
| VNC | Vínculo usuário-empresa |
| PFL | Perfil |
| PRD | Produto |
| PLN | Plano |
| MOD | Módulo |
| UNT | Unidade empresarial |
| MVU | Vinculo usuario-unidade |
| ASS | Assinatura |
| ITM | Item contratado |
| CNV | Convite ou aceite de vínculo |
| TKN | Token temporário |
| MFA | Desafio de MFA interno |
| AUD | Auditoria |
| SUP | Acesso temporário de suporte |
| PAD | Administrador interno da plataforma |
| ALT | Alerta operacional |
| ALC | Comentario de alerta operacional |
| INC | Incidente operacional |
| NTF | Notificacao operacional |
| PAG | Pagamento |
| VCH | Voucher |
| VRD | Resgate de voucher |
| SCH | Mudanca de assinatura |
| REE | Reembolso |
| RCA | Alerta de conciliacao |
| LWU | Unidade juridica do Fokus Law |
| LWM | Vinculo usuario-unidade Law |
| LCS | Processo Law |
| LCO | Contato Law |
| LDR | Endereco de contato Law |
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

## Entidades e escopo

### Globais

| Entidade | Chave | Regras |
| --- | --- | --- |
| `users` | USR | CPF e e-mail normalizados e únicos; credenciais e status da conta. |
| `roles` | PFL | Catálogo fixo: `admin`, `gestor`, `usuario`. |
| `products` | PRD | Catálogo de produtos. |
| `plans` | PLN | Catálogo comercial de planos por produto e linha. |
| `modules` | MOD | Catálogo comercial de funcionalidades por produto. |
| `security_tokens` | TKN | Confirmação, recuperação e criação de senha. |
| `user_credentials` | CRD | Hash de senha e futuras credenciais de autenticacao. |
| `user_preferences` | PRF | Idioma, fuso horario e preferencias da conta. |
| `legal_acceptances` | ACE | Aceites versionados de termos e politicas. |
| `identity_verifications` | IDV | Validacoes de nome civil e identidade. |
| `user_sessions` | SES | Sessoes web e dispositivos autenticados. |
| `mobile_refresh_tokens` | RFT | Refresh tokens moveis rotativos e revogaveis. |
| `platform_admins` | PAD | Contas internas do Backoffice, separadas de `users`. |
| `platform_login_challenges` | MFA | Desafios de MFA por e-mail para contas internas. |
| `platform_audit_events` | AUD | Auditoria de acoes internas do Backoffice. |
| `vouchers` | VCH | Beneficios comerciais administraveis pelo Backoffice. |

Os aceites legais ficam vinculados à conta global, com versão e data dos
Termos de Uso e da Política de Privacidade.

### Exclusivas da empresa

Todas as entidades abaixo possuem `company_id NOT NULL`:

| Entidade | Chave | Finalidade |
| --- | --- | --- |
| `companies` | EMP | Fronteira de isolamento; CPF/CNPJ e nome imutáveis. |
| `company_memberships` | VNC | Usuário, empresa, perfil e status do vínculo. |
| `company_units` | Unidade | Filiais, departamentos ou equipes operacionais da empresa. |
| `membership_units` | Vinculo | Relacao entre um vinculo e uma ou mais unidades. |
| `company_invitations` | CNV | Criação de senha e aceite de vínculo. |
| `subscriptions` | ASS | Assinatura de empresa por produto. |
| `subscription_items` | ITM | Snapshot de módulo, quantidade, preço e condições. |
| `subscription_changes` | SCH | Historico de upgrade, downgrade, cancelamento, suspensao e reativacao. |
| `payments` | PAG | Pagamentos e cobrancas recorrentes por assinatura. |
| `voucher_redemptions` | VRD | Resgates de vouchers com snapshot comercial. |
| `refund_requests` | REE | Solicitacoes e execucoes de reembolso. |
| `payment_reconciliation_alerts` | RCA | Divergencias entre status interno e Mercado Pago. |
| `platform_alerts` | ALT | Alertas operacionais do Backoffice. |
| `platform_alert_comments` | ALC | Comentarios e historico de alerta. |
| `platform_incidents` | INC | Incidentes criticos e ciclo de resposta. |
| `platform_notifications` | NTF | Notificacoes imediatas por e-mail e dashboard. |
| `law_units` | LWU | Especializacao juridica de unidade operacional para o Fokus Law. |
| `law_unit_memberships` | LWM | Perfil e acesso do usuario em uma unidade juridica. |
| `law_cases` | LCS | Gestao de Processos do Fokus Law, incluindo cartas recebidas como classe de processo. |
| `law_contacts` | LCO | Gestao de Contatos do Fokus Law: pessoas, advogados, instituicoes, orgaos, partes e destinatarios reutilizaveis. |
| `law_contact_addresses` | LDR | Enderecos vinculados a contatos. |
| `law_contact_channels` | LCN | Telefones, e-mails e canais vinculados a contatos. |
| `law_case_contacts` | LCV | Vinculo entre processo e contato, com papel processual contextual. |
| `law_expedition_types` | LET | Tipos de expedicao, como oficio e cartas expedidas. |
| `law_expedition_instances` | LEI | Instancias de expedicao por unidade e setor. |
| `law_expedition_number_sequences` | LES | Sequencias anuais de expedicoes numeradas. |
| `law_expeditions` | LEX | Expedicoes vinculadas a processo quando processuais. |
| `law_expedition_contacts` | LEV | Vinculos entre expedicoes e contatos destinatarios ou externos. |
| `law_tasks` | LTK | Prazos, pendencias e tarefas vinculaveis a processo, expedicao ou tarefa. |
| `law_task_types` | LTT | Tipos de tarefa operacional do Fokus Law. |
| `law_operation_recipes` | LOR | Receitas que conectam tarefas, expedicoes, prazos e alertas. |
| `law_task_expeditions` | LTE | Vinculos entre tarefas e expedicoes. |
| `law_task_contacts` | LTV | Vinculos opcionais entre tarefas e contatos externos. |
| `law_alerts` | LAL | Alertas operacionais do Fokus Law. |
| `law_audit_events` | LAU | Auditoria operacional do Fokus Law. |
| `law_confidential_case_accesses` | LCA | Autorizacoes explicitas para processos sigilosos. |
| `law_support_requests` | LSR | Solicitacoes de suporte orientativo abertas dentro do produto. |
| `law_datajud_syncs` | LDS | Consultas e sincronizacoes Datajud. |
| `law_datajud_divergences` | LDD | Divergencias entre dados internos e Datajud. |
| `audit_events` | AUD | Histórico estruturado e mascarado. |
| `support_accesses` | SUP | Acesso temporário e justificado da Fokus. |
| Cadastros operacionais futuros | Prefixo próprio | Dados de Law, Lead e outros produtos. |

## Relacionamentos e restrições

```text
users ──< company_memberships >── companies
roles ────────────────────────────┘

companies ──< subscriptions >── products
subscriptions ──< subscription_items >── modules
subscriptions ──< subscription_changes
subscriptions ──< payments ──< refund_requests
payments ──< payment_reconciliation_alerts
plans ──< plan_modules >── modules
companies ──< company_units ──< membership_units >── company_memberships
users ──< user_sessions
user_sessions ──< mobile_refresh_tokens
platform_admins ──< platform_login_challenges
platform_admins ──< platform_audit_events
platform_alerts ──< platform_alert_comments
platform_incidents ──< platform_alerts
company_units ──< law_units
company_memberships ──< law_unit_memberships >── law_units
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

- Um usuário pode possuir vínculos com várias empresas.
- Uma empresa possui um único vínculo ativo com perfil `admin`.
- A unidade `headquarters` e criada automaticamente para cada empresa.
- `admin` possui escopo empresarial e nao precisa de `membership_units`.
- `gestor` e `usuario` sem unidades ativas ficam com vinculo `suspended`.
- Adicionar unidade nao remove associacoes existentes; transferencia remove a anterior em transacao.
- Uma empresa possui no máximo uma assinatura não encerrada por produto.
- Itens de assinatura são snapshots e não mudam com o catálogo atual.
- O modelo alvo de Backoffice e Billing esta detalhado em [Modelo de dados do Backoffice e Billing](backoffice-and-billing-data-model.md).
- O modelo alvo do Fokus Law esta detalhado em [Modelo de dados do Fokus Law](fokus-law-data-model.md).
- A composição publicada de um plano é formada por `plan_modules`; uma funcionalidade pode estar em vários planos do mesmo produto.
- FKs usam `ON DELETE RESTRICT`; nunca há exclusão em cascata automática.
- Tabelas filhas empresariais possuem chave única auxiliar `(company_id, id)`.
  FKs compostas `(company_id, parent_id)` garantem que pai e filho pertençam à
  mesma empresa.

## Pseudo-DDL de referência

```sql
CREATE TABLE companies (
  id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  document_type ENUM('cpf', 'cnpj') NOT NULL,
  document_number VARCHAR(14) CHARACTER SET ascii NOT NULL,
  legal_name VARCHAR(255) NOT NULL,
  status ENUM('pending', 'active', 'suspended', 'cancelled', 'closed') NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  created_by VARCHAR(30) CHARACTER SET ascii NULL,
  updated_at DATETIME(6) NOT NULL,
  updated_by VARCHAR(30) CHARACTER SET ascii NULL,
  deleted_at DATETIME(6) NULL,
  deleted_by VARCHAR(30) CHARACTER SET ascii NULL,
  UNIQUE KEY uq_company_document (document_type, document_number)
) ENGINE=InnoDB;

CREATE TABLE company_units (
  id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  company_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  unit_type ENUM('headquarters', 'branch', 'department', 'team') NOT NULL,
  name VARCHAR(255) NOT NULL,
  document_type ENUM('cpf', 'cnpj') NULL,
  document_number VARCHAR(14) CHARACTER SET ascii NULL,
  status ENUM('active', 'suspended', 'closed') NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL,
  created_by VARCHAR(30) CHARACTER SET ascii NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  updated_by VARCHAR(30) CHARACTER SET ascii NOT NULL,
  UNIQUE KEY uq_unit_company_id (company_id, id),
  UNIQUE KEY uq_unit_company_document (company_id, document_type, document_number),
  CONSTRAINT fk_unit_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE company_memberships (
  id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  company_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('pending', 'active', 'suspended', 'removed') NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  created_by VARCHAR(30) CHARACTER SET ascii NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  updated_by VARCHAR(30) CHARACTER SET ascii NOT NULL,
  deleted_at DATETIME(6) NULL,
  deleted_by VARCHAR(30) CHARACTER SET ascii NULL,
  UNIQUE KEY uq_membership_company_user (company_id, user_id),
  UNIQUE KEY uq_membership_company_id (company_id, id),
  CONSTRAINT fk_membership_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
  CONSTRAINT fk_membership_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_membership_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE membership_units (
  membership_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  company_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  unit_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL,
  created_by VARCHAR(30) CHARACTER SET ascii NOT NULL,
  PRIMARY KEY (membership_id, unit_id),
  CONSTRAINT fk_membership_unit_membership FOREIGN KEY (company_id, membership_id)
    REFERENCES company_memberships (company_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_membership_unit_unit FOREIGN KEY (company_id, unit_id)
    REFERENCES company_units (company_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

Como MySQL não possui índice parcial nativo, a migration usa a coluna auxiliar
`active_admin_company_id`, única e nula para vínculos não administrativos. A
transferência atualiza os dois vínculos em uma única transação.

```sql
CREATE TABLE subscriptions (
  id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  company_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('pendente', 'ativa', 'suspensa', 'encerrada') NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  created_by VARCHAR(30) CHARACTER SET ascii NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  updated_by VARCHAR(30) CHARACTER SET ascii NOT NULL,
  deleted_at DATETIME(6) NULL,
  deleted_by VARCHAR(30) CHARACTER SET ascii NULL,
  UNIQUE KEY uq_subscription_company_id (company_id, id),
  CONSTRAINT fk_subscription_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscription_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

A unicidade de assinatura não encerrada por empresa e produto é condicional e
usa `open_company_product`, preenchida apenas nos estados não encerrados, e
índice único. O webhook atualiza pagamento e assinatura de forma idempotente.

## Metadados e concorrência

Todo cadastro empresarial armazena `created_at`, `created_by`, `updated_at`,
`updated_by`, `deleted_at`, `deleted_by`, `status` e `version`. Atualizações
exigem a versão atual; conflitos impedem a gravação e exigem revisão.

### Gestão de Contatos: relações e dados complementares

| Tabela | Finalidade |
| --- | --- |
| `law_contact_relationship_roles` | Papéis por relação PF↔PJ, complemento e período de vigência. |
| `law_contact_relationship_designations` | Histórico de cargos, postos, graduações ou funções por relação. |
| `law_contact_institutional_data` | Dados opcionais de unidade judiciária ou órgão público. |