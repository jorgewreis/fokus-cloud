# Backoffice Cloud: modelo visual e evolução operacional

**Estado:** proposta para validação; não é especificação comercial aprovada nem prova de implementação.

**Base examinada:** `27a2beb62d37b0126fd1a241349e0c7ba140ae61` em 13/11/2026. O [inventário estático](backoffice-inventory.md) registra seus próprios limites e uma base anterior; conferir alterações posteriores antes de executar qualquer etapa. A fila e o estado das entregas permanecem no [Linear](https://linear.app/reis-developer/project/fokus-cloud-f279b0530261).

## Propósito e fronteiras

O Backoffice é o ambiente de operação interna do Cloud para governar empresas, identidade interna, catálogo, assinaturas, cobrança e auditoria. Seu desenho deve continuar utilizável quando houver muitas empresas e produtos, sem transferir para o Cloud as regras ou dados operacionais privados de cada produto. O banco e os serviços do Cloud continuam fontes de verdade dos contratos comerciais; um nome apresentado na interface não é identificador estável de produto. As fronteiras estão em [repository-boundaries.md](repository-boundaries.md).

Na base examinada, o roteador registra painel, catálogo, empresas, produtos, módulos, planos, assinaturas, pagamentos, vouchers, usuários, interesses, seis entradas de auditoria e componentes. As três telas de sessões, eventos detalhados e relatórios de auditoria anunciam trabalho futuro; presença da rota não comprova funcionalidade. O inventário descreve guards e testes identificados, sem comprovar cada perfil, estado ou comportamento renderizado. A navegação não substitui a autorização na API.

## Modelo visual proposto

A direção é um console operacional legível, com densidade ajustada à tarefa: listas para comparar muitos registros, detalhes progressivos para investigar um caso e confirmações específicas para ações sensíveis. Usar os tokens e componentes da versão efetivamente instalada do Fokus Styles, conforme [governança visual](fokus-styles-ui-governance.md); uma necessidade reutilizável pertence ao Styles, uma composição específica da operação pertence ao Cloud. Ícones devem usar os PNGs oficiais Streamline Ultimate, com Ultimate Bold nos tamanhos pequenos quando aplicável.

| Região | Comportamento proposto | Critério verificável |
| --- | --- | --- |
| Shell e navegação | Agrupar destinos por visão geral, empresas, catálogo, assinaturas e cobrança, operação comercial, pessoas e auditoria. Exibir somente destinos autorizados, com nome e contexto persistentes. | Rotas diretas continuam protegidas no servidor; seleção, foco, carregamento e retorno são claros em cada perfil. |
| Listas | Título, resumo útil, filtros explícitos, tabela ou lista responsiva e paginação no servidor quando o volume exigir. Preservar filtros ao voltar do detalhe. | Busca, filtros, ordenação e total representam o conjunto consultado; nenhuma página vazia é confundida com ausência de resultados. |
| Detalhes | Identidade e status primeiro; seções de contratos, produtos, eventos e ações carregadas sob demanda. Dados indisponíveis ou desatualizados são identificados. | Cada informação indica sua origem e, quando dependente de sincronização, a última atualização; falha de uma seção não apaga as outras. |
| Formulários e ações | Labels persistentes, ajuda junto ao campo, erros associados, revisão de efeitos e confirmação para operação sensível. | Teclado, foco, validação, erro, sucesso, cancelamento e proteção contra envio duplicado são testados. |
| Indicadores | Mostrar definição, período e atualização; linkar para o conjunto correspondente quando houver permissão. | A mesma fórmula é documentada e testada; estado indisponível não aparece como zero. |
| Acessibilidade e adaptação | Estados de carregamento, vazio, erro, acesso negado e sucesso; foco visível, zoom/refluxo, movimento reduzido e navegação móvel sem sobreposição. | Evidência renderizada por página/estado, incluindo teclado e viewports de 320, 375, 768, 1024 e 1440 px; leitores de tela e contraste são avaliados separadamente. |

Não há protótipo ou aprovação visual nesta proposta. Antes de adotar componentes, verificar markup, API, estados e CSS calculado conforme o [protocolo página × componente](fokus-styles-component-audit.md); snapshots e checker estático não substituem essa inspeção.

## Capacidades e oportunidades

As capacidades da primeira coluna são identificadas no inventário e nas rotas; a presença de código não comprova funcionamento em todos os perfis. A segunda coluna contém propostas, não regras aprovadas.

| Área existente | Evolução proposta e resultado esperado | Dependência ou decisão |
| --- | --- | --- |
| Painel, alertas e busca | Visão de trabalho com pendências priorizadas, filtros por produto e empresa e acesso às evidências que sustentam cada indicador. | Definir responsáveis, fonte, período, atualização e fórmula de cada indicador; testar permissões por resultado. |
| Empresas, assinaturas e pagamentos | Ficha operacional contextual com produtos contratados, situação comercial, ações autorizadas e histórico paginado, com carregamento independente. | Conciliar a documentação de cadastro com o fluxo implementado; definir limites de dados pessoais e ações de suporte antes de ampliar a ficha. |
| Catálogo e interesses | Filtros orientados pelo catálogo e identificação de produtos históricos; visualizar composição e estado de publicação antes de agir. | Produto é referenciado por código estável; critérios comerciais e política de publicação permanecem nas fontes oficiais. |
| Conciliação, reembolsos e vouchers | Filas com filtros escaláveis, informação sobre estado, responsável e histórico; confirmar ações e explicar efeitos antes da gravação. | Definir ciclo, atribuição e exceções com a operação; manter permissões, trilha de auditoria e idempotência onde houver efeitos externos. |
| Diretório e auditoria | Destacar ações autorizadas por função e permitir investigação por evento, período e ator com mascaramento apropriado. | Definir retenção por classe e os requisitos das telas hoje futuras; não prometer acesso a dados internos dos produtos. |
| Métricas de produto | Propor contrato versionado de métricas de uso resumidas por empresa e `product_code`, com fonte, momento de coleta, estado e versão. | Cada produto é responsável por sua medição; Cloud valida acesso, versão e contexto de empresa. Dados ausentes, atrasados e erro permanecem distintos de zero. |

As propostas de filas, atribuições e novos relatórios exigem regras, permissões, retenção e critérios próprios antes da implementação. Não representam um novo mecanismo de cobrança, preço ou integração comercial.

## Contratos para escala e segurança

- **Consultas e operações:** filtrar e ordenar no servidor sobre o conjunto completo, com paginação e ordenação estável. Definir limites, índices e metas de latência a partir de medições sintéticas e necessidades operacionais; não estabelecer capacidade de produção por estimativa. Consultas lentas devem permitir diagnóstico por domínio sem registrar dados pessoais desnecessários.
- **Compatibilidade:** introduzir novos formatos ou métricas por contrato explícito e versão, manter consumidores antigos durante a transição e testar combinação de frontend antigo/novo com backend antigo/novo. Retirada de contrato legado depende de inventário de consumidores e evidência; controle de cache de assets faz parte da mudança.
- **Identidade e segregação:** manter guard de plataforma separado dos clientes, permissão por ação e por categoria de resultado, contexto de empresa explícito quando pertinente e autorização no servidor. Auditoria deve registrar ator, ação, alvo e resultado conforme a política existente, sem segredos em logs.
- **Métricas:** estabelecer para cada indicador sua população, janela, fórmula, tratamento de ausência, momento de referência e grau de consistência. Agregações por lotes não devem ser apresentadas como retrato único sem teste de concorrência e garantia compatível com o banco. Registrar fonte e atraso das métricas externas.
- **Integrações de produto:** contrato proposto deve prever autenticação entre produtores e Cloud, validação de `product_code` e empresa, versão, idempotência quando aplicável, tratamento de atraso/falha e observabilidade. Não copiar dados jurídicos ou imobiliários para resolver um indicador sem contrato e necessidade demonstrada.
- **Operação e reversão:** ações com efeitos externos precisam de confirmação, rastreabilidade e tratamento explícito de repetição e falha parcial. Cada migração deve documentar coexistência, monitoramento, rollback de código e recuperação de dados antes do deploy; respeitar as proteções vigentes.

## Sequência recomendada e aceite

1. **Inventário e linha de base.** Completar a REI-46 e relacionar cada tela, perfil, estado e evidência ao contrato do Styles na REI-47. Fechar lacunas de reprodução e registrar divergências de documentação; não declarar auditoria concluída por contagem de páginas ou snapshots.
2. **Integridade operacional.** Confirmar e corrigir falhas reproduzidas de autorização, filtros, paginação, prontidão e lifecycle; testar permissões negadas, navegação concorrente e compatibilidade. Priorizar dados incompletos e exposição sobre alterações cosméticas.
3. **Padrões de interface.** Validar uma listagem, uma ficha e um fluxo sensível representativos em ambiente sintético; então aplicar as composições verificadas às demais telas. Revisar desktop e mobile, foco, contraste, estados e diferenças calculadas antes/depois.
4. **Escala e fronteiras.** Medir consultas com volumes e distribuição sintéticos documentados, comparar resultado e custo antes/depois, eliminar carregamentos desnecessários e propor contrato de métricas por produto. Ensaiar MySQL/Redis isolados quando forem relevantes; SQLite e mocks não os substituem.
5. **Capacidades novas.** Detalhar uma proposta por fluxo com perfis, regra de negócio, estados, efeitos, métricas e aceite; validar com a operação antes de implementá-la. Reaproveitar tarefas existentes no Linear e registrar dependências sem duplicar a fila.
6. **Entrega e acompanhamento.** Revisar independentemente o diff, executar testes proporcionais e conferir renderização real. Registrar ambiente, commit, comandos, dados sintéticos, resultados e pendências; acompanhar checks, publicação e comportamento autorizado sem confundir cada etapa com aceite de produto.

Esta sequência não fixa prazo, volume, custo, metas de desempenho ou disponibilidade sem dados. A validação final exige demonstração de cada cenário aplicável e indicação dos inacessíveis; não há evidência de homologação autenticada, conformidade integral de acessibilidade ou comportamento de produção neste documento.
