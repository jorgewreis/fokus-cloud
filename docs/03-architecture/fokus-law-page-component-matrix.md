# Matriz de componentes por página — Fokus Law

## Escopo e referência

Esta auditoria cobre somente o produto Fokus Law: páginas públicas de apresentação, preços e contratação; portal autenticado servido pelo shell Law; módulos dinâmicos de Contatos, Processos, Empresa, Assinatura, Perfil, Usuários e transferência. Fokus Lead está fora deste documento.

O portal não tem um repositório Fokus Law separado disponível neste workspace. Suas rotas são servidas pelo Fokus Cloud, por `resources/views/portal/fokus-law.blade.php`, e montadas pelos módulos em `public/portal/assets/`. As páginas públicas também estão neste checkout. O código de backend identifica rotas e consumidores, mas não prova como estados autenticados são renderizados.

Referência de API: Fokus Styles **2.7.0**, resolvido no `package-lock.json` e em `node_modules/fokus-styles`; o checkout fonte canônico `Fokus Styles 2.7.0` corresponde à tag `v2.7.0`. O checkout antigo `FokusCloud - Styles` declara 2.5.0 e não é usado para avaliar contratos 2.7.0. O shell carrega `/assets/js/fokus.min.js?v=20260916-fokus-styles-2.7.0`, `fokus.css` e `fokus-law-shell.css`.

Fontes principais: `routes/web.php:95-112`; `resources/views/portal/fokus-law.blade.php`; `resources/views/portal/partials/fokus-law-profile.blade.php`; scripts `fokus-law-shell.js`, `fokus-law-contacts.js`, `fokus-law-processes.js`, `fokus-law-access.js`, `law-record-ui.js`, `portal-profile.js`, `public/marketing/products/fokus-law.js`, `fokus-law-plans.js` e `public/auth/contratar-fokus-law.js`; folhas `public/portal/assets/fokus-law-shell.css`, `public/marketing/products/fokus-law.css` e `public/auth/checkout.css`.

## Convenções de leitura

- **FS**: classe ou API oficial Fokus Styles observada no código. Isso prova o contrato estático pretendido, não a aparência computada.
- **Local**: composição ou controle com classe `law-*`, `lp-*` ou `checkout-*`; verificar se é realmente exclusivo do produto antes de manter CSS próprio.
- **Parcial**: a família existe, mas algumas ocorrências não usam a classe/API oficial adequada.
- **N/V visual**: não foi possível conferir essa página/estado renderizado nesta auditoria.
- Para cada página, as colunas listam todos os grupos de componentes constatados nos seus templates, no shell que a envolve e no renderer funcional correspondente. Os estados separados na coluna final alteram a árvore de controles e contam como consumidores da mesma página.

## Matriz página × componentes

