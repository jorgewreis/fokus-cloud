# Fokus Lead — estrutura inicial PostgreSQL

Data das decisões: **15/10/2026**. Produto: `lead`. Linhas comerciais: `one` e `team`.

## Estado verificável

Este diretório contém a especificação aprovada na conversa e o **baseline SQL
aplicado ao projeto exclusivo Fokus Lead no Supabase em 15/10/2026**. O projeto
está saudável em São Paulo, PostgreSQL 17.11.0.003. Não é uma release funcional
da aplicação: backend, telas e integrações continuam pendentes.

Os scripts não participam de `php artisan migrate`, não mudam a conexão padrão
do Cloud e não consultam ou alteram o Law. Permanecem na documentação do Cloud
enquanto o código operacional do produto deve ter seu repositório próprio,
conforme [as fronteiras atuais](../../03-architecture/repository-boundaries.md).
O repositório antigo do Lead foi excluído deste trabalho por instrução expressa.

| Entrega | Situação |
| --- | --- |
| Vinte decisões de produto | Confirmadas pelo responsável nesta conversa |
| Modelo físico inicial | 38 tabelas; 10 migrations SQL aplicadas |
| Controle de acesso no modelo | RLS, fronteira por empresa e escopos operacionais |
| Referências entre empresas | Impedidas por chaves estrangeiras compostas |
| Projeções públicas e administrativas | Definidas sem expor tabelas operacionais |
| Verificação isolada | Executada; ver [validação](validation.md) |
| Projeto Supabase exclusivo do Lead | Criado como Fokus Lead na organização Jorge Reis Dev, região `sa-east-1`; saudável |
| Backend, interfaces e integrações | Ainda não implementados por estes arquivos |
| Servidor próprio de arquivos | Escolhido; endpoint e infraestrutura ainda não informados |
| Migração/separação física do Law | Fora desta entrega; ainda não executada |

Projeto: `yukmvgkojmpzmsynlyxn`. As 38 tabelas têm RLS habilitada e forçada;
142 políticas e três views de projeção foram verificadas. O verificador de
segurança retornou zero alertas. A revisão de desempenho identificou seis FKs
compostas sem cobertura, corrigidas na décima migration. Permanecem apenas avisos
informativos de índices ainda sem uso, esperados no banco novo e vazio.

## Decisões confirmadas

| Nº | Decisão | Consequência para a estrutura |
| --- | --- | --- |
| 1 | Vendas e locações até o fechamento | Sem administração de aluguéis, cobranças ou repasses |
| 2 | Autônomos e imobiliárias/equipes | Empresa isolada por operação; One e Team usam o mesmo domínio |
| 3 | Corretor vê seus atendimentos; gestor vê todos da empresa | Responsável explícito no atendimento; política também em relatórios, buscas e exportações |
| 4 | Imóvel editável pelo responsável e gestores | Responsável pela captação separado do responsável pelo atendimento |
| 5 | Contatos do proprietário restritos ao responsável e gestores | Proprietários, canais e endereço exato separados do cadastro comercial compartilhado |
| 6 | Todos os corretores consultam a carteira disponível da própria empresa | Consulta compartilhada não concede edição ou acesso aos proprietários |
| 7 | Venda e locação simultâneas | Um imóvel; ofertas comerciais separadas por finalidade |
| 8 | Imóveis individuais e empreendimentos com unidades | Empreendimento agrupa imóveis/unidades; preço e disponibilidade pertencem à oferta da unidade |
| 9 | Etapas intermediárias configuráveis por empresa | Funis e etapas próprios; resultados finais padronizados |
| 10 | Distribuição manual e rodízio opcional | Regras, candidatos e histórico de atribuição |
| 11 | Um atendimento reúne imóveis da mesma busca | Relação atendimento–ofertas; visitas e propostas vinculadas à opção específica |
| 12 | Catálogo público opcional por empresa | Publicação explícita de ofertas e recepção de interessados na empresa do catálogo |
| 13 | Fechamento na assinatura do contrato | Data da assinatura obrigatória no registro do negócio; aceite não equivale a fechamento |
| 14 | Comissão e divisão entre participantes | Registro comercial e rateio, sem execução financeira |
| 15 | Agenda interna, tarefas, visitas, retornos e lembretes | Entidades operacionais vinculadas ao atendimento e, quando aplicável, ao imóvel |
| 16 | Gerar propostas e anexar contratos/documentos | Versões imutáveis de propostas e referências a arquivos privados |
| 17 | Aceite cria reserva temporária e bloqueia aceite incompatível | Reserva com prazo, unicidade de compromisso ativo e transação de aceite a implementar |
| 18 | Admin global com dados administrativos e suporte temporário autorizado | Papel de métricas separado do runtime; suporte depende de autorização vigente no Cloud e auditoria |
| 19 | Arquivos em servidor próprio | Banco guarda metadados e chaves opacas; conteúdo permanece fora do PostgreSQL |
| 20 | Novo projeto Supabase exclusivo do Lead | Nenhuma reutilização do projeto do Cloud ou do banco do Law |

