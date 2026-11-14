# Inventário do Backoffice Cloud — telas, contratos e evidências

## Referência e limites

Inventário estático da árvore `5802b688daa3112dac37e5e0d853f647187d1dbe` (main após PRs #74, #76 e #75), elaborado em 13/11/2026. O escopo é o Backoffice Cloud; o registro de um produto não inclui suas telas internas. As fontes de navegação são `public/backoffice/assets/js/backoffice-router.js` e `routes/web.php`; as permissões de API abaixo vêm de `routes/api.php`. O roteador é uma barreira de apresentação, não substitui a autorização do servidor. O grupo de APIs exige `EnsurePlatformAdmin`; cada ação ainda pode exigir uma permissão ou superadministrador.

**Situação da evidência:** nomes de controles, fluxos e testes são declarados no código. Este documento não atesta que cada estado foi renderizado, que o teste foi executado, que as permissões funcionam em produção ou que a interface é acessível. `—` na coluna de guarda do roteador significa ausência de `role`/`permission` na entrada do registro; não significa acesso sem autenticação ou ausência de guarda em outra camada. Antes de concluir a REI-46, capturar por perfil e viewport os estados efetivos com dados sintéticos e anotar evidência individual.

## Matriz de telas registradas

Todas as rotas da tabela são relativas a `/backoffice/`; fragmentos ficam em `public/backoffice/pages/` e módulos em `public/backoffice/assets/js/`. Cada linha identifica os estados ou fluxos encontrados na fonte e as famílias de testes pertinentes; a matriz não afirma que cada teste cobre cada estado ou perfil. As entradas de auditoria usam `audit-page.js`.

| Rota | Fragmento; módulo | Guarda no registro; contrato principal no servidor | Estados e fluxos identificados | Referências de testes existentes |
| --- | --- | --- | --- | --- |
| `painel` | `platform-dashboard-final.html`; comportamento no shell | —; `platform.dashboard.view` | Indicadores, alertas, atalhos, vazio e erro | `tests/browser/backoffice-visual.spec.mjs`: dashboard e estados; `tests/Feature/BackofficeSecurityTest.php` |
| `visao-geral-catalogo` | `catalog-overview.html`; `catalog-overview-page.js` | `platform.catalog.manage`; catálogo `platform.catalog.manage`, publicação `platform.catalog.publish` | Resumo, pendências, publicações, vazio, erro e nova tentativa | `backoffice-visual.spec.mjs`: visão geral; `tests/Feature/CatalogAdminTest.php` |
| `empresas` | `companies.html`; `companies-page.js` | —; leitura `platform.companies.view`, alterações `platform.companies.manage` | Filtros, lista, detalhe, criação/edição e ações de status | `backoffice-visual.spec.mjs`: snapshots, drawer e navegação; `tests/Feature/CompanyAdminTest.php` |
| `planos` | `subscription-plans.html`; `subscription-plans-page.js` | `superadministrador`; gestão `platform.catalog.manage`, publicação/exclusão `platform.catalog.publish` | Lista, composição, detalhe, criação/edição e estados de publicação | `backoffice-visual.spec.mjs`: planos, PATCH e ciclo de vida; `CatalogAdminTest.php` |
| `produtos` | `products.html`; `products-page.js` | `superadministrador`; endpoints de produtos reservados a superadministrador | Filtros, lista, criação, edição, detalhe e ciclo de vida | `backoffice-visual.spec.mjs`: cinco viewports e PATCH; `CatalogAdminTest.php` |
| `modulos` | `modules.html`; `modules-page.js` | `superadministrador`; gestão `platform.catalog.manage`, publicação/exclusão `platform.catalog.publish` | Filtros, lista, detalhe, criação/edição e ativação | `backoffice-visual.spec.mjs`: cinco viewports, PATCH e ação única; `CatalogAdminTest.php` |
| `assinaturas` | `subscriptions.html`; `subscriptions-page.js` | —; `platform.subscriptions.manage`; nome público exige superadministrador | Filtros, detalhe, histórico, checkout assistido, alteração e encerramento | `backoffice-visual.spec.mjs`: filtros, drawers, checkout e vazios; `tests/Feature/SubscriptionAdminTest.php` |
| `pagamentos` | `pagamentos.html`; `payments-page.js` | `platform.payments.view`; conciliação e reembolso possuem permissões próprias | Abas financeiras, filtros, paginação, detalhes, conciliação e reembolso | `tests/browser/payments-page.spec.mjs`; `tests/Feature/ReconciliationAndRefundTest.php` |
| `vouchers` | `vouchers.html`; `vouchers-page.js` | `platform.vouchers.manage`; arquivo/exclusão `platform.catalog.publish` | Filtros, paginação, detalhe, criação/edição, resgates e reservas | `backoffice-visual.spec.mjs`: vouchers e debounce; `tests/Feature/BackofficeSecurityTest.php`, `VoucherRedemptionTest.php` |
| `usuarios` | `users.html`; `users-page.js` | `platform.users.view`; gestão interna `platform.security.manage` | Diretório, detalhe, convite, papel, bloqueio e erro de listagem | `backoffice-visual.spec.mjs`: diretório; `backoffice-invite-readiness.spec.mjs`; `tests/Feature/PlatformSecurityTest.php` |
| `interesses` | `product-interests.html`; script no fragmento | —; `platform.product_interests.manage` | Filtros, lista, paginação, detalhe e atualização de status; opções Law/Lead estão fixas na fonte | `tests/Feature/ProductInterestTest.php`; teste browser específico não identificado nesta leitura |
| `auditoria` | `audit-overview.html`; `audit-page.js` | `superadministrador`; auditoria comercial `platform.audit.view_commercial` | Visão geral e navegação para subáreas | `tests/Feature/AuditContractTest.php`; cobertura browser específica não identificada |
| `atividade-recente` | `audit-activity.html`; `audit-page.js` | `superadministrador`; auditoria comercial `platform.audit.view_commercial` | Eventos e atividade recente | `AuditContractTest.php`; cobertura browser específica não identificada |
| `controle-de-acessos` | `audit-access.html`; `audit-page.js` | `superadministrador`; endpoint específico reservado a superadministrador | Filtros, paginação e eventos de acesso | `tests/Feature/BackofficeSecurityTest.php`; cobertura browser específica não identificada |
| `sessoes-ativas` | `audit-sessions.html`; `audit-page.js` | `superadministrador`; endpoint de sessões desta tela não identificado | Texto informa que monitoramento será conectado futuramente | Sem teste de fluxo identificado; não presumir funcionalidade operacional |
| `eventos-do-sistema` | `audit-events.html`; `audit-page.js` | `superadministrador`; endpoint dedicado desta tela não identificado | Texto indica visão detalhada futura e remete à atividade | Sem teste de fluxo identificado; não presumir funcionalidade operacional |
| `relatorios-de-auditoria` | `audit-reports.html`; `audit-page.js` | `superadministrador`; endpoint dedicado desta tela não identificado | Texto informa dependência de definição de indicadores e exportação | Sem teste de fluxo identificado; não presumir relatório ou exportação funcionais |
| `componentes` | `ui-components.html`; sem módulo no registro | —; não foi atribuído endpoint de negócio no registro | Demonstrações de componentes e DataTable | `tests/Feature/BackofficeUiContractTest.php`; não comprova conformidade visual de todos os consumidores |

## Shell e superfícies fora da matriz

- `public/backoffice/index.html` fornece shell, menu, busca e controles de conta. A busca usa `/api/backoffice/search`: a rota exige `platform.dashboard.view` e, na base deste inventário, o controlador restringe grupos por permissões dos domínios. Estados e resultados por combinação de permissões exigem execução dedicada.
- `routes/web.php` registra `/backoffice/ativar` e `/backoffice/confirmar-email`, servidas por `public/backoffice/ativar.html` e `confirmar-email.html`. Há endpoints públicos de autenticação/ativação com throttling em `routes/api.php`; inventariar token válido, inválido, expirado e erro de rede em ambiente descartável antes de considerar os fluxos cobertos.
- `public/backoffice/pages/security.html` existe fora do registro de fragmentos. `/backoffice/seguranca` é alias de `users`; não remover o arquivo sem verificar consumidores e decisão de legado.
- Os aliases `catalogo`, `modules`, `billing` e `product-interests` apontam para fragmentos registrados. O menu tem destinos desabilitados; a existência de um alias não demonstra acesso ao destino para todo perfil.

## Componentes e cobertura de evidência

As telas usam classes e inicializadores de `fokus-styles`, incluindo cards, tabelas/DataTable, formulários, botões, alertas, badges, paginação, tabs, modais e offcanvas. O lockfile fixa `fokus-styles` 2.7.0. Fontes locais do shell e de página acrescentam adaptações; para cada instância, comparar API instalada, cascade e estado renderizado conforme `fokus-styles-component-audit.md`. A presença da classe no HTML não comprova que o componente é inicializado ou que sua acessibilidade funciona.

`tests/browser/backoffice-visual.spec.mjs` declara comparações de empresas, produtos e módulos em cinco viewports e outros fluxos de interface; `payments-page.spec.mjs` e `backoffice-invite-readiness.spec.mjs` acrescentam cenários. Fixtures e interceptações simulam APIs: não comprovam autenticação, persistência ou transporte real. Snapshots no repositório são referências, não evidências da execução deste inventário. `phpunit.xml` configura SQLite em memória para testes, o que não comprova MySQL/Redis ou isolamento total de rede. O checker `npm run backoffice:contract:check` verifica contrato estático, não aparência, foco ou contraste.

## Pendências para fechamento da matriz

1. Para cada linha e cada perfil pertinente, registrar os estados carregando, populado, vazio, erro, acesso negado e ações pertinentes como **observado**, **não aplicável com justificativa** ou **não verificado**, com captura/log, commit e ambiente.
2. Comparar componentes efetivamente renderizados, estilos calculados, foco, teclado, contraste e responsividade nas dimensões acordadas; não generalizar a partir das telas que possuem snapshot.
3. Verificar contratos de API, CSRF, isolamento, operações e respostas em ambiente descartável com dados sintéticos; indicar quais testes usam mocks e quais exercitam backend e banco.
4. Conciliar as três superfícies de auditoria que anunciam funcionalidades futuras, o alias de segurança e os itens desabilitados com decisões e backlog. Registrar hipóteses separadamente de defeitos reproduzidos.
5. Atualizar este inventário após merges, pois branches concorrentes podem alterar router, filtros, paginação e seus testes. A matriz não substitui o acompanhamento oficial no Linear.
