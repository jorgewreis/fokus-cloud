# Explorações de interface — Processos do Fokus Law

Cinco propostas estáticas para comparar estruturas de uma tela de Processos. A navegação entre telas e os controles de estado são apenas demonstrativos; não há requisições, persistência nem regras de negócio. Todos os nomes, números e eventos processuais exibidos são sintéticos.

> **Novo módulo em exploração:** três propostas locais para Gestão de Contatos estão em [`contacts/`](contacts/). A base visual usa a estrutura escolhida de sidebar escura e barra superior; cada proposta inclui alternância demonstrativa entre escritório de advocacia, setor público e Poder Judiciário.

## Como visualizar

Execute `node mockups/fokus-law/preview.mjs` na raiz do repositório e abra `http://127.0.0.1:8130/mockups/fokus-law/`. O servidor liga apenas em loopback, aceita somente GET/HEAD, entrega os protótipos desta pasta e encaminha somente `/assets/` do `public/`; não cria rotas Laravel nem publica os arquivos. Não copie estes arquivos para `public/` sem uma decisão explícita de publicação.

O seletor inicial abre cada proposta. Dentro das telas, o controle **Ver estado da lista** alterna entre dados de exemplo, carregamento, vazio e erro. Os demais botões e filtros são somente visuais.

Para comparar as propostas de Contatos, abra `http://127.0.0.1:8130/mockups/fokus-law/contacts/` no mesmo preview local. Os três modelos compartilham seis contatos sintéticos e oferecem exemplos de dados, carregamento, vazio e erro. O seletor de perfil apenas troca rótulos e papéis ilustrativos; não representa regras funcionais específicas de cada instituição.

### Gestão de Contatos

| Arquivo | Composição | Hipótese que explora |
| --- | --- | --- |
| `contacts/modelo-01.html` | Sidebar escura, barra superior, métricas, filtros e tabela | Diretório denso para localizar rapidamente contatos e papéis. |
| `contacts/modelo-02.html` | Mesma navegação com grupos e cartões de relações | Leitura visual de pessoas e instituições sem perder os vínculos. |
| `contacts/modelo-03.html` | Lista de contatos junto de ficha e processos relacionados | Consulta do cadastro com contexto do contato selecionado. |

As três propostas mantêm os mesmos seis registros, identificadores fictícios, papéis por perfil institucional e paginação ilustrativa. E-mail usa domínios reservados de exemplo; não inserir dados pessoais reais. O CSS e JavaScript próprios estão em `contacts/assets/`.

## Propostas

| Arquivo | Composição | Hipótese que explora |
| --- | --- | --- |
| `mockup-01.html` | Sidebar escura, métricas, filtros e tabela densa | Familiaridade e leitura tabular de alto volume. |
| `mockup-02.html` | Navegação superior e cartões de processo em largura ampla | Aproveitamento horizontal sem navegação lateral permanente. |
| `mockup-03.html` | Trilho compacto, alertas, lista e resumo persistente | Acompanhar carteira e contexto do caso ao mesmo tempo. |
| `mockup-04.html` | Workspace por grupos de urgência e vencimento | Priorizar trabalho acionável na rotina da unidade. |
| `mockup-05.html` | Hierarquia editorial e ritmo visual mais espaçado | Reforçar identidade institucional e valor percebido. |

## Conteúdo comum

Os cinco layouts usam os mesmos cinco processos fictícios e preservam número, classe, cliente, parte contrária, tribunal/unidade, responsável, fase, próximo prazo, prioridade, situação, movimentação recente e indicação de sigilo. Os indicadores e a paginação são ilustrativos e iguais entre as opções. A diferença entre telas está na organização e na ênfase da informação, não no conteúdo.

## Sistema visual e acessibilidade

- A base de componentes vem de `public/assets/css/shared/fokus.css`, gerada pelo Fokus Styles instalado (2.7.0). Os protótipos não editam nem substituem componentes dessa biblioteca.
- As cores seguem os tokens documentados do Fokus Law em `docs/04-products/fokus-law.md`: ameixa, ameixa profunda, roxo, roxo claro, sálvia, sálvia clara, marfim e tinta.
- O CSS local trata apenas composição, identidade de cada proposta e adaptação do protótipo. Os dados centrais e renderizadores ficam em `assets/fokus-law-mockups.js`.
- Cada página tem landmarks semânticos, títulos, rótulos associados, link para pular ao conteúdo, foco visível, estado atual anunciado nos controles e respeito a `prefers-reduced-motion`.
- Dados pessoais e processuais reais não devem ser inseridos nestes arquivos.

## Critérios para comparar

Registrar nota e observação para cada alternativa considerando:

1. clareza da navegação e localização dentro do produto;
2. quantidade de processos legíveis sem rolagem excessiva;
3. facilidade de localizar prazo, prioridade e situação;
4. contraste e leitura da paleta em cada superfície;
5. hierarquia da movimentação e dos metadados do processo;
6. aproveitamento da largura e equilíbrio entre densidade e respiro;
7. comportamento em teclado e compreensão dos estados de carregamento, vazio e erro;
8. adaptação inicial para tablet e mobile e itens que pedem uma decisão de produto antes de evoluir.

Desktop é a referência primária da comparação. Em larguras menores, a revisão serve para encontrar cortes, sobreposição e necessidade de adaptação; não representa aprovação de um produto mobile final. A tabela da proposta clássica mantém rolagem horizontal em viewport estreito para preservar seus campos.

## Limites da exploração

Não alterar portal, Backoffice, rotas, API, autenticação, permissões, banco de dados ou pacote Fokus Styles. Os arquivos estão fora de `public/`, portanto não fazem parte do conteúdo publicado. Nenhuma proposta está aprovada para produção por existir neste diretório.
