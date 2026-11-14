# Auditoria sintética de acessibilidade do Backoffice — 13/11/2026

## Método e limites

Observação realizada em Edge headless com servidor `tools/serve-backoffice-visual.mjs` na porta local 4191, timezone padrão do ambiente e dados sintéticos. O código examinado foi `3f1573f2` da branch `codex/backoffice-company-scale-20261113`, que inclui a correção proposta para a ficha de empresa; este SHA não identifica a implantação em produção. O Playwright bloqueou requisições para destinos que não fossem `127.0.0.1:4191`. Isso não comprova contenção de rede do sistema operacional nem segurança do backend. A fixture do servidor simulou uma conta superadministradora; não houve login, persistência ou dados reais.

Foram abertas as 18 rotas registradas em 375 × 900 e 1440 × 900 px. Para cada abertura, verificaram-se erro JavaScript não tratado, transbordamento horizontal do documento e o primeiro foco por Tab. Não houve erros JavaScript nem overflow horizontal do documento nos estados observados. Essas verificações não inspecionam todo controle, tabela rolável, fluxo, contraste ou estado. O menu mobile foi observado também após a animação em 375 × 812 px. Capturas temporárias dos estados de empresas, usuários, vouchers e auditoria foram examinadas; não incluem dados reais. Nenhuma conclusão de conformidade geral ou de leitura por tecnologia assistiva decorre desses resultados.

## Matriz por rota

`OK` significa somente abertura no fragmento esperado, sem erro de página e sem overflow horizontal global nos dois viewports. Não é aprovação visual, funcional ou de acessibilidade. A fixture padrão não fornecia as permissões de pagamentos e vouchers, de modo que ambas foram redirecionadas para empresas em ambos os tamanhos. Uma segunda navegação em 1440 px com permissões sintéticas adicionais confirmou a abertura dos fragmentos correspondentes; o estado específico em 375 px permanece não verificado.

| Rota | Fragmento esperado | 375 px | 1440 px |
| --- | --- | --- | --- |
| `painel` | `platform-dashboard-final` | OK | OK |
| `visao-geral-catalogo` | `catalog-overview` | OK | OK |
| `empresas` | `companies` | OK | OK |
| `planos` | `subscription-plans` | OK | OK |
| `produtos` | `products` | OK | OK |
| `modulos` | `modules` | OK | OK |
| `assinaturas` | `subscriptions` | OK | OK |
| `pagamentos` | `pagamentos` | Não verificado; fixture padrão sem permissão | OK com permissão sintética adicional |
| `vouchers` | `vouchers` | Não verificado; fixture padrão sem permissão | OK com permissão sintética adicional |
| `usuarios` | `users` | OK | OK |
| `interesses` | `product-interests` | OK | OK |
| `auditoria` | `audit-overview` | OK | OK |
| `atividade-recente` | `audit-activity` | OK | OK |
| `controle-de-acessos` | `audit-access` | OK | OK |
| `sessoes-ativas` | `audit-sessions` | OK | OK |
| `eventos-do-sistema` | `audit-events` | OK | OK |
| `relatorios-de-auditoria` | `audit-reports` | OK | OK |
| `componentes` | `ui-components` | OK | OK |

## Achado: menu recolhido sem nomes acessíveis e foco visível

**Reprodução:** em 1440 × 900, abrir `/backoffice/empresas` com a fixture administrativa, manter o menu lateral recolhido e pressionar Tab uma vez. O foco chega ao botão Dashboard. A árvore acessível obtida por `ariaSnapshot()` para `#sidebar` apresenta os sete botões ativos de navegação sem nome; o botão focado tem `outline-style: none` e `box-shadow: none`, e a captura inspecionada não exibe indicador de foco no botão. Os rótulos dos itens estão no DOM, mas ficam ocultos na apresentação recolhida. Quando o menu mobile foi expandido, a árvore acessível apresentou nomes, incluindo Dashboard e Empresas.

**Impacto:** com o menu recolhido, pessoas que navegam por teclado podem não localizar o foco, e a árvore de acessibilidade observada não oferece nomes para identificar os destinos. Isso é evidência da árvore do Edge, não um ensaio com leitor de tela. Recomenda-se manter nomes acessíveis nos botões recolhidos, prover foco visível e testar ambos os estados e larguras.

## Fluxo adicional observado

Em 1440 × 900 com a fixture administrativa e permissões sintéticas, o botão Nova empresa abriu o drawer. Em 18 pressões consecutivas de Tab, o foco permaneceu nos controles do drawer e retornou ao início; Escape fechou o drawer após a animação, devolvendo o foco a Nova empresa. Isso cobre apenas esse drawer e essa sequência. A atualização, o envio, os erros de validação, Shift+Tab, zoom, leitores de tela e demais drawers continuam pendentes.

## Pendências

- Repetir a matriz por perfil autorizado e negado, com carregamento, vazio, erro, sucesso e operações relevantes; testar pagamentos e vouchers em mobile.
- Medir contraste, zoom/refluxo, alvo de toque, foco reverso, anúncios e comportamento com leitores de tela nos fluxos críticos.
- Capturar e revisar visualmente todas as páginas e estados em viewports acordados, incluindo 320, 768 e 1024 px; preservar evidências por SHA e fixture.
- Validar autenticação, autorização, persistência e integrações em ambiente seguro com backend real e dados sintéticos. Mocks não comprovam esses contratos.
- Corrigir o achado do menu e repetir teste de nomes e foco em estados recolhido e expandido antes de considerá-lo resolvido.
