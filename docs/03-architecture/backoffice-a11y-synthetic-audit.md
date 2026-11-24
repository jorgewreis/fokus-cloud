# Auditoria sintética de acessibilidade do Backoffice — 13/11/2026

## Método e limites

Observação realizada em Edge headless com servidor `tools/serve-backoffice-visual.mjs` na porta local 4191, timezone padrão do ambiente e dados sintéticos. O código examinado foi `3f1573f2` da branch `codex/backoffice-company-scale-20261113`, que inclui a correção proposta para a ficha de empresa; este SHA não identifica a implantação em produção. O Playwright bloqueou requisições para destinos que não fossem `127.0.0.1:4191`. Isso não comprova contenção de rede do sistema operacional nem segurança do backend. A fixture do servidor simulou uma conta superadministradora; não houve login, persistência ou dados reais.

As 18 rotas registradas foram solicitadas em 375 × 900 e 1440 × 900 px com a fixture padrão, registrando erro JavaScript não tratado, transbordamento horizontal do documento e primeiro foco por Tab. Não houve erros JavaScript nem overflow horizontal do documento nas páginas efetivamente abertas; pagamentos e vouchers abriram o fragmento de empresas por ausência de permissão na fixture. As capturas temporárias correspondentes a vouchers mostram empresas, não vouchers. Na segunda passagem de pagamentos e vouchers a 1440 px com permissões adicionais, verificou-se apenas o fragmento aberto, sem registrar erros, overflow ou foco. As verificações não inspecionam todo controle, tabela rolável, fluxo, contraste ou estado. O menu mobile foi observado também após a animação em 375 × 812 px. Capturas temporárias de empresas e usuários foram examinadas; não incluem dados reais. Nenhuma conclusão de conformidade geral ou de leitura por tecnologia assistiva decorre desses resultados.

## Matriz por rota

`OK` significa somente abertura no fragmento esperado, sem erro de página e sem overflow horizontal global no viewport indicado. Não é aprovação visual, funcional ou de acessibilidade. A fixture padrão não fornecia as permissões de pagamentos e vouchers, de modo que ambas foram redirecionadas para empresas em ambos os tamanhos. Uma passagem intermediária em 1440 px com permissões adicionais confirmou apenas a abertura dos fragmentos, sem registrar erros, overflow ou foco. O reteste complementar abaixo cobre as duas larguras.

**Reteste complementar:** as duas rotas foram abertas novamente nas duas larguras com uma identidade sintética incluindo `platform.payments.view` e `platform.vouchers.manage`. Em Edge 158.0.4370.16, os quatro casos retornaram HTTP 200, abriram o fragmento esperado e não apresentaram erro JavaScript não tratado nem overflow horizontal do documento. Os [resultados sanitizados](evidence/backoffice-a11y-20261113/fokus-pay-voucher-audit.json), a [medição geométrica mobile](evidence/backoffice-a11y-20261113/fokus-pay-voucher-geometry.json) e as quatro capturas abaixo foram preservados. Requisições a fontes externas foram bloqueadas; assim, a aparência das fontes não comprova fidelidade ao ambiente real. A ausência de overflow do documento não impede corte dentro de contêineres, observado nos dois casos móveis.

- Pagamentos: [375 px](evidence/backoffice-a11y-20261113/fokus-pagamentos-375-a11y.png) e [1440 px](evidence/backoffice-a11y-20261113/fokus-pagamentos-1440-a11y.png).
- Vouchers: [375 px](evidence/backoffice-a11y-20261113/fokus-vouchers-375-a11y.png) e [1440 px](evidence/backoffice-a11y-20261113/fokus-vouchers-1440-a11y.png).