## Fronteira de identidade e dados

O Cloud continua sendo a fonte de verdade de identidade global, empresas,
vínculos, perfil básico, assinatura, catálogo comercial, limites e autorização
de entrada no produto. O Lead não cria senhas, sessões próprias ou Supabase Auth.

`tenant_refs` e `actor_refs` guardam somente os identificadores externos que
permitem integridade e histórico locais. Não são novos cadastros de empresas ou
usuários e não concedem acesso. A existência de uma referência local **não prova
vínculo ativo**. O backend precisa revalidar o vínculo, a assinatura e a permissão
no Cloud antes de cada operação protegida, incluindo jobs e downloads.

Os IDs atuais de empresas e usuários do Cloud são `char(30)`. Por isso, o modelo
mantém referências `varchar(30)`; não presume UUIDs para dados já existentes.
As entidades imobiliárias novas usam UUIDs e chaves compostas `(company_id,id)`.

Law e Lead não possuem conexões, credenciais ou consultas entre si. A integração
de cada produto é com o Cloud, por contratos administrativos e de identidade.
Não se cria FDW, replicação de contatos ou transação distribuída entre produtos.
Operações entre o Cloud e o produto deverão usar eventos idempotentes/outbox e
reconciliação, sem presumir atomicidade entre os bancos.

## Organização do banco

| Schema | Uso | Acesso previsto |
| --- | --- | --- |
| `lead` | Imóveis, ofertas, atendimentos, agenda, propostas e operação comercial | Backend, com empresa ativa e escopo operacional |
| `lead_private` | Contatos, localização exata, proprietários, documentos, auditoria e eventos internos | Backend, com autorização específica |
| `lead_public` | Views com campos comerciais explicitamente publicáveis | Leitura por serviço do catálogo público |
| `lead_admin` | View de métricas por empresa | Leitura administrativa do Cloud |

Schemas privados não devem constar em `Exposed schemas` da Data API. Como o
acesso será pelo Laravel, a Data API poderá ser desativada no projeto do Lead.
A criação de um projeto não configura automaticamente essa opção.

Os conteúdos públicos de cada catálogo são uma publicação intencional da empresa.
Isso não compartilha cadastros, contatos, documentos ou atendimentos entre
empresas. Os formulários públicos devem resolver o catálogo no servidor e
derivar sua empresa; nunca confiar em um `company_id` enviado pelo visitante.

## Dicionário resumido

As colunas completas, tipos, checks, índices e FKs constam em `sql/`.

| Grupo | Tabelas | Relações e finalidade |
| --- | --- | --- |
| Referências e preferências | `tenant_refs`, `actor_refs`, `tenant_settings` | Referências ao Cloud; timezone, moeda e preferências operacionais |
| Pessoas e canais privados | `contacts`, `contact_channels` | Pessoas físicas/jurídicas; telefone/e-mail protegidos; papéis decorrem das relações com o domínio |
| Carteira | `developments`, `properties` | Empreendimento → unidades/imóveis; características e responsável |
| Dados restritos do imóvel | `property_locations`, `property_owners` | Endereço exato e múltiplos proprietários/representantes |
| Ofertas | `offers`, `offer_versions` | Venda/locação com valores e estados próprios; histórico de condições |
| Funil | `funnels`, `funnel_stages`, `stage_history` | Etapas por empresa; histórico de movimentação |
| CRM | `sources`, `inquiries`, `inquiry_properties` | Pessoa → uma ou mais buscas; cada busca acompanha múltiplas ofertas |
| Distribuição | `assignment_rules`, `assignment_candidates`, `assignment_history` | Rodízio opcional, atribuição manual e reatribuições |
| Entrada pública | `intake_events` | Idempotência, origem, fila de processamento e consentimento de marketing separado |
| Agenda | `activities`, `tasks`, `visits`, `reminders` | Histórico do atendimento, compromissos, responsáveis e entrega de lembretes |
| Negociação | `proposals`, `proposal_versions`, `reservations` | Propostas por oferta, snapshots e reserva temporária |
| Fechamento | `deals` | Contrato assinado, valor final e eventual reversão auditada |
| Resultado comercial | `commissions`, `commission_shares` | Comissão por negócio, snapshot da regra e parcelas dos participantes |
| Arquivos | `file_objects`, `property_media`, `document_links` | Arquivos no servidor próprio; ligações explícitas a entidades da mesma empresa |
| Divulgação | `catalogs`, `publications` | Catálogo opcional e publicação por oferta |
| Governança | `audit_events`, `outbox_events` | Auditoria append-only e eventos idempotentes |

