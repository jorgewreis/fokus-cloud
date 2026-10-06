# Protocolo de auditoria página × componente

Este protocolo torna exaustiva a revisão visual de páginas do Fokus Cloud,
Fokus Law, Fokus Lead e Admin/Backoffice. A meta é examinar cada consumidor
visível e cada estado pertinente, não apenas demonstrar que algumas páginas
usam classes do Fokus Styles.

## 1. Fixe a referência da auditoria

1. Liste repositórios, versão solicitada, versão declarada, versão resolvida no
   lockfile e versão carregada em `node_modules/fokus-styles`.
2. Use documentação, SCSS, CSS compilado, JavaScript, tipos e exemplos do mesmo
   release. Se faltar documentação empacotada, consulte o checkout/tag
   canônico correspondente. Registre cada fonte ausente e não substitua uma
   versão pela outra sem declarar a diferença.
3. Monte o catálogo completo a partir de todos os arquivos
   `docs/components/*.md` desse release, compare com o README e com a API real,
   e acrescente componentes/API encontrados no código que ainda não tenham uma
   página própria. Não mantenha uma lista fixa que possa omitir componentes de
   uma versão posterior.

Como referência do release `2.7.0`, o checkout canônico contém **56** páginas
de componentes. O catálogo observado é:

| Grupo de conferência | Componentes documentados em `2.7.0` |
| --- | --- |
| Ações e controles | Button, Button Group, Checkbox, Combobox, Datepicker, File Upload, File Upload Advanced, Input, Input Group, Radio, Range, Rating, Segmented Control, Select, Stepper, Switch |
| Navegação e dados | Breadcrumb, Command Palette, DataTable, Dropdown, Menu, Navbar, Nested Menu, Pagination, Scrollspy, Table, Tabs, Tree View |
| Feedback e overlays | Alert, Alert Dialog, Collapse, Modal, Notification Center, Offcanvas, Popover, Progress, Skeleton, Toast, Tooltip |
| Conteúdo e estrutura | Accordion, Avatar, Badge, Card, Carousel, Close Button, Code, Divider, Empty State, Icon Link, List Group, Placeholder, Ratio, Responsive, Tag, Tile, Timeline |

Confirme a contagem no diretório do release usado em cada execução. Componentes
sem uso nas páginas auditadas ficam como `não usado`; componentes cuja API,
consumidor ou estado não pôde ser inspecionado ficam como `não verificável`,
nunca simplesmente omitidos.

## 2. Feche o inventário de páginas

Para cada repositório no escopo, descubra rotas públicas, autenticadas,
administrativas, páginas de marketing, rotas SPA, fragmentos carregados sob
demanda e telas auxiliares. Consulte roteadores, registros, templates, links de
navegação e consumers. Registre página/rota e shell responsável.

Inclua estados que mudam a árvore de componentes: criação, edição, consulta,
menus e popovers abertos, drawers/modals abertos, formulários com erro,
carregamento, vazio e sucesso, quando existirem. O mesmo componente em outro
estado ou outro consumidor é uma ocorrência adicional na matriz.

Se o pedido limitar o escopo, liste precisamente as páginas incluídas e
excluídas. Se o pedido for transversal, não escolha páginas representativas:
continue até enumerar e examinar todas. Só encerre com cobertura parcial quando
houver um bloqueio concreto e a cobertura restante estiver quantificada.

## 3. Registre cada componente encontrado

Não limite a inspeção a nomes com prefixo `fs-`. Procure componentes oficiais,
composições do produto e controles HTML nativos. Para formulários, confira
individualmente:

- `label` e `fs-form-label`, associação `for`/`id`, label visível e nome
  acessível; placeholder não substitui label;
- cada `input` por tipo (text/search/email/number/date/file/checkbox/radio/
  hidden e demais tipos realmente presentes), `fs-form-control` ou componente
  especializado, atributos, ajuda, required, inválido e desabilitado;
- `select` nativo com `fs-form-select` e Select customizado/Combobox como
  componentes distintos;
- `textarea`, `fieldset`, `legend`, grupos, addons, prefixos/sufixos, mensagens
  e `aria-describedby`;
- `button` versus `a`, variante e tamanho (`fs-btn-*`), propósito, loading,
  disabled, `aria-busy`, foco e área acionável.

Faça o mesmo para cards, listas, tabelas, paginação, badges, alertas, menus,
tabs, navegação, tooltips, popovers, overlays, ícones, feedback, progresso e
quaisquer outros componentes descobertos no catálogo e nas páginas.

## 4. Compare com a API e com a interface renderizada

Para cada ocorrência, registre:

| Campo | O que registrar |
| --- | --- |
| Página/estado | Rota, produto, estado da interface e consumidor |
| API oficial | Página de referência do componente, classes, variante, tokens, atributos e API JS realmente suportados |
| Implementação | Elemento/markup, classes, atributos, hooks, folha e seletor local que afeta a ocorrência |
| Acessibilidade | Nome/descrição, semântica, teclado, foco, ARIA, retorno/gerenciamento de foco e movimento reduzido conforme aplicável |
| Aparência | Família e tamanho de fonte, pesos, espaçamento, dimensões, cores/tokens, borda, superfície e contraste calculados |
| Estados e viewports | Normal, hover, focus-visible, active/selecionado, disabled, loading, erro, vazio e sucesso pertinentes; desktop, tablet e mobile |
| Conclusão | `alinhado`, `divergente`, `não usado` ou `não verificável`, com evidência concreta |

Inspecione a ordem real das folhas carregadas e os estilos computados. A presença
de classe oficial no HTML não comprova que o componente mantém a anatomia ou os
estados oficiais: detecte overrides, `!important`, tokens redeclarados, folhas
duplicadas e diferenças entre markup fonte e DOM renderizado.

Preserve diferenças de paleta e tipografia que estejam definidas para cada
produto. Registre-as como tema de produto; só as classifique como divergência
quando alterarem contrato comum, legibilidade, contraste, foco, estados ou
semântica sem justificativa documentada.

## 5. Critério de conclusão e entrega

Apresente contagem total de páginas, ocorrências e componentes catalogados;
matriz página × componente completa; estados/viewports inspecionados; itens não
usados e não verificáveis; e achados priorizados com arquivo/linha, efeito,
recomendação e repositório responsável. Diferencie evidência em código de
renderização observada no navegador.

Se o navegador ou uma sessão autenticada não estiver disponível, relate quais
páginas/estados ficaram sem validação renderizada. Não classifique esses itens
como alinhados nem declare cobertura visual completa por causa de source,
build, testes ou deploy.
