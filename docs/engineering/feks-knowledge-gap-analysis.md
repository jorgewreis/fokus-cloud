# FEKS: análise de lacunas de conhecimento

Data: 08/11/2026 (UTC). Base: [inventário técnico](feks-technical-inventory.md).
Tarefa: [REI-15](https://linear.app/reis-developer/issue/REI-15).

## 1. Conclusão executiva

Há uma base concreta de código, testes, CI, runbooks e documentação. A maior lacuna
é a ligação verificável entre o que se declara, o que está implementado, o que os testes
cobrem e o que foi comprovado no ambiente efetivo.

Priorizar autorização/multiempresa e integridade antes de ampliar funcionalidades ou
adicionar ferramentas. Não há vulnerabilidade explorável demonstrada por este diagnóstico.
Os itens abaixo distinguem divergências confirmadas de perguntas que ainda precisam de prova.

### Classificação

- **P1:** revisar antes de expandir acesso, dados sensíveis, operação financeira ou integração.
- **P2:** reduzir risco de regressão e divergência de conhecimento no próximo ciclo.
- **P3:** melhorar descoberta e manutenção após os contratos essenciais.
- **Confirmada:** divergência ou ausência localizada nas fontes lidas.
- **Não comprovada:** falta de evidência suficiente nesta etapa, sem afirmar inexistência.

## 2. Lacunas priorizadas

| ID | Prioridade / tipo | Evidência e lacuna | Impacto / próxima validação |
| --- | --- | --- | --- |
| G01 | P1, não comprovada | Há testes de isolamento/permissão, mas não uma matriz rastreável de todos os endpoints e objetos Law/Cloud/Lead. | A revisão pode deixar lacunas entre empresa, unidade, objeto e assinatura. Mapear rotas a controles e testes negativos existentes; só propor testes novos após identificar ausência real. |
| G02 | P1, não comprovada | Billing, Datajud e DJEN têm testes com HTTP simulado; o workflow de homologação declara limites para provedores e sinks externos. | Um CI verde não comprova transação, reconciliação, disponibilidade externa ou não vazamento em logs remotos. Separar contratos locais de ensaios externos e suas autorizações. |
| G03 | P1, confirmada / não comprovada | Fronteiras descrevem quatro repos independentes; Law está no Cloud e Lead tem shell no Cloud mais PR #25 draft. Repos canônicos Law/Lead não foram resolvidos no acesso atual. | Risco de aplicar mudança no componente errado ou duplicar serviços comuns. Reconciliar mapa atual versus alvo, sem extração de código nesta etapa. |
| G04 | P1, não comprovada | Backup agendado teve success observado; runbook exige recuperação operacional, evidências de credenciais e sinks remotos. | Não confundir restore de backup recém-gerado com recuperação de artefato retido, RPO/RTO e corte efetivo de segredos. Vincular evidência existente antes de pedir novo exercício. |
| G05 | P2, confirmada | Styles declara Node >=18, mas toolchain travada requer versões posteriores; versões instaladas da aplicação não foram lidas. | Reprodução local pode divergir do CI. Separar mínimo de consumo, desenvolvimento e runtime; documentar versão suportada somente com evidência. |
| G06 | P2, confirmada | Contrato exige fokus-project.json e validação CI por repo; Styles não possui o arquivo e validação explícita não foi localizada nos workflows lidos. | Inventário automatizado pode sugerir garantia inexistente. Definir primeiro schema e critério de aceite, depois pedir aprovação para implementação. |
| G07 | P2, confirmada | Documentos gerais de teste/ambiente têm listas “A complementar” mesmo havendo testes/workflows específicos. | Informações reais ficam difíceis de descobrir. Propor índice de evidências e atualização direcionada, sem replicar estado do Linear. |
| G08 | P2, não comprovada | Suites SQLite e MySQL/Redis existem, mas cobertura integral de concorrência, reexecução, falha parcial e compatibilidade MariaDB não foi estabelecida. | Idempotência, unicidade, transações e sigilo podem variar por caminho/engine. Inspecionar constraints, transações e testes por operação crítica. |
| G09 | P2, não comprovada | Há lockfiles e correção recente de dependências; não foram encontrados jobs dedicados de SAST/SCA/secret scan nos workflows lidos. | Atualização pontual não prova vigilância contínua. Inventariar capacidades já habilitadas no GitHub e fontes de advisories sem instalar scanner. |
| G10 | P3, confirmada / não comprovada | Cloud tem perfis de agentes, mas não há SKILL.md nas árvores Cloud/Styles; guia externo v0.0.6 não verificado. | Confundir catálogo declarado com capacidade executável. Registrar finalidade, fonte, versão e validação mínima de cada skill realmente necessária, sem instalar ou copiar guia privado. |

## 3. Dossiês e critérios de aceite propostos

### G01: autorização, isolamento e sigilo

**Evidência:** [rotas](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/routes/api.php),
[contexto de empresa](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/app/Http/Middleware/EnsureCompanyContext.php),
[serviço Law](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/app/Services/LawAuthorizationService.php),
[testes de autenticação](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/AuthenticationAndIsolationTest.php),
[permissões](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/LawPermissionsTest.php) e
[contatos](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/LawContactsTest.php).

Há controles e testes negativos reais. A lacuna é a completude/rastreabilidade, não a
alegação de que a aplicação está sem proteção. A árvore não contém `app/Policies`;
reconciliar a linguagem das diretrizes com o mecanismo customizado existente.

**Aceite para revisão somente leitura da etapa 2:**
- Uma linha por família de rota: ator/guard, empresa, unidade, recurso, ação,
  entitlement, middleware/serviço, auditoria e teste correspondente.
- Cobrir identificador de outra empresa/unidade, vínculo removido, módulo retirado,
  acesso direto à API, processo sigiloso, entidades relacionadas, suporte e endpoints agregados.
- Classificar cada combinação como testada, não localizada ou não aplicável.
- Não declarar exploração nem corrigir antes de obter reprodução sintética autorizada.

### G02 e G08: integridade e contratos externos

**Evidência:** [BillingSandboxTest](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/BillingSandboxTest.php),
[Datajud](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Unit/LawDatajudClientTest.php),
[DJEN](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Unit/LawDjenClientTest.php) e
[ProductionSecurityHardeningTest](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/ProductionSecurityHardeningTest.php).

Casos já presentes incluem assinatura inválida, webhook repetido, checkout órfão,
paginação incompleta, processo divergente e preservação de estado após falha.
Não repetir esses trabalhos como se estivessem ausentes.

**Aceite proposto:**
- Relacionar invariantes a constraints, limites transacionais, jobs e testes.
- Distinguir execução única, repetição, concorrência, timeout e falha entre etapas.
- Verificar que dados operacionais não sejam sobrescritos por resposta externa parcial.
- Separar matriz de SQLite, MySQL, MariaDB e PostgreSQL/Lead, sem inferir equivalência.
- Usar somente testes sintéticos locais já disponíveis na fase aprovada; nenhuma
  chamada de cobrança, consulta de processo real ou gravação externa sem escopo próprio.

### G03: fronteiras e Lead

**Evidência:** [fronteiras](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/03-architecture/repository-boundaries.md),
[README](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/README.md),
[Lead shell](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/app/Http/Controllers/Api/LeadShellController.php) e
[PR #25](https://github.com/jorgewreis/fokus-cloud/pull/25).

Em 08/11/2026 o PR #25 estava aberto em draft, head
`198f3fe20104d9261d942c93fa22099d27da3c93`.
Sua descrição registra proposta PostgreSQL/Supabase, validações históricas e pendências;
elas não foram reproduzidas pelo FEKS. O estado oficial de reconciliação está em
[REI-14](https://linear.app/reis-developer/issue/REI-14).

**Aceite proposto:** mapa atual/alvo com proprietário lógico, repositório realmente
acessível, contrato de identidade/empresa/assinatura/uso, dados e pipeline.
Não extrair Law do Cloud nem integrar PR #25 como consequência desta análise.
Informações de fontes privadas permanecem restritas; a publicação pública deve usar
somente evidências públicas ou conteúdo especificamente aprovado.

### G04: evidências operacionais

**Evidência:** [runbook](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/production-security-runbook.md),
[homologação](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/security-homologation.yml) e
[backup bem-sucedido](https://github.com/jorgewreis/fokus-cloud/actions/runs/42470621270).

**Aceite proposto:** conferir se já existem registros de recuperação a partir de artefato
retido, RPO/RTO medidos, responsável, data, sink remoto e revogação no provedor.
Quando não localizados, marcar “evidência não localizada”, sem concluir que a operação falhou.
Ler arquivos de workflow não autoriza acioná-los. Rotação, restore, produção e credenciais
precisam de aprovação específica. Não ampliar REI-9 nem repetir trabalho de outro responsável.

### G05 e G06: versões e manifests

**Evidência:** [package Styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package.json),
[lock Styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package-lock.json),
[CI Styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/.github/workflows/ci.yml),
[manifest Cloud](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/fokus-project.json) e
[fronteiras](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/03-architecture/repository-boundaries.md).

**Aceite proposto:**
- Manter colunas distintas: mínimo declarado, resolução lock, CI configurado,
  runtime observado e data/commit da prova.
- Não alterar engines ou atualizar dependências só para alinhar números.
- Definir dono, schema e uso dos manifests antes de propor arquivo novo ou check.
- Comparar pacote Styles consumido com release/artefato publicado sem presumir que
  compartilhar o número 2.7.0 torna todo commit da main idêntico ao pacote npm.
- A versão instalada do servidor permanece não verificada até uma leitura autorizada
  no ambiente correto ou evidência sanitizada já existente.

### G07, G09 e G10: conhecimento de engenharia e segurança

**Evidência:** [qualidade](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/testing-and-quality.md),
[ambientes](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/environments-and-deploy.md),
[modelo de ameaças](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/07-security/threat-model.md),
[AGENTS](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/AGENTS.md) e
[procedimento de execução](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/julia-night-operations.md).

**Aceite proposto:**
- Indexar evidência existente em vez de manter três backlogs.
- Vincular ameaça → controle → teste → última execução; documentar limites da prova.
- Para dependências, consultar advisories oficiais e capacidades existentes antes de
  propor scanner; uma ferramenta não instalada não é automaticamente uma lacuna do produto.
- Para skills/agentes, separar “declarado”, “acessível”, “lido” e “validado em tarefa”.
  Nenhuma promessa de operação autônoma decorre da existência de um perfil TOML.
- Nenhuma consulta adicional ao Notion foi realizada nesta etapa. A versão 0.0.6
  do guia externo permanece não verificada; não afirmar sincronização com GitHub.
- Documentação oficial acessível nesta etapa: Laravel 13, PHP, OWASP multi-tenant,
  GitHub Actions e manifesto upstream Laravel v13.24.0. Ter acesso não substitui
  validação técnica na stack efetiva.

## 4. Etapa 2 recomendada para aprovação separada

**Objetivo:** produzir matriz de autorização/isolamento e reconciliar o mapa
arquitetural atual/alvo usando o código e testes existentes.

**Justificativa:** são as áreas com maior impacto potencial em dados, acesso e decisões
de implementação; a base de testes já existe e pode ser aproveitada.

**Solução proposta:** leitura dirigida de rotas, middleware/serviços, queries,
constraints e testes, com relatório requisito/controle/evidência e lacunas reproduzíveis.
Consultar o andamento de REI-14 e trabalhos concorrentes antes de iniciar.

**Impacto:** melhora decisões e evita duplicar componentes; não muda comportamento.
Não inclui automaticamente correção de código, atualização de docs adicionais,
instalação de ferramentas, dados reais ou chamadas externas.

**Riscos:** confundir ausência de evidência com vulnerabilidade; trabalhar sobre SHA
desatualizado; publicar informação privada. Mitigar com snapshot, classificação de
evidência e revisão do destino de publicação.

**Validação:** matriz com fontes exatas e testes existentes por linha; separar pass,
fail e não executado; declarar limitações. Qualquer reprodução ou alteração necessária
deve receber escopo próprio com objetivo, solução, impacto, riscos e critérios de teste.

A etapa 1 termina com os dois documentos e PR para revisão. Nenhuma recomendação aqui
é autorização para merge, deploy, mudança de dependência, banco, código ou permissões.

## 5. Checkpoint reproduzível da etapa 1

- Snapshot Cloud: `53afd105641bae66375ccb24ba1bd2e092cefec1`.
- Snapshot Styles: `f052b05bea070f7175a759951657d73e73f9c63b`.
- Branch documental: `docs/rei-15-feks-inventory-20261108`.
- Leitura realizada: árvores não truncadas, manifests/locks, oito workflows Cloud,
  CI/Release Styles, rotas, testes selecionados e fontes oficiais.
- Comandos locais: versões de Git/Node/npm/Python; presença de PHP/Composer e diretórios
  de dependências. PHP/Composer indisponíveis, sem tentativa de instalação.
- Validação dos documentos: checagem de UTF-8, whitespace, estrutura, links relativos
  e caminhos de permalinks contra as árvores fixadas; diff remoto deve ter só os dois docs.
- Não executado localmente: PHPUnit, Pint, build, navegador, SAST/SCA, migrations,
  testes de carga/concorrência ou validação de produção.
- Evidências de CI anteriores estão no inventário. Checks deste PR devem ser consultados
  no próprio head e registrados em REI-15; não herdam aprovação do commit-base.
- Estado final e URL do PR ficam no Linear para evitar duplicação de status neste snapshot.