Valores monetários usam `numeric(18,2)` e não ponto flutuante. Instantes usam
`timestamptz`; o frontend converte para o timezone da empresa. Campos JSON não
substituem as relações principais e precisam de schema de validação na API.

## Matriz mínima de acesso

| Recurso | Corretor | Gestor da empresa | Admin do Cloud | Visitante |
| --- | --- | --- | --- | --- |
| Imóveis e ofertas internas | Consulta da própria empresa; edição dos próprios | Consulta/edição da própria empresa | Somente com suporte operacional autorizado | Apenas projeção publicada |
| Proprietários e canais | Somente imóveis próprios e contatos autorizados | Própria empresa | Somente com suporte autorizado | Sem acesso |
| Atendimentos e propostas | Sob sua responsabilidade | Todos da própria empresa | Somente com suporte autorizado | Sem acesso interno |
| Documentos privados | Conforme vínculo com a entidade autorizada | Própria empresa, conforme permissão | Somente com suporte autorizado | Sem acesso |
| Comissões | Proposta técnica: somente sua parcela | Totais e rateios da própria empresa | Somente com suporte autorizado | Sem acesso |
| Indicadores administrativos | Conforme interface autorizada | Própria empresa | Métricas administrativas das empresas | Sem acesso |

A limitação de cada corretor à própria parcela de comissão é uma decisão técnica
inicial do modelo, não uma resposta expressa do questionário. O acesso efetivo
continua dependendo das permissões cadastradas no Cloud e das Policies do Lead.
Ter perfil de gestor não substitui a permissão específica da ação.

## RLS e fronteira de confiança

O papel `lead_runtime` não tem login, BYPASSRLS, criação de objetos, DELETE ou
TRUNCATE. O proprietário das migrations e a credencial de runtime são distintos.
O papel precisa ser associado a um login restrito na implantação; esse login e
seus segredos não são criados nem versionados por este modelo.

As políticas usam `app.company_id`, `app.user_id` e `app.is_manager`, definidos
exclusivamente pelo backend confiável com `set_config(..., true)`/`SET LOCAL`
dentro de uma transação. Eles **não são credenciais nem tokens verificáveis**.
Quem obtiver a credencial direta do backend pode forjar esse contexto: RLS
reduz falhas de consultas; não substitui autenticação, Policies, proteção contra
SQL injection ou proteção de segredos. Nunca disponibilizar esse login, SQL
arbitrário ou o papel `lead_runtime` a usuários finais ou à Data API.

Requisições não podem aceitar perfil, usuário ou empresa diretamente do payload.
Pools de conexão não podem conservar contexto entre transações. Para cada
requisição, validar o contexto no Cloud antes de iniciar a transação do Lead.
Sem contexto, as tabelas retornam zero registros ao runtime. Indisponibilidade
da verificação de autorização deve resultar em recusa de acesso, sem permissões
em cache que contrariem suspensão imediata.

As views públicas e de métricas usam a leitura do proprietário das migrations,
com campos e filtros restritos. Essa conta precisa conseguir ler as bases sob
FORCE RLS; validar a propriedade e o atributo BYPASSRLS no ambiente Supabase.
Nenhuma conta de runtime pode se tornar proprietária dessas views ou das tabelas.

## Fluxos transacionais a implementar no backend

1. **Entrada e distribuição:** resolver catálogo/empresa no servidor; validar e
   limitar submissões; registrar evento idempotente; conferir candidatos ativos
   no Cloud; bloquear a regra do rodízio; atribuir um responsável; registrar
   histórico e auditoria. Sem candidato elegível, manter evento na fila do gestor,
   sem criar atendimento órfão e invisível.