| Página/estado | Formulários e controles | Ações e navegação | Dados e composição | Feedback e overlays | Contrato visual e evidência |
|---|---|---|---|---|---|
| `/produtos/fokus-law` — apresentação pública | Sem formulário de dados; botões/links CTA | Header/nav, âncoras, links de ajuda e planos | Hero, cards de benefícios e seções em composição `law-*` | Revelação animada de seções; sem estado de formulário | Sem `fs-*` encontrado no HTML. Local; N/V visual nesta execução. |
| `/produtos/fokus-law/planos` — catálogo e composição | Botões de ciclo mensal/anual; `select` de plano; `fieldset`/`legend` de módulos; inputs dinâmicos de capacidade; formulário de proposta com nome, e-mail, organização, segmento, capacidade, consentimento e hidden fields | Navegação, link continuar contratação com `aria-disabled`, ação de proposta | Cards/ofertas carregadas, resumo de composição e total mensal/anual | Estados de carregamento do catálogo, sem oferta, cotação/validação e envio representados na página | Classes `law-*`/`lp-*`; sem `fs-*`. Controles nativos locais; verificar estilo e estados próprios em viewport. N/V visual. |
| `/contratar/fokus-law` — revisar contratação/acessar | O JS monta formulário de CPF/CNPJ e senha; após autenticação monta seletor de empresa nativo quando necessário | Entrar, continuar, iniciar pagamento ou solicitar alteração; link de cadastro/voltar aos planos | Resumo de plano, módulos, ciclo, ajustes de capacidade e preço recotado pelo servidor | Verificando cotação, validação de documento, acesso inválido, conta sem empresa, erro/sucesso de operação | Estilo de checkout local; `fs-card-title` é usado isolado no título, sem anatomia `fs-card`: uso parcial. Não submeter nem iniciar pagamento durante auditoria. N/V visual. |
| `/portal/fokus-law` — shell, início/visão geral | Busca global (`label` oculto + `input[type=search]`), alternador de empresa/listbox e seletor nativo de setor | Rail, navegação contextual, botão mobile, notificações, perfil, logout e suporte | Cartões/indicadores, atalhos e resumos recentes de contatos/processos | Spinner de carregamento, aviso de suporte, busca sem resultado/resultados, painel local de notificações | Shell usa `fs-spinner`, `fs-btn*` e `fs-form-control`; navegação e cards de dashboard são majoritariamente `law-*`. Se a preferência local restaurar grupo, a tela inicial pode mostrar um módulo em vez do overview. Overview observado em browser desktop; widget de carregamento de resumo visto brevemente. |
| `/portal/fokus-law` — módulo Contatos, busca/lista | Busca, filtros/selects, campos por registro | Botões de criar, abrir, editar, ações por linha; paginação | Métricas/cards, tabela/lista de contatos, badges/estados | Estados vazio, carregando, falha, detalhes e busca global | `fs-card*`, `fs-table*`, `fs-pagination`, `fs-btn*`, `fs-form-control`, `fs-alert*`; alguns selects usam `fs-form-control` em vez de `fs-form-select` (parcial). Lista e modal Novo contato foram vistos em browser desktop; compartilhar e qualidade também foram vistos, ver estados abaixo. |
| `/portal/fokus-law` — Novo/Editar contato | Campos text/email/tel/number conforme metadados; selects de natureza/profissão/vínculo; endereço; textarea de notas; grupos/checkboxes; labels envolventes, required e mensagens | Salvar, cancelar, fechar; adicionar/remover itens de endereço, documento e vínculo | Grupos de dados do contato e seções do formulário compostas localmente | Modal FS, alerta de validação/servidor e retorno ao acionador/foco | Modal via `FokusStyles.Modal`; componentes `fs-btn*`, `fs-form-control` e larguras `fs-width-*`; anatomia interna/associações de cada controle ainda precisa validação renderizada completa. No estado Novo observado, foco inicial em Nome e envio vazio acionou validação HTML nativa. |
| `/portal/fokus-law` — revisão/duplicidade/mesclagem de contatos | Select de contato de destino, textarea de justificativa obrigatória e controles de confirmação | Botões de revisar, manter/mesclar e cancelar | Comparação de registros e resumo dos campos | Modal, validação nativa e feedback de operação | Usa classes FS para controle/modal em partes, mas select da composição de contatos usa `fs-form-control`, não `fs-form-select`. Não submetido nem validado visualmente nesta execução. |
| `/portal/fokus-law/processos` — lista | Busca, filtro de arquivados (`checkbox`), filtros de classe/assunto conforme configuração | Botões abrir detalhe e ações de linha; paginação | Tabela responsiva, cartões de recentes/contagens, chips/badges de prioridade e estado | Loading, vazio, erro de API, alertas e dados carregados | `fs-table`, `fs-table-responsive`, `fs-check-input`, `fs-badge*`, `fs-card*`, `fs-alert*`, `fs-pagination`, `fs-btn*`; campos compartilhados de `law-record-ui.js`. Comportamento de teclado/estados e todos os viewports N/V visual. |
| `/portal/fokus-law/processos` — detalhe/edição | Campos de número/classe/assunto, partes/contatos relacionados, prioridades/checkboxes, notas e metadados; labels e mensagens | Editar, cancelar, salvar, histórico e ações de acesso externo | Modal amplo com cards/seções, tabela/lista relacionada, histórico temporal e documentos | Modal FS, alertas de erro/sucesso, estado de carregamento e vazio | `FokusStyles.Modal`, `fs-card*`, `fs-form-control`, `fs-form-label`, `fs-check*`, `fs-alert*`; controles prioritários também têm classe local. Não foi aberto no browser. |
| `/portal/fokus-law/empresa` — dados da empresa | Formulário de edição com inputs para nome legal/exibido, e-mail, telefone, site e endereço; documentos/status somente leitura | Editar, salvar/cancelar; navegação para setores e configuração | Cards/seções de identificação, contato, endereço e histórico de auditoria | Feedback inline de carregamento, erro e sucesso | `fs-btn*`, `fs-form-control` e composições locais `law-company-*`; tabela histórica substituída por lista/timeline local. Cards de leitura e formulário de edição observados em browser desktop; edição cancelada sem salvar. |
| `/portal/fokus-law/empresa` — subestado Setores | `label` + input de nome, obrigatório, min/max; seletor de setor ativo no shell | Adicionar/desativar setor/selecionar setor | Formulário e lista de setores ativos | `role=status` com sucesso/erro/loading | Input/button usam `fs-form-control`/`fs-btn`; `#law-unit-select` é `<select class="law-unit-select">`, sem `fs-form-select`. Observado em browser desktop: campo, CTA e lista de dois setores; nenhum dado alterado. |
| `/portal/fokus-law` — subestado Preferências | Labels com caixas `input[type=checkbox]` para lembrar grupo de navegação e movimento reduzido | Alternar/preferências persistidas localmente | Linhas de preferências com título e descrição | Não há feedback remoto; preferência muda estado local | Checkbox sem classe `.fs-check-input` e linha `.law-preference-row`; composição local. Observado em browser desktop: checkbox nativo pequeno alinhado à direita, sem preferência alterada. |
| `/portal/fokus-law/assinatura` — assinatura | Selects de plano, ciclo e complementos/módulos; capacidade por tipo de produto | Solicitar mudança, contratar/continuar ou abrir planos | Cards de plano/módulos, limites, uso, valores e histórico | Loading, erro e confirmação/feedback | Botões e campos FS parciais; selects de assinatura recebem `fs-form-control` em vez de `fs-form-select`. Composição `law-subscription-*` local e CSS próprio. Cards superiores e módulo/capacidade abaixo da dobra observados em browser desktop; nenhum select/checkbox alterado. |
| `/portal/fokus-law/perfil` — perfil | Dados pessoais, telefone/CPF, alteração de e-mail e senha; inputs text/tel/email/password/read-only, label envolvente, required e `aria-describedby` da senha | Salvar dados, solicitar confirmação do novo e-mail e alterar senha | Três cards/seções de dados pessoais, e-mail e segurança | Loading, modo suporte somente leitura, erro/sucesso anunciado por campo | Template reutilizável `fokus-law-profile.blade.php`, `portal-profile.js`, `fs-form-control`, `fs-btn*`; três cards e campos observados em browser desktop. Badge “Confirmado” em verde vivo merece conferência contra tokens semânticos/tema Law. Nenhum campo editado. |
| `/portal/perfil` — alias de perfil | Mesma árvore de `/portal/fokus-law/perfil` | Mesmo fluxo e destino; rota suporta modo compatível | Mesmos cards/seções | Mesmos estados | Segunda URL, mesmo renderer/template; matriz mantida como consumidor de rota separado. N/V visual. |
| `/portal/fokus-law/contatos/compartilhamentos` — compartilhamentos | Select de empresa, checkboxes de tipos de cadastro e campos autorizados | Adicionar empresa e criar/revogar política; navegação contextual | Fieldsets lado a lado, métricas, gráfico e tabela de políticas | Disabled CTA, empty state explicativo, paginação | Usa renderização específica `renderSharingPage`, cards/botões/tabela FS com composição local; screenshot desktop mostra checkboxes visualmente nativos. Nenhum campo ou checkbox foi alterado; opções de empresa não foram abertas. |
| `/portal/fokus-law/contatos/revisao-e-qualidade` — qualidade | Sem filtros visíveis no estado carregado; botão para analisar duplicidades | Ações por linha para completar cadastro; paginação numerada | Métrica “sem telefone” e tabela de contatos a complementar | Loading transitório, tabela carregada e 1 página | Renderer `renderQualityPage`, cards/alerts/botões FS e composição local. Observado em desktop após carregamento; ações de análise/revisão não foram acionadas. |
| `/portal/usuarios` — usuários e permissões | Convite com nome/CPF/e-mail; selects de perfil por usuário/setor; permissões checkbox; edição por modal | Enviar convite, editar/revogar/restaurar acesso, paginação/listagem | Cards de convite/lista, metadados em listas `dl` e grupos de permissões | Alertas de convite, loading, vazio, erro, sucesso; modal de confirmação/edição | `fs-form-control`, `fs-form-select`, `fs-btn*`, alertas e classes locais. `fokus-law-access.js` implementa disable/loading e `aria-busy`; card de convite e de perfis observados em browser desktop, sem enviar convite. |
| `/portal/transferir-administracao` — transferência | Seleção de membro elegível e confirmação da operação | Iniciar/cancelar/confirmar transferência; links de retorno | Aviso de impacto e cartão de destino/estado | Alertas, confirmação e loading/erro | `FokusLawAccessUsers.renderTransfer`; botões e selects oficiais onde usados, restante composição local. Formulário observado em browser desktop; nenhum destino escolhido nem transferência iniciada. |

