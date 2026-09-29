# Modelo de dados da Gestão de Contatos Law

## Propriedade e relações

A empresa (`company_id`) é dona dos cadastros. `law_unit_id` legado é mantido
por compatibilidade, mas novos contatos usam `NULL`: setores internos
autorizados veem a base da empresa. `legal_nature` diferencia `pf` e `pj`;
classificações e tags são relações separadas. Outros módulos usarão o ID
estável de contato e guardarão seus papéis contextuais em tabelas próprias.

```mermaid
erDiagram
    companies ||--o{ law_contacts : owns
    law_contacts ||--o{ law_contact_addresses : has
    law_contacts ||--o{ law_contact_channels : has
    law_contacts ||--o{ law_contact_documents : identifies
    law_contacts ||--o{ law_contact_departments : organizes
    law_contact_departments ||--o{ law_contact_channels : has
    law_contacts ||--o{ law_contact_classifications : classified
    law_contacts ||--o{ law_contact_tag_assignments : tagged
    law_contact_tags ||--o{ law_contact_tag_assignments : reused
    law_contacts ||--o{ law_contact_activity : accessed
    companies ||--o{ law_contact_sharing_policies : source
    companies ||--o{ law_contact_sharing_policies : recipient
```

## Tabelas

### `law_contacts`

Cadastro principal existente, ampliado com:

| Campo | Regra |
| --- | --- |
| `legal_nature` | `pf` ou `pj`, padrão `pf`; indexado com empresa e status. |
| `sharing_excluded` | Booleano que retira individualmente o contato das regras externas. |
| `display_name`, `legal_name` | Nome principal e razão social/nome complementar; valores normalizados no servidor. |
| `status`, `deleted_at`, `merged_into_id` | Controlam ciclo de vida e preservação da mesclagem. |
| `law_unit_id` | Nulo para os novos registros de propriedade empresarial compartilhada. |

### `law_contact_addresses`

Até dois endereços completos por contato. Inclui tipo, CEP, logradouro,
número, complemento, bairro, município, UF, país e indicação de principal.
Tipos: residencial, comercial, correspondência e outro. Composto
`(company_id, law_contact_id)` referencia o cadastro de mesma empresa.

### `law_contact_channels`

Telefones e e-mails do contato e dos departamentos. `channel_type` aceita
`phone`/`email`; `law_contact_department_id` é nulo no escopo do contato.
`is_personal` marca dado sensível e `is_primary`/`sort_order` apoiam exibição.
No escopo do contato o limite é quatro telefones e dois e-mails; aplica-se o
mesmo limite independentemente a cada departamento.

### `law_contact_documents`

Até quatro documentos por contato. `document_number_encrypted` guarda o valor
com `Crypt`; `document_fingerprint` guarda HMAC do CPF/CNPJ normalizado com
unicidade por empresa para detecção de duplicados sem índice sobre o texto aberto. Tipo, rótulo e UF
emissora completam o registro. CPF/CNPJ são opcionais e validados quando
fornecidos. CPF pertence a PF; CNPJ e inscrição estadual pertencem a PJ.
Inscrição estadual usa o tipo `state_registration` e exige UF emissora.

### `law_contact_departments`

Departamentos que pertencem a um contato PJ: nome, status e autoria da criação
e alteração. Os canais próprios usam `law_contact_channels`. Cada departamento
conta como uma unidade extra da capacidade `contatos_cadastrados`.

### Classificação e tags

- `law_contact_classifications` associa códigos do vocabulário controlado por
  empresa/contato. A PK impede a repetição de uma classificação.
- `law_contact_tags` contém nome e nome normalizado únicos por empresa.
- `law_contact_tag_assignments` associa até seis tags a cada contato sem
  duplicar associações.

Vocabulário de classificações: `client`, `lawyer`, `law_firm`, `public_body`,
`court_unit`, `police`, `prosecutor_office`, `public_defender`, `expert`,
`witness`, `representative` e `other`.

### `law_contact_activity`