2. **Propostas:** criar proposta e versão inicial na mesma transação; gerar o PDF
   da versão imutável; registrar novas versões e atualizar o ponteiro de versão;
   registrar envio, validade, recusa, retirada e contrapropostas. As colunas
   `terms` precisam de schema explícito; não receber condições arbitrárias sem
   validação. A referência à versão atual é uma FK diferida.
3. **Aceite e reserva:** bloquear o imóvel com `FOR UPDATE`; encerrar reservas
   vencidas; validar finalidade e versão; aceitar a proposta e criar a reserva
   na mesma transação; atualizar disponibilidade e auditoria. A unicidade protege
   contra duas reservas ativas, mas o SQL estrutural **não implementa o aceite**.
4. **Expiração:** job idempotente libera a reserva vencida e reconcilia a oferta.
   A abertura de nova reserva também verifica expiração sob o mesmo bloqueio;
   não depende apenas de cron. Não usar condição com `now()` em índice parcial.
5. **Fechamento:** exigir data de assinatura, proposta aceita/reserva válida e
   permissão; registrar negócio, anexos e snapshot da comissão; encerrar reserva;
   atualizar ofertas/publicações incompatíveis e histórico. Mudar uma etapa do
   funil para ganho não pode contornar a assinatura do contrato.
6. **Comissão:** validar soma exata das parcelas antes da aprovação, com
   arredondamento explícito e bloqueio do lançamento; preservar snapshots;
   cancelar/reverter com justificativa. O modelo não executa pagamentos nem
   calcula tributos. A soma entre linhas depende da Action transacional; ainda
   não há constraint diferida de rateio neste baseline.
7. **Transferência de responsabilidade:** somente ator autorizado; preservar
   histórico e restringir de imediato o acesso do antigo responsável aos
   atendimentos. Não apagar referências históricas de usuários desligados.
8. **Suporte:** autorização da empresa, finalidade, prazo e escopo no Cloud;
   revalidação por requisição e auditoria do ator administrativo real, empresa e
   sessão de suporte. A existência do campo `support_session_ref` não valida a
   sessão e não concede acesso por si só.

Esses fluxos exigem Services/Actions, Form Requests e Policies no produto. Checks
e FKs do modelo já rejeitam algumas inconsistências, mas não implementam a máquina
de estados completa nem garantem sozinhos as regras comerciais.

## Arquivos no servidor próprio

O banco não armazena fotos/PDFs em blobs. `storage_key` é uma chave opaca, não um
caminho enviado pelo cliente. Uploads deverão ser iniciados pelo backend, passar
por validação de tamanho, MIME e conteúdo, quarentena e aprovação antes do uso.
Downloads privados devem revalidar contexto e entidade, por proxy autenticado ou
URL assinada de curta duração emitida após autorização. A autorização de publicar
uma mídia não autoriza publicar os documentos do mesmo imóvel.

O servidor precisa de endpoint HTTPS disponível para a aplicação, isolamento
de credenciais e objetos do Lead, prevenção de path traversal e backup fora do
mesmo disco. Os detalhes do servidor ainda não foram fornecidos. O backup do
PostgreSQL não recupera o conteúdo dos arquivos; devem existir rotinas de backup
e teste de restauração do banco **e** do servidor de arquivos. Falhas de upload
exigem compensação e limpeza de objetos órfãos; uma transação SQL não abrange o
servidor de arquivos.

Campos `*_ciphertext` representam criptografia da aplicação ainda a implementar.
Não gravar texto puro nesses campos. Pesquisa exata/deduplicação deve usar HMAC
com segredo e escopo por empresa; telefone ou e-mail compartilhado por familiares
não autoriza fusão automática de pessoas. Chaves de criptografia, HMAC, banco e
arquivos devem ser distintas quando aplicável, protegidas e rotacionáveis.

## Decisões técnicas provisórias

Estas escolhas tornam o modelo revisável sem apresentá-las como respostas do
usuário: UUIDs para novas entidades; múltiplos proprietários; timezone inicial
`America/Bahia`; moeda BRL; reserva padrão de 48 horas configurável; um compromisso
ativo por imóvel, tratando venda e locação simultâneas como incompatíveis para
reserva. A publicação é por oferta e o formulário recebe dados apenas na empresa
do catálogo. Tratar combinações específicas de venda de imóvel já locado ou
reservas compatíveis exigirá evolução explícita da regra.