### Shell comum das 10 URLs do portal

Cada estado autenticado herda: skip link; `aside` com navegação `nav` (rail + menu contextual); cabeçalho do sistema/empresa; button listbox de troca de empresa; `select` do setor; avatar textual; header de pesquisa; `input[type=search]` com label oculto e região live; botão mobile/menu; botão notificações com painel local; logout; região de conteúdo live; link de ajuda e aviso condicional de suporte.

No markup fixo, mobile menu/notificações/logout usam `fs-btn*`; busca usa `fs-form-control`; setor usa classe somente local; spinner usa `fs-spinner`. A maior parte da navegação lateral, listbox, popover, avatar e estados mobile tem classes `law-*` próprias. Não há uso observado da API `fs-navbar`, `fs-dropdown`, `fs-popover` ou `fs-offcanvas` no shell.

### Cruzamento página × renderer × API/composição

Este cruzamento registra as famílias reutilizadas em cada página e o contrato encontrado no renderer, inclusive quando vários estados de uma rota usam o mesmo módulo.

| Página/estado atendido | Renderer/componente consumidor | Componentes/API oficiais encontrados | Componentes HTML ou composição local encontrados |
|---|---|---|---|
| Todas as 10 URLs do portal | Blade `fokus-law.blade.php` + `fokus-law-shell.js` | Button, Input, Alert, spinner; `fs-btn*`, `fs-form-control`, `fs-alert*`, `fs-spinner` | skip link, nav/aside, listbox, menu, notification popover, shell, avatar textual, support banner |
| `/portal/fokus-law` — overview | `renderOverview`, `renderProcessDashboardWidget` | Card, Badge/Alert em componentes carregados quando disponíveis | Metric cards, recents, ações e empty states `law-*` |
| `/portal/fokus-law` — Contatos: lista, busca e detalhes | `FokusLawContacts.render`, busca do shell, `law-record-ui.js` | Card, Button, Input, Alert, Table, Pagination, Modal | Filtros/combinações locais, métricas, rows, chips/tags, menu de ações |
| `/portal/fokus-law` — Contatos: criar/editar, revisar duplicidade | `FokusLawContacts.openContact` e fluxo de merge | Button, Input, Select parcial, Modal, Alert; `FokusStyles.Modal` | Fieldsets/seções, profissões/vínculos, listas de endereço/documento, checkbox, mensagens |
| `/portal/fokus-law/contatos/compartilhamentos` | `FokusLawContacts.renderSharingPage` | Card, Button, Table/Pagination conforme lista | Escolha de empresa, estado selecionado, tabela/charts/empty state locais |
| `/portal/fokus-law/contatos/revisao-e-qualidade` | `FokusLawContacts.renderQualityPage` | Card, Alert, Badge/Button e Pagination conforme lista | Métricas, filas/ações de qualidade e filtros locais |
| `/portal/fokus-law/processos` — lista | `FokusLawProcesses.render` + `law-record-ui.js` | Table, Checkbox, Badge, Card, Button, Alert, Pagination, Input/Select | Search/filter rows, status/priority chips e empty state |
| `/portal/fokus-law/processos` — detalhe/edição | `FokusLawProcesses` + `law-record-ui.dialog/field/section` | Modal, Card, Input, Select parcial, Checkbox, Alert, Button | Seções de processo, histórico, contatos relacionados, documentos e feedback |
| `/portal/fokus-law/empresa` — empresa/setores | `renderCompany`, `renderUnits`, `refreshUnits` | Card/Alert/Button/Input; `fs-form-control` nos textos | Detail cards, auditoria em lista, setor select local, list state |
| `/portal/fokus-law` — preferências | `renderPreferences` | Nenhum componente Checkbox/Switch FS observado | Linhas de preferência com input checkbox e texto/descrição local |
| `/portal/fokus-law/assinatura` e redirect `/portal/assinaturas` | `renderSubscription`, `drawSubscription` | Card/Badge/Button/Input parcial/Alert | Selects de plano/módulo com classe incompatível, cards de módulo, uso/capacidade/histórico |
| `/portal/fokus-law/perfil` e alias `/portal/perfil` | profile template + `portal-profile.js` | Input, Button, Alert; larguras/utilitários FS | Cards de dados/e-mail/segurança, loading, feedback por campo |
| `/portal/usuarios` | `renderUsers` em shell e helpers `FokusLawAccessUsers` | Button, Input, Select, Checkbox parcial, Alert, Modal | Invite card, permissões, linhas/lista de usuários, estado/loading |
| `/portal/transferir-administracao` | `FokusLawAccessUsers.renderTransfer` | Button, Select e Alert conforme ação | Card de destino, lista de elegibilidade, confirmação/estado |
| `/produtos/fokus-law` | Template marketing | Nenhuma classe `fs-*` detectada | Header/nav, hero, CTA, benefit cards, FAQ e transições `law-*` |
| `/produtos/fokus-law/planos` | Template + JS catálogo/compositor | Nenhuma classe `fs-*` detectada | Buttons ciclo, selects, fieldsets, checkbox consent, proposal form, summary e estados `lp-*` |
| `/contratar/fokus-law` | Template + `contratar-fokus-law.js` | `fs-card-title` isolado | Form/login/empresa, quote summary, botões e alerts locais |

