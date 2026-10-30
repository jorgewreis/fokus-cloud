# Modelo de dados da Gestão de Contatos Law

## Propriedade e relações

A empresa (`company_id`) é dona dos cadastros. `law_unit_id` legado é mantido
por compatibilidade, mas novos registros usam `NULL`: setores internos
autorizados veem a base da empresa. `record_kind` diferencia contato e unidade;
`legal_nature` diferencia PF e PJ e é nulo para unidade. `parent_contact_id`
forma uma hierarquia na mesma empresa, com um pai por filho e vários filhos por
pai. Contexto e tags ficam em estruturas separadas.

```mermaid
erDiagram
    companies ||--o{ law_contacts : owns
    law_contacts ||--o{ law_contacts : parent_of
    law_contacts ||--o{ law_contact_addresses : has
    law_contacts ||--o{ law_contact_channels : has
    law_contacts ||--o{ law_contact_documents : identifies
    law_contacts ||--o{ law_contact_tag_assignments : tagged
    law_contact_tags ||--o{ law_contact_tag_assignments : reused
    law_contacts ||--o{ law_contact_activity : accessed
    companies ||--o{ law_contact_sharing_policies : source
    companies ||--o{ law_contact_sharing_policies : recipient
    companies ||--o| law_contact_company_settings : configures
```

## Tabelas

### `law_contacts`

Cadastro principal existente, ampliado com:

| Campo | Regra |
| --- | --- |
| `legal_nature` | `pf` ou `pj`, padrão `pf`; indexado com empresa e status. |
| `record_kind` | `contact` para pessoa PF/PJ ou organização PJ; `unit` para unidade autônoma, cuja `legal_nature` é nula. |
| `parent_contact_id` | Pai imediato opcional com FK composta por empresa; PF não pode ser pai nem filho. |
| `sharing_excluded` | Booleano que retira individualmente o contato das regras externas. |
| `display_name`, `legal_name` | Nome principal e razão social/nome complementar; valores normalizados no servidor. |
| `status`, `deleted_at`, `merged_into_id` | Controlam ciclo de vida. Exclusão lógica usa `status=excluido` e `deleted_at`; mesclagem preserva o destino. |
| `law_unit_id` | Nulo para os novos registros de propriedade empresarial compartilhada. |

### `law_contact_addresses`

Até dois endereços completos por contato. Inclui tipo, CEP, logradouro,
número, complemento, bairro, município, UF, país e indicação de principal.
Tipos: residencial, comercial, correspondência e outro. Composto
`(company_id, law_contact_id)` referencia o cadastro de mesma empresa.

### `law_contact_channels`

Telefones e e-mails do contato ou unidade. `channel_type` aceita `phone`/`email`;
`label` descreve o canal e `is_personal` marca separadamente o dado sensível.
`is_primary`/`sort_order` apoiam exibição. O limite por registro é quatro
telefones e dois e-mails. Canais pessoais são protegidos por permissão e não
são compartilhados.

### `law_contact_documents`

Até quatro documentos por contato. `document_number_encrypted` guarda o valor
com `Crypt`; `document_fingerprint` guarda HMAC do CPF/CNPJ normalizado com
unicidade por empresa para detecção de duplicados sem índice sobre o texto aberto. Tipo, rótulo e UF
emissora completam o registro. CPF/CNPJ são opcionais e validados quando
fornecidos. CPF pertence a PF; CNPJ e inscrição estadual pertencem a PJ.
Inscrição estadual usa o tipo `state_registration` e exige UF emissora.

### `law_contact_departments`

Tabela legada preservada para reversibilidade da migração. Cada departamento
existente é promovido a um registro `law_contacts` do tipo `unit`; o campo
`migrated_contact_id` aponta para esse registro e mantém a correspondência com a
linha histórica. Novos registros hierárquicos não são gravados como departamento.

### Tags

- `law_contact_tags` contém nome e nome normalizado únicos por empresa.
- `law_contact_tag_assignments` associa até seis tags a cada contato sem duplicar associações.

Papéis de vínculo são mantidos na relação PF–organização. Unidade judiciária é um tipo institucional, com dados e código CNJ próprios.

### `law_contact_company_settings`

Uma linha por empresa armazena `segment_code` e `context_code` ativos e o último
administrador que alterou a configuração. A troca de contexto muda rótulos e
sugestões da interface, mas não converte, apaga ou recria contatos e vínculos.

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

Consumo = registros sem exclusão lógica/mesclagem, incluindo unidades. A
exclusão lógica reduz o consumo. A contagem é feita no servidor
durante a transação de criação/edição e validada contra o snapshot comercial da
assinatura. Índices mantêm busca por empresa/natureza/status, escopo de canais,
documento fingerprint, tags normalizadas e atividade recente.

## Segurança e integridade

- Tabelas filhas carregam `company_id` e FKs compostas ao contato para impedir
  referência cruzada entre empresas.