Registra empresa, contato, usuário, ação e horário para os itens recentes do
dashboard pessoal. Tipos incluem criação, edição, abertura normal e abertura
de resultado de busca. O termo digitado na busca não é persistido.

### `law_contact_sharing_policies`

Regra de saída entre `source_company_id` e `recipient_company_id`, única por
par de empresas. O compartilhamento exige políticas ativas nos dois sentidos:
cada empresa configura e pode revogar o próprio lado do acordo. `legal_natures`
define PF/PJ e `profession_names` limita as pessoas físicas às profissões
selecionadas e vinculadas a contatos da origem. `shared_fields` define os
campos adicionais expostos. `is_active` permite revogação sem apagar auditoria.
O compartilhamento fica desativado por padrão e só vale enquanto ambas as
assinaturas incluírem o componente Contatos.

Campos compartilháveis: `professional_channels`, `business_addresses` e
`documents`. A API remove canais pessoais, endereços residenciais, notas,
tags e metadados internos; documentos também dependem de permissão sensível no
destino. A referência compartilhada é somente leitura, não é copiada à empresa
destinatária e não consome sua capacidade.

## Capacidade e índices

Consumo = contatos sem exclusão lógica/mesclagem + departamentos desses
contatos. Status inativo não reduz o consumo. A contagem é feita no servidor
durante a transação de criação/edição e validada contra o snapshot comercial da
assinatura. Índices mantêm busca por empresa/natureza/status, escopo de canais,
documento fingerprint, tags normalizadas, classificações e atividade recente.

## Segurança e integridade

- Tabelas filhas carregam `company_id` e FKs compostas ao contato para impedir
  referência cruzada entre empresas.
- APIs sempre escopam por empresa ativa e aplicam assinatura e permissões.
- Dados sensíveis são omitidos ou mascarados sem `law.contacts.sensitive.view`;
  alterações comuns preservam os valores sensíveis não exibidos.
- Mesclagem transfere registros filhos dentro de transação, combina
  classificações/tags sem duplicatas, registra motivo e marca origem como
  mesclada.
- Todas as ações administrativas e alterações relevantes geram auditoria.

## Compatibilidade e rollout

A migração converte tipos legados de organização para PJ, cria classificações
iniciais a partir de tipos antigos e limpa o escopo por unidade para que a
propriedade passe a ser da empresa. Contatos existentes passam a consumir a
capacidade conforme a nova contagem. O desmonte da migração remove as tabelas e
colunas novas sem alterar dados das tabelas originais.

## Módulos consumidores futuros

Processos, Expedições e Tarefas devem referenciar o contato por ID e aplicar as
permissões e o sigilo pertinentes ao contexto. O papel processual e o de
expedição ficam em relações próprias; snapshots preservam o destino utilizado
na emissão. Essas relações não são criadas por esta entrega.

## Evolução do vínculo pessoa–empresa e dados institucionais

`law_contact_relationship_roles` guarda vários papéis padronizados por vínculo,
com complemento para Outro e início/término próprios. A vigência é calculada
pelas datas e não concede permissões. `law_contact_relationship_designations`
guarda cargos, postos, graduações ou funções em períodos independentes; uma
relação pode ter várias designações vigentes. Os nomes livres ficam disponíveis
como sugestões reutilizáveis pela empresa. Relações antigas continuam válidas
sem inventar papéis ou designações.

`law_contact_institutional_data` guarda bloco opcional por classificação. Para
unidade judiciária, armazena código CNJ e competências; para órgão público,
esfera Federal/Estadual/Distrital/Municipal e código oficial com sistema emissor.
A sigla do contato segue representando tribunal/região. OAB continua em
`law_contact_documents`, sem campo duplicado.

## Qualidade e sugestões de duplicidade

Indicadores consideram apenas contatos próprios ativos. Correspondências por
e-mail/telefone profissional ou código institucional podem ser sugeridas;
similaridade de nome apenas reforça um candidato já corroborado. A resposta
expõe motivo, nunca o valor coincidente. A análise é sob demanda e paginada;
não bloqueia gravação nem mescla automaticamente. CPF/CNPJ permanece sujeito à
validação rígida existente.