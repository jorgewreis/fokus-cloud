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

## Continuação: largura estreita e contraste

Em Chromium headless, com servidor visual e dados sintéticos, `/backoffice/empresas` foi observado em 1280 × 900 e 320 × 900 px. A largura rolável do documento foi respectivamente 1280 e 320 px. Em 320 px, os limites horizontais de Nova empresa, Busca e Filtrar permaneceram dentro da janela. Isso é emulação de largura, não zoom real, e não demonstra que outros elementos ou contêineres estejam livres de corte.

No botão examinado, o texto foi observado em branco, 14 px e peso 500, sobre `oklch(0.53563 0.213215 261.626)`. A medição de razão não foi concluída: a ferramenta utilizada preservou a cor em OKLCH e não a converteu para cálculo válido. Não há veredito de contraste para esse botão. Medição confiável, zoom real, demais estados e leitor de tela seguem pendentes.

### Medição complementar

A cor OKLCH acima foi convertida localmente por `@colordx/core` para sRGB `rgb(26, 97, 230)`. A fórmula de luminância relativa WCAG aplicada contra branco produziu **5,39:1**, acima do limiar AA de 4,5:1 para texto normal. O resultado refere-se somente às cores opacas observadas no estado medido do botão; hover, foco, estados desabilitados, composição e outros controles não foram avaliados.

No Chromium headless, seis comandos `Ctrl++` não alteraram largura CSS, `devicePixelRatio` ou escala visual. Portanto, essa tentativa não demonstra zoom de 200% ou 400%. Prosseguir com navegador interativo e confirmação da escala efetiva; a emulação anterior de 320 px permanece evidência distinta.

### Controles adicionais de Empresas

Em Chromium headless, 1280 × 900 px, `/backoffice/empresas`, com fixture sintética e controles no estado inicial, foram medidas cores opacas, sem imagem de fundo na cadeia examinada. A conversão para sRGB usou `@colordx/core`, seguida da fórmula de luminância relativa WCAG:

| Controle | Texto | Fundo | Razão |
| --- | --- | --- | --- |
| Busca `#company-query` | `rgb(37, 37, 37)` | `rgb(254, 254, 254)` | 15,20:1 |
| Status `#company-status` | `rgb(37, 37, 37)` | `rgb(254, 254, 254)` | 15,20:1 |
| Botão Filtrar | `oklch(0.267815 0.106607 261.626)` | `rgb(254, 254, 254)` | 15,23:1 |
| Botão Nova empresa `#company-create` | branco | `oklch(0.53563 0.213215 261.626)` | 5,39:1 |

As quatro razões superam 4,5:1 para texto normal nesse estado. Campos vazios não comprovam contraste do placeholder; bordas, foco, hover, desabilitado, outros tamanhos e leitores de tela não foram avaliados.

### Foco, hover e placeholder

Em Chromium headless, fixture sintética de `/backoffice/empresas` a 1280 × 900 px:

- O placeholder de `#company-query` usa `oklch(0.554391 0.040717 257.417)` com opacidade 0,4 sobre `rgb(254, 254, 254)`. A composição aproximada é `rgb(192, 199, 208)`, com **1,69:1**. O campo tem rótulo visível “Busca”; ainda assim, o exemplo pode ser difícil de ler. Recomenda-se melhorar seu contraste e repetir a medição.
- Em hover, com movimento reduzido e transições desativadas para estabilizar a leitura, Filtrar e Nova empresa apresentaram texto branco sobre `oklch(0.53563 0.213215 261.626)`: **5,39:1**.
- Em foco, Busca, Status, Filtrar e Nova empresa apresentaram outline sólido de 2 px na mesma cor azul, cerca de **5,34:1** contra `rgb(254, 254, 254)`. No botão azul, a borda interna não contrasta com o próprio fundo; a aparência e adjacência reais não foram medidas por pixel.

Os valores derivam de estilos computados e composição aproximada; não demonstram conformidade integral do indicador de foco. Outros viewports, estados desabilitados e leitores de tela permanecem pendentes.

O placeholder da busca foi corrigido localmente com opacidade integral na folha específica de Empresas, preservando a cor existente. Para a cor observada `rgb(100, 116, 139)` sobre `rgb(254, 254, 254)`, a razão calculada é aproximadamente **4,72:1**. O ensaio Playwright confirmou a opacidade integral em 320 e 1280 px; ele não calcula contraste nem verifica leitor de tela.