- APIs sempre escopam por empresa ativa e aplicam assinatura e permissões.
- Dados sensíveis são omitidos ou mascarados sem `law.contacts.sensitive.view`;
  alterações comuns preservam os valores sensíveis não exibidos.
- Mesclagem transfere registros filhos dentro de transação, combina
  tags sem duplicatas, registra motivo e marca origem como
  mesclada.
- Todas as ações administrativas e alterações relevantes geram auditoria.

## Compatibilidade e rollout

A migração `2026_10_01_000100_add_context_and_hierarchy_to_law_contacts.php`
promove departamentos existentes a unidades independentes e preserva as linhas
legadas com `migrated_contact_id`. Os canais acompanham a unidade promovida.
Também cria configuração contextual por empresa. Na reversão,
unidades existentes são mantidas como organizações PJ para não apagar dados
criados após a migração.

## Evolução do vínculo pessoa–empresa e dados institucionais

`law_contact_relationship_roles` guarda vários papéis padronizados por vínculo,
com complemento para Outro e início/término próprios. A vigência é calculada
pelas datas e não concede permissões. `law_contact_relationship_designations`
guarda cargos, postos, graduações ou funções em períodos independentes; uma
relação pode ter várias designações vigentes. Os nomes livres ficam disponíveis
como sugestões reutilizáveis pela empresa. Relações antigas continuam válidas
sem inventar papéis ou designações.

`law_contact_institutional_data` guarda blocos opcionais; `is_primary` marca o
tipo institucional principal e permite tipos secundários. Para unidade
judiciária, armazena código CNJ e competências; para órgão público, esfera
Federal/Estadual/Distrital/Municipal e código oficial com sistema emissor.
Identificadores são opcionais e sua ausência gera aviso de completude, sem
bloquear o cadastro.
A sigla do contato segue representando tribunal/região. OAB continua em
`law_contact_documents`, sem campo duplicado.

## Qualidade e sugestões de duplicidade

Indicadores consideram apenas contatos próprios ativos. Correspondências por
e-mail/telefone profissional ou código institucional podem ser sugeridas;
similaridade de nome apenas reforça um candidato já corroborado. A resposta
expõe motivo, nunca o valor coincidente. A análise é sob demanda e paginada;
não bloqueia gravação nem mescla automaticamente. CPF/CNPJ permanece sujeito à
validação rígida existente.


## Complemento protegido do contato (2026-10-30)

- `law_contacts.sensitive_details_encrypted`: JSON criptografado pelo `Crypt` da aplicação; não usar em pesquisa, exportação, logs ou compartilhamento entre empresas. A chave da aplicação deve permanecer no gerenciamento seguro de segredos, com backup e rotação controlada.
- API `sensitive_details`: nascimento (`birth_date`, ISO, não futuro), filiação (`parentage`, até 4), contas (`bank_accounts`, até 5) e chaves Pix (`pix_keys`, até 10). Nascimento/filiação são exclusivos PF; unidades não recebem este bloco.
- Somente usuários com permissão existente `law.contacts.sensitive.view` podem ler no detalhe ou editar o bloco, além da permissão normal de edição. Não é enviado em listagens nem contatos compartilhados, mesmo ao leitor com permissão sensível. Banco/Pix ficam mascarados inicialmente na interface de consulta; exibição explícita pelo usuário autorizado.
- Omitir `sensitive_details` no PATCH preserva o conteúdo. Enviá-lo substitui o bloco; arrays vazios/data nula limpam os campos. Uma alteração PF→PJ com nascimento/filiação existentes é rejeitada: revisar e remover explicitamente antes de mudar a natureza. Mesclagem com bloco protegido preenchido é bloqueada para impedir perda silenciosa; a consolidação assistida fica pendente.
- Documentos: até 20; PIS, PASEP, NIT, NIS, CadÚnico (código familiar), CNH e CIN são tipos distintos PF, criptografados como os documentos existentes. O código familiar do CadÚnico não é tratado como NIS. Não foi implementada consulta/certificação desses cadastros externos.
- Canais: até 10 e-mails e 4 telefones (14 no total). Limites comerciais de quantidade de contatos da empresa permanecem inalterados.
- Nenhuma senha Gov.br, senha bancária ou credencial foi adicionada; chaves inesperadas no bloco são rejeitadas. Relações com processos/audiências seguem no modelo já existente, sem cadastro duplicado.

### Validação antes da liberação

Executar `php artisan test --filter=LawContactsTest`, `node --check public/portal/assets/fokus-law-contacts.js` e `node tests/Frontend/law-contact-sensitive-details.cjs`. Homologar visualmente PF/PJ/unidade, usuário restrito e compartilhamento. Migração deve preceder o código em qualquer futura liberação; rollback da coluna remove os dados do bloco, portanto exige backup e decisão explícita. Este PR não executa migração nem deploy.
