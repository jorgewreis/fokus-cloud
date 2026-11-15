# REI-48 — Auditoria de acessibilidade: checkpoint estático

Data: 15/11/2026 (America/Bahia)
Base inspecionada: `b1574c4bf3c4d55a10c4c3aa425a36dc52efcea1` (`origin/main`)
Escopo: componentes modais do Backoffice e contratos de foco/movimento já versionados. Não foram usados dados, contas ou serviços reais.

## Método e limite de evidência

Foram inspecionados os templates reais em `public/backoffice/pages/`, o contrato de foco existente em `tests/browser/backoffice-sidebar-a11y.spec.mjs` e as folhas de estilo de foco e movimento reduzido. `npm run backoffice:contract:check` passou para as 19 páginas do Backoffice.

O ensaio Playwright foi recuperado nesta cópia com as dependências locais e Chromium já disponíveis. Ele cobre a abertura dos modais financeiro, de convite e de showcase, contexto de diálogo, foco inicial, Escape e retorno ao gatilho nos dois fluxos com gatilho visível. O modal financeiro foi aberto pelo gatilho técnico oculto porque a fixture não oferece uma ação financeira elegível; o retorno de foco desse fluxo de negócio específico, contraste medido, leitor de tela, zoom e reflow continuam pendentes.

A revisão posterior confirmou que o runtime `FokusStyles.Modal` já injeta papel, modalidade, nome pelo título, foco preso, Escape e retorno ao gatilho quando inicializado. A correção abaixo torna o mesmo contrato explícito no HTML e acrescenta descrições programáticas; não altera o runtime nem substitui a verificação renderizada.

## Achados e correção declarativa

| Severidade | Contexto e reprodução | Evidência estática | Impacto | Próxima ação |
| --- | --- | --- | --- | --- |
| Média — corrigido no markup | Em uma conta com permissão financeira, abrir uma ação que exija motivo em Pagamentos. | `#billing-action-modal` agora declara `tabindex`, `role="dialog"`, `aria-modal`, título e descrição. | O contrato declarativo permanece disponível antes e independente da auto-inicialização. O foco ainda precisa de observação no navegador. | Reproduzir foco inicial, Tab/Shift+Tab, Escape e retorno ao gatilho em conta sintética. |
| Média — corrigido no markup | Em Segurança, acionar “Convidar administrador”. | `#invite-dialog` agora declara papel, modalidade, título e descrição do convite. | O convite passa a ter nome e contexto programáticos no template. | Exercitar validação, Escape e retorno de foco em navegador. |
| Baixa — corrigido no markup | Na página de componentes, abrir “Confirmação”. | `#component-modal` agora tem papel, modalidade, título e descrição identificáveis. | A vitrine passa a oferecer o mesmo contrato declarativo para futuras cópias. | Incluir em ensaio renderizado do catálogo de componentes. |

## Sinais positivos verificados por leitura

- O Backoffice já possui foco visível e nomes no menu recolhido sob teste dedicado em `tests/browser/backoffice-sidebar-a11y.spec.mjs`.
- Os diálogos de Usuários, Vouchers, Assinaturas, Módulos, Produtos e Planos já declaram `role="dialog"`, `aria-modal="true"` e referência de título.
- Há regras de `prefers-reduced-motion` no shell e nos componentes do Backoffice; a equivalência de interação ainda requer observação renderizada.

## Verificações executadas

- `npm run backoffice:contract:check` — aprovado: contrato visual válido para 19 páginas.
- `node --check public/backoffice/assets/js/payments-page.js` — aprovado.
- `node tests/Frontend/backoffice-modal-a11y-contract.cjs` — aprovado; protege a semântica declarativa e as referências de título/descrição dos três modais.
- Inspeção dos templates e dos seletores de foco/movimento — achados documentados acima.
- `npx playwright test tests/browser/backoffice-sidebar-a11y.spec.mjs tests/browser/records-drawer-focus.spec.mjs --config=playwright.backoffice.config.mjs --workers=1` — 5 aprovados; nomes, foco visível, recolhimento do menu e retorno de foco do drawer foram observados em fixture sintética.
- `npx playwright test tests/browser/backoffice-modal-a11y.spec.mjs --config=playwright.backoffice.config.mjs --workers=1 --timeout=15000` — 2 aprovados; semântica, descrição, foco inicial e Escape dos três modais foram exercitados; o retorno de foco foi confirmado para convite e showcase.

## Cobertura ainda necessária

1. Reproduzir os três modais em navegador com conta sintética e registrar árvore acessível, foco inicial, Tab/Shift+Tab, Escape e retorno ao gatilho.
2. Medir contraste e reflow/zoom nos fluxos financeiro, convite e confirmação; testar leitores de tela em ao menos um fluxo crítico.
3. Só então encaminhar os achados para REI-51, REI-52, REI-53 e a automação da REI-55.