Tipos de imóveis/características, campos finais das propostas, prazos de retenção,
limites por plano e identidade visual dos documentos permanecem refinamentos.
Não se inventam preços ou limites: devem ser definidos e publicados pelo catálogo
do Cloud. Nenhum módulo é marcado como disponível ou comercialmente contratado
por estes scripts.

## Próximas entregas e critérios de aceite

| Entrega | Critérios mínimos |
| --- | --- |
| Projeto Supabase | Organização escolhida pelo usuário, custo confirmado, projeto exclusivo e região definida |
| Infraestrutura | Credenciais distintas de migration/runtime/catálogo/métricas; TLS verificado; schemas privados fora da API; teste com papel sem BYPASSRLS |
| Provisionamento | Referências consistentes com o Cloud, sem duplicar contas/vínculos; seeds idempotentes por empresa |
| Integração Laravel | Conexão `lead` explícita; sem fallback para banco do Cloud/Law; contexto transacional e testes de pool |
| Operação comercial | Actions de aceite/reserva/fechamento/rateio e testes reais de concorrência |
| Interfaces | Fokus Styles; carregamento, vazio, erro, sessão expirada, sem permissão e indisponibilidade do banco/arquivos |
| Catálogo e formulários | Apenas projeções públicas, limites de abuso e idempotência; nenhum dado privado em HTML, JSON ou assets |
| Suporte e observabilidade | Consentimento temporário no Cloud, trilha administrativa, métricas e logs sanitizados |
| Recuperação | Restore isolado do PostgreSQL e dos arquivos, referências reconciliadas e rollback documentado |

Rotas e módulos propostos para orientar a implementação:

| Módulo Lead | Rotas protegidas propostas | Permissões de domínio propostas |
| --- | --- | --- |
| Carteira | `/api/lead/properties`, `/developments`, `/offers` | `lead.properties.view`, `create`, `update`, `archive`; dados privados com permissão própria |
| CRM | `/api/lead/inquiries`, `/assignments`, `/funnels` | `lead.inquiries.view`, `create`, `update`, `assign`; configuração pelo gestor |
| Agenda | `/api/lead/tasks`, `/visits`, `/reminders` | `lead.agenda.view`, `manage` |
| Negociação | `/api/lead/proposals`, `/reservations`, `/deals` | `lead.proposals.manage`, `accept`; `lead.deals.close`, `reverse` |
| Comissões | `/api/lead/commissions` | `lead.commissions.view-own`, `view-all`, `manage`, `approve` |
| Arquivos | `/api/lead/files`, `/files/{id}/download` | `lead.files.upload`, `download`, `publish` e vínculo com a entidade |
| Divulgação | `/api/lead/catalog`, `/publications` | `lead.catalog.manage`, `publish` |

Os nomes são propostas técnicas, não rotas implementadas ou permissões já
cadastradas. O Cloud continua responsável por autenticação, empresa ativa e
perfis básicos. Nenhuma regra jurídica é criada neste modelo.

## Implantação do modelo

O baseline já possui histórico de migrations no projeto Supabase exclusivo do
Lead. Seu código operacional deverá preservar esse histórico no repositório
oficial do produto. Não apontar `php artisan migrate` do Cloud
para o Supabase: isso tentaria aplicar as tabelas do core e do Law no banco errado.
Não executar estes arquivos em um banco que já contenha schemas `lead`.

Para validar localmente, usar o procedimento em [validation.md](validation.md).
Uma validação transacional também foi executada no PostgreSQL nativo do Supabase,
conforme `validate-live.sql`, sem deixar fixtures ou associações temporárias de
papéis. Ainda é necessário testar com conexões concorrentes reais da aplicação.
PGlite confirma a execução SQL e políticas, mas
não reproduz rede, TLS, Supavisor, papéis administrados do Supabase ou concorrência
entre processos de aplicação.

Fontes primárias consultadas em 15/10/2026:

- [Supabase com Laravel](https://supabase.com/docs/guides/getting-started/quickstarts/laravel)
- [Segurança do banco Supabase](https://supabase.com/docs/guides/database/secure-data)
- [Segurança da Data API](https://supabase.com/docs/guides/api/securing-your-api)
- [PostgreSQL: políticas de segurança por linha](https://www.postgresql.org/docs/current/ddl-rowsecurity.html)

As versões, custos e opções de provisionamento devem ser conferidos novamente
na criação efetiva do projeto; nenhum preço é fixado neste documento.