Rotas históricas que não produzem uma página independente: `/portal/assinaturas` redireciona à tela Assinatura. A seleção de Contatos ocorre como estado de módulo no shell e não possui rota `Route::get` própria no arquivo atual.

## Catálogo completo de componentes Fokus Styles 2.7.0 confrontado com Law

O catálogo abaixo foi confrontado com as classes/API observadas nos templates, nos renderers e no shell. “Não detectado” significa que a API oficial não aparece nos consumidores Law inspecionados; pode existir controle semanticamente parecido feito localmente, indicado na última coluna. Utilitários `fs-u-*`, larguras `fs-width-*` e spinner aparecem no Law, embora não sejam páginas de componente no catálogo de 56 documentos.

| Componente documentado | Uso no Fokus Law | Evidência/observação |
|---|---|---|
| Accordion | Não detectado | Página pública usa seções/revelação animada; não foi identificado acordeão. |
| Alert | Usado | `fs-alert`, `fs-alert-info/danger/success/warning` em shell e renderers. |
| Alert Dialog | Não detectado | Confirmações usam modal/feedback próprio; nenhuma classe `fs-alert-dialog` observada. |
| Avatar | Equivalente local | Avatar textual `.law-user-avatar`, sem `fs-avatar`. |
| Badge | Usado | `fs-badge` e variantes no módulo Processos. |
| Breadcrumb | Não detectado | Navegação lateral e títulos substituem breadcrumb. |
| Button | Usado | `fs-btn` e variantes primária, secundária, outline, danger, icon. |
| Button Group | Não detectado | Ciclo de cobrança usa botões locais separados; validar grupo/seleção. |
| Card | Usado | `fs-card`, header/body/title, tamanhos. Outros cards seguem `law-*`. |
| Carousel | Não detectado | — |
| Checkbox | Parcial | Processos usa `fs-check*`; preferências/contatos têm inputs checkbox em composições locais. |
| Close Button | Usado | `fs-btn-close` em modais. |
| Code | Não detectado | — |
| Collapse | Não detectado | Estados/FAQ públicos usam JS/CSS local se expansíveis. |
| Combobox | Não detectado | Campos de profissão/seleção não usam `.fs-combobox`; confirmar se há autocomplete customizado local. |
| Command Palette | Equivalente local parcial | Busca global com atalho Ctrl+K; não usa API `fs-command-palette`. |
| DataTable | Equivalente local | Tabelas nativas com `fs-table*` e paginação customizada; não usa API `fs-datatable`. |
| Datepicker | Não detectado | Datas são exibidas/entradas sem classe de datepicker FS observada. |
| Divider | Não detectado | Separadores por CSS `law-*`. |
| Dropdown | Equivalente local | Listbox de empresa e menus contextuais, sem `fs-dropdown`. |
| Empty State | Equivalente local | Mensagens vazias `law-*-empty`, sem `fs-empty-state`. |
| File Upload | Não detectado | — |
| File Upload Advanced | Não detectado | — |
| Icon Link | Equivalente local | Links de navegação com imagens Streamline/labels; sem `fs-icon-link`. |
| Input Group | Não detectado | Prefixos/sufixos visuais são composições locais. |
| Input | Usado | `.fs-form-control` em entradas de texto/contato/processo/perfil. |
| List Group | Equivalente local | Linhas/listas de contatos, usuários, histórico usam `law-*`. |
| Menu | Equivalente local | Navegação do shell própria (`law-rail`, `law-page-navigation`). |
| Modal | Usado | `fs-modal*` e API `FokusStyles.Modal` em `law-record-ui.js`; formulário de contato também é modal. |
| Navbar | Equivalente local | Navegação de aplicação construída com `nav`/`aside` próprios. |
| Nested Menu | Equivalente local | Grupos/módulos e páginas contextualizados localmente. |
| Notification Center | Equivalente local | Botão/painel de notificações `law-popover`; não usa `fs-notification-center`. |
| Offcanvas | Equivalente local | Sidebar e scrim móvel próprios; não usa `fs-offcanvas`. |
| Pagination | Usado | `fs-pagination`, `fs-page-item`, `fs-page-link`; geração em `law-record-ui.js`. |
| Placeholder | Não detectado | Loading usa texto/spinner e não skeleton/placeholder FS. |
| Popover | Equivalente local | Painel de notificações implementado com `law-popover`. |
| Progress | Equivalente local/não confirmado | Métricas e capacidade têm CSS Law; não foi observada API `.fs-progress`. |
| Radio | Não detectado | Preferências e ciclos visíveis usam checkbox/botões. |
| Range | Não detectado | — |
| Rating | Não detectado | — |
| Ratio | Não detectado | — |
| Responsive | Equivalente local | Breakpoints e composições responsivas estão no CSS `law-*`; sem utilitário/componente FS constatado. |
| Scrollspy | Não detectado | — |
| Segmented Control | Equivalente local parcial | Seletor de ciclo mensal/anual em botões na página pública de planos. |
| Select | Parcial | `fs-form-select` no módulo de acesso; selects de Contatos/Assinatura usam `fs-form-control`; setor usa `.law-unit-select`. |
| Skeleton | Não detectado | Loading por spinner e texto. |
| Stepper | Não detectado | Checkout/assinatura não usa stepper FS detectado. |
| Switch | Equivalente local | Preferências são caixas nativas; não usam `.fs-switch`. |
| Table | Usado | `fs-table`, `fs-table-responsive`, ações de tabela em contatos/processos. |
| Tabs | Equivalente local | Navegação contextual de contatos/visões não usa `.fs-tabs`/API de tabs. |
| Tag | Equivalente local | Etiquetas/chips de contatos são `law-*`, sem `.fs-tag` confirmado. |
| Tile | Não detectado | — |
| Timeline | Equivalente local | Histórico/auditoria usa `ol` e classes próprias, não `.fs-timeline`. |
| Toast | Não detectado | Feedback inline/alerta; nenhuma API/classe toast usada. |
| Tooltip | Não detectado | Títulos/labels auxiliares locais; nenhuma API tooltip detectada. |
| Tree View | Não detectado | — |