| Rota | Fragmento esperado | 375 px | 1440 px |
| --- | --- | --- | --- |
| `painel` | `platform-dashboard-final` | OK | OK |
| `visao-geral-catalogo` | `catalog-overview` | OK | OK |
| `empresas` | `companies` | OK | OK |
| `planos` | `subscription-plans` | OK | OK |
| `produtos` | `products` | OK | OK |
| `modulos` | `modules` | OK | OK |
| `assinaturas` | `subscriptions` | OK | OK |
| `pagamentos` | `pagamentos` | OK no reteste com permissão adicional; corte interno no filtro | OK no reteste com permissão adicional |
| `vouchers` | `vouchers` | OK no reteste com permissão adicional; corte interno no filtro | OK no reteste com permissão adicional |
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

A captura e o script usados nessa observação foram mantidos apenas em diretório temporário; a saída textual da árvore e dos estilos foi observada na execução, mas não foi registrada como log durável versionado. A reprodução deve ser repetida para gerar evidência anexável antes de encerrar o achado.

## Achado: filtros cortados no mobile

Na largura de 375 px, as capturas do reteste mostram cortes nos campos de busca de pagamentos e vouchers. A medição do campo `#billing-payments-query` encontrou limites horizontais de aproximadamente 129 a 489 px em viewport de 375 px; o contêiner da página oculta o conteúdo excedente. No campo `#voucher-search`, o limite direito foi 371 px enquanto o card que o recorta terminava em 349 px. São observações do estado inicial com dados simulados em `3f1573f2`, não uma afirmação sobre produção nem sobre todos os estados dos filtros. Investigar responsividade dos contêineres e repetir a medição e interação por teclado após a correção.

## Fluxo adicional observado

Em 1440 × 900 com a fixture administrativa e permissões sintéticas, o botão Nova empresa abriu o drawer. Em 18 pressões consecutivas de Tab, o foco permaneceu nos controles do drawer e retornou ao início; Escape fechou o drawer após a animação, devolvendo o foco a Nova empresa. Isso cobre apenas esse drawer e essa sequência. A atualização, o envio, os erros de validação, Shift+Tab, zoom, leitores de tela e demais drawers continuam pendentes.

O resultado foi observado na saída interativa do script, sem log durável versionado; o script temporário pode não persistir entre sessões. Reexecutar e registrar o resultado é necessário para uma evidência auditável.

## Pendências

- Repetir a matriz por perfil autorizado e negado, com carregamento, vazio, erro, sucesso e operações relevantes; testar pagamentos e vouchers em mobile.
- Medir contraste, zoom/refluxo, alvo de toque, foco reverso, anúncios e comportamento com leitores de tela nos fluxos críticos.
- Capturar e revisar visualmente todas as páginas e estados em viewports acordados, incluindo 320, 768 e 1024 px; preservar evidências por SHA e fixture.
- Validar autenticação, autorização, persistência e integrações em ambiente seguro com backend real e dados sintéticos. Mocks não comprovam esses contratos.
- Corrigir o achado do menu e repetir teste de nomes e foco em estados recolhido e expandido antes de considerá-lo resolvido.

## Reteste dos filtros de planos e usuários — 14/11/2026

Em Edge headless, em 320 × 700 px, com a fixture administrativa sintética e
código baseado em `37535392`, os campos de busca de planos e usuários mediram
respectivamente 71–491 px e 33–453 px, excedendo a largura do viewport e sendo
recortados pelo cartão. A mesma inspeção visual confirmou o recorte. A correção
estende a composição responsiva já usada nos filtros de registros do Cloud;
não modifica o pacote Fokus Styles 2.7.0. A largura fixa local do formulário de
registros motivou a adaptação local, específica deste shell.

Após a correção, as capturas de [planos](evidence/backoffice-filtros-20261114/planos-320-depois.png)
e [usuários](evidence/backoffice-filtros-20261114/usuarios-320-depois.png)
mostram os dois campos inteiros. O teste `backoffice-mobile-filters.spec.mjs`
verifica geometria e digitação em 320, 375 e 768 px com fixture, sem backend
real. A comparação foi feita na branch da alteração, não em produção. Não
comprova os demais estados, acessibilidade completa ou fidelidade das fontes.
