# REI-48 — Auditoria de acessibilidade: checkpoint estático

Data: 15/11/2026 (America/Bahia)
Base inspecionada: `b1574c4bf3c4d55a10c4c3aa425a36dc52efcea1` (`origin/main`)
Escopo: componentes modais do Backoffice e contratos de foco/movimento já versionados. Não foram usados dados, contas ou serviços reais.

## Método e limite de evidência

Foram inspecionados os templates reais em `public/backoffice/pages/`, o contrato de foco existente em `tests/browser/backoffice-sidebar-a11y.spec.mjs` e as folhas de estilo de foco e movimento reduzido. `npm run backoffice:contract:check` passou para as 19 páginas do Backoffice.

O ensaio Playwright não pôde ser executado nesta cópia: ela não inclui dependências versionadas; uma instalação local isolada ficou inconsistente no ambiente Windows (`playwright-core` não carregou `./utilsBundle`). Portanto, este documento não afirma comportamento no navegador, contraste medido, ordem de tabulação, leitor de tela, zoom ou reflow. Os achados abaixo são estáticos e precisam de reprodução no navegador antes de fechar a REI-48.

## Achados

| Severidade | Contexto e reprodução | Evidência estática | Impacto | Próxima ação |
| --- | --- | --- | --- | --- |
| Média | Em uma conta com permissão financeira, abrir uma ação que exija motivo em Pagamentos. | `public/backoffice/pages/pagamentos.html:91` declara `#billing-action-modal` com classe `fs-modal` e `aria-hidden`, mas sem `role="dialog"`, `aria-modal="true"`, `aria-labelledby` ou `aria-describedby`. | O diálogo de confirmação pode não ser anunciado como contexto modal e não expõe programaticamente título e descrição para tecnologias assistivas. O foco deve ser confirmado em navegador, pois o comportamento do runtime não foi observado nesta execução. | Alinhar o markup ao padrão já empregado em `users.html`, `vouchers.html` e `subscriptions.html`; acrescentar regressão de árvore acessível, foco inicial, Escape e retorno ao gatilho. |
| Média | Em Segurança, acionar “Convidar administrador”. | `public/backoffice/pages/security.html:22` declara `#invite-dialog` sem `role="dialog"`, `aria-modal`, `aria-labelledby` ou descrição programática; o título já existe como `#invite-dialog-title` na linha 24. | A abertura do convite pode não comunicar o diálogo e seu nome à pessoa que usa leitor de tela. | Adotar o contrato do modal acessível e testar teclado completo, incluindo erro de validação e retorno de foco. |
| Baixa | Na página de componentes, abrir “Confirmação”. | `public/backoffice/pages/ui-components.html:43` tem `#component-modal` sem papel, modalidade e título identificável. | A vitrine interna propaga um exemplo sem o contrato de acessibilidade usado nos fluxos corrigidos, aumentando o risco de cópia para novas telas. | Corrigir o exemplo ou substituí-lo por componente canônico; incluir no teste de regressão do catálogo de componentes. |

## Sinais positivos verificados por leitura

- O Backoffice já possui foco visível e nomes no menu recolhido sob teste dedicado em `tests/browser/backoffice-sidebar-a11y.spec.mjs`.
- Os diálogos de Usuários, Vouchers, Assinaturas, Módulos, Produtos e Planos já declaram `role="dialog"`, `aria-modal="true"` e referência de título.
- Há regras de `prefers-reduced-motion` no shell e nos componentes do Backoffice; a equivalência de interação ainda requer observação renderizada.

## Verificações executadas

- `npm run backoffice:contract:check` — aprovado: contrato visual válido para 19 páginas.
- `node --check public/backoffice/assets/js/payments-page.js` — aprovado.
- Inspeção dos templates e dos seletores de foco/movimento — achados documentados acima.
- `npx playwright test tests/browser/backoffice-sidebar-a11y.spec.mjs --config=playwright.backoffice.config.mjs` — não executado com sucesso por dependência local Playwright inconsistente; não usar este estado como evidência de comportamento.

## Cobertura ainda necessária

1. Reproduzir os três modais em navegador com conta sintética e registrar árvore acessível, foco inicial, Tab/Shift+Tab, Escape e retorno ao gatilho.
2. Medir contraste e reflow/zoom nos fluxos financeiro, convite e confirmação; testar leitores de tela em ao menos um fluxo crítico.
3. Só então encaminhar os achados para REI-51, REI-52, REI-53 e a automação da REI-55.