## Ocorrências detalhadas de controles e contrato de formulários

| Elemento/contrato | Ocorrência no Law | Avaliação estática |
|---|---|---|
| `label` / associação | Busca fixa tem label visualmente oculto associado por `for`/`id`; `law-record-ui.field()` usa `fs-form-label` e `htmlFor`; helpers de Contatos envolvem controle em `label`; templates de Perfil/Usuários usam IDs e `htmlFor`. | Há padrões bons, mas precisa varredura renderizada por campo nos estados dinâmicos de Contatos, Processos e Perfil para fechar IDs, nome acessível e `aria-describedby`. |
| `input[type=search]` | Busca global | Tem label oculto, `aria-controls`, `aria-expanded`; validar sincronização do estado e foco no painel. |
| `input[type=text/email/tel/number]` | Contatos, processos, empresa, assinatura, perfil, convite/usuários e páginas públicas | Geralmente `fs-form-control`; verificar minlength/maxlength/required, `autocomplete`, mensagens e associação em cada campo. |
| `input[type=checkbox]` | Prioridade arquivada/Processos, permissões, preferências, seleção/consentimento de contato e página de planos | Há uso correto de `fs-check-input` em Processos e checkbox nativo local em outras composições. Conferir rótulo clicável, foco, estados e uso da anatomia `.fs-check`. |
| `select` | Empresa/setor, Contatos, assinatura, Usuários/acesso, planos públicos | Contrato inconsistente: alguns usam `.fs-form-select`; outros usam `.fs-form-control` ou `.law-unit-select`. Padronizar conforme API 2.7.0 após verificar tokens e opções. |
| `textarea` | Notas de contato e justificativa de mesclagem | Classes `fs-form-control`; label/required/descrição precisam ser conferidos em cada modal. |
| `fieldset` / `legend` | Grupo de preferências de processo e módulos/plano público | Presente em alguns fluxos; agrupamento das preferências do portal deve ser revisto para grupos de caixas relacionados. |
| `button` versus `a` | Botões executam ações; links navegam. Botões sem texto dependem de `aria-label`/`title` em linhas e toolbar. | Revisar nomes dos ícones sem texto, `type=button/submit`, loading/disabled e foco visível. `law-toolbar-button` sobrescreve estados CSS FS. |
| Tabela e paginação | Contatos/Processos e visões administrativas do produto | Estrutura nativa com classes de tabela FS; paginação expõe números via componentes compartilhados. Conferir cabeçalhos, `scope`, responsividade e foco no item atual. |
| Modal e foco | Contato/processo/ação de acesso | API `FokusStyles.Modal` é usada na composição compartilhada; verificar Escape, retorno do foco, foco inicial, nome do diálogo e armadilha de foco por modalidade. |

## Divergências priorizadas

| Prioridade | Evidência | Divergência/impacto | Recomendação e proprietário |
|---|---|---|---|
| P1 | `public/portal/assets/fokus-law-shell.js:1193-1224`; `public/portal/assets/fokus-law-contacts.js:26-29`; `resources/views/portal/fokus-law.blade.php:39-42` | Selects recebem `fs-form-control` (contrato de input) e o seletor de setor fixo usa apenas `law-unit-select`. A aparência/foco do select pode divergir do componente Select 2.7.0. | Usar `fs-form-select` em selects nativos e manter somente dimensões/cores de contexto no CSS Law; revisar todos os consumidores de select em Law. Responsável: shell Law no Cloud. |
| P1 | `public/portal/assets/fokus-law-shell.css:26`; API 2.7.0 usa token `--fs-font-sans` | `--fs-font-family` não é token da API identificada. A tipografia pode estar sendo aplicada por regra local, mas alteração via token fica ineficaz. | Configurar `--fs-font-sans` no escopo do tema Law e remover token sem consumidor, preservando Google Sans conforme identidade do produto. Responsável: shell Law no Cloud. |
| P1 | `public/portal/assets/fokus-law-shell.css:133-137` | Toolbar e saída sobrescrevem cores/bordas/fundos do botão FS com `!important`, inclusive hover/focus. Isso contorna o estado oficial e pode ocultar foco/tema. | Migrar paleta para tokens `--fs-btn-*`; manter local apenas composição/tamanho exclusivos. Verificar computed styles e foco visível. Responsável: shell Law no Cloud. |
| P2 | `public/portal/assets/fokus-law-shell.js:964-977` | Preferências geram checkbox nativo sem `fs-check-input`/anatomia oficial; a implementação local precisa provar foco, tamanho e estados equivalentes. | Compor `.fs-check`/`.fs-check-input`/`.fs-check-label` ou documentar exceção exclusiva do shell. |
| P2 | `public/marketing/products/fokus-law-planos.html:15-17`; `public/marketing/products/fokus-law.html`; `public/auth/contratar-fokus-law.html` | Páginas públicas usam controles e cards locais sem convergência sistemática com as classes Fokus Styles. A identidade Law é legítima, mas controles comuns repetidos podem divergir de acessibilidade/estados do framework. | Migrar formulários e botões comuns para API FS 2.7.0, mantendo paleta/typo via tema e CSS exclusivo apenas para composição/hero. Responsável: Cloud (marketing/checkout). |
| P2 | `public/portal/assets/fokus-law-shell.js`, `fokus-law-contacts.js`, `fokus-law-processes.js` e `fokus-law-shell.css` | Navegação, listbox, popover, sidebar mobile, timeline, empty states e listas duplicam famílias existentes no catálogo sem comparação de estados concluída. | Auditar cada substituto local em viewport/renderização antes de propor migração; priorizar equivalência de teclado, ARIA, foco e responsividade. |
| P3 | `public/portal/assets/fokus-law-contacts.js:28`; `public/portal/assets/law-record-ui.js:15-23` | Helpers de select não são uniformes (um usa `fs-form-control`, outro `fs-form-select`), aumentando inconsistência entre páginas. | Unificar contrato no helper compartilhado após converter os controles e conferir tema. |

## Cobertura observada e pendências

| Evidência | Cobertura desta análise |
|---|---|
| Rotas | 10 URLs de portal mapeadas para 9 estados base; perfil tem URL canônica e alias. Três URLs públicas Law adicionadas. Incluem-se subestados de contatos, processos e setores porque alteram componentes. |
| Código | Shell Blade, partial de Perfil e todos os seis módulos de interface Law revisados por uso de classes, tags/controles, renderers e imports: access, contacts, processes, shell, record UI e portal profile. Rotas backend associadas conferidas. |
| Catálogo | 56/56 documentos 2.7.0 classificados quanto a uso oficial observado ou equivalente local/não detectado. Utilitários e `fs-spinner` também inventariados fora dessas 56 páginas de componente. |
| Browser | Desktop autenticado: overview, Contatos (lista, modal Novo, Compartilhamentos, Revisão e qualidade), Processos (lista e modal Novo), Configurações, Empresa (visualização e edição), Setores, Assinatura, Usuários/permissões, transferência, Perfil e Preferências. A qualidade foi observada após carregar a tabela; Setores/Compartilhamentos mostram checkboxes nativos. Modal Novo contato exibiu foco inicial em Nome e validação HTML nativa quando vazio na execução anterior. Formulários e operações não foram salvos; nenhum dado foi alterado nesta execução. |
| Não verificado | Detalhes de registros, sucesso/erro real de gravação, completar/revisar cadastros, cálculo/alteração de assinatura, convites e transferência; páginas públicas Law; tablet/mobile; computed styles; todos os estados hover/focus-visible/active/disabled/loading/vazio/sucesso/erro; temas, movimento reduzido, leitores de tela e teclado além do foco inicial/Escape observados. |

Por isso, esta entrega fecha a **matriz estática página × componente e a classificação do catálogo** para o escopo Law, mas não atesta equivalência visual completa. As células dependentes de estilos computados e dos estados de dados estão explicitamente pendentes de validação renderizada.

