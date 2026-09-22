# Padrões de interface do Backoffice

O Backoffice usa o Fokus Styles `2.7.0` como fonte de componentes visuais. O
HTML de cada página deve reutilizar a anatomia abaixo e alterar apenas conteúdo,
dados, permissões e regras de negócio.

As regras gerais de decisão, fronteira entre repositórios e validação estão em
[Governança visual com Fokus Styles](fokus-styles-ui-governance.md). Os prompts
reutilizáveis ficam em [`docs/prompts`](../prompts/).

## Regra de composição

- Componentes reutilizáveis usam classes `fs-*` e utilitários `fs-u-*`.
- Classes `admin-*` são reservadas ao shell exclusivo, a hooks de JavaScript e
  a identificadores semânticos que não definem aparência.
- CSS local não deve redefinir botões, campos, tabelas, cards, badges, alerts,
  paginação ou overlays existentes no Fokus Styles.
- Quando um refinamento combinar primitivas Fokus Styles com uma convenção do
  produto, ele deve ser criado no contrato compartilhado do Fokus Cloud
  (`tools/sync-fokus-styles.mjs`), com nome `fs-*`; não é necessário alterar o
  repositório externo `fokus-styles` para essa composição.
- A referência visual executável está em `/backoffice/componentes`.

## Anatomia de uma página

Use um container oficial, um stack vertical e um cabeçalho de conteúdo. Métricas
ficam em cards; listagens ficam em `fs-datatable`; mensagens usam alert ou toast.

```html
<main class="fs-container-fluid fs-u-p-4">
  <div class="fs-stack fs-stack-gap-3">
    <header class="fs-stack fs-stack-gap-1">
      <span class="fs-u-text-uppercase fs-u-fw-semibold">Contexto</span>
      <h1 class="fs-u-m-0">Título da página</h1>
      <p class="fs-u-color-secondary fs-u-m-0">Descrição operacional.</p>
    </header>

    <section class="fs-row fs-row-cols-4 fs-u-gap-3" aria-label="Resumo">
      <article class="fs-card fs-card-sm"><div class="fs-card-body">Métrica</div></article>
    </section>

    <section class="fs-card">
      <div class="fs-card-header fs-u-d-flex fs-u-justify-content-between fs-u-gap-2">
        <div><h2 class="fs-card-title">Itens cadastrados</h2><p class="fs-card-subtitle">Resumo da operação.</p></div>
        <button class="fs-btn fs-btn-primary" type="button">Novo cadastro</button>
      </div>
      <div class="fs-card-body">Conteúdo da página.</div>
    </section>
  </div>
</main>
```

## Componentes obrigatórios

| Necessidade | API Fokus Styles | Regra |
| --- | --- | --- |
| Botão | `fs-btn` + variante | Nunca usar `submit` ou botão visual local. |
| Campo | `fs-form-label`, `fs-form-control`, `fs-form-select` | Labels devem estar associados aos controles. |
| Card | `fs-card`, `fs-card-header`, `fs-card-body`, `fs-card-footer` | Usar utilitários para espaçamento. |
| Tabela | `fs-datatable` + `fs-table` | Usar `data-fs-sort` quando a coluna for ordenável. |
| Status | `fs-badge` + variante | O texto deve permanecer compreensível sem cor. |
| Mensagem | `fs-alert` ou `fs-toast` | Informar sucesso, erro, vazio e carregamento. |
| Paginação | `fs-pagination`, `fs-page-item`, `fs-page-link` | Preservar nome acessível e página atual. |
| Painel lateral | `fs-offcanvas` | Usar foco contido, Escape e botão de fechamento. |
| Confirmação | `fs-modal` | Ações destrutivas exigem confirmação clara. |

## Composições compartilhadas do Fokus Cloud

Estas classes complementam as primitivas instaladas e ficam no CSS gerado por
`npm run styles:sync`. Páginas devem usá-las antes de criar CSS visual local.

| Necessidade | Composição | Uso |
| --- | --- | --- |
| Cabeçalho de página | `fs-page-layout`, `fs-page-header-display` | Contexto, `h1`, descrição e ação principal. |
| Painel operacional | `fs-card fs-card-panel` | Card com cabeçalho, corpo e rodapé alinhados. |
| Filtros | `fs-filter-form`, `fs-input-group-subtle` | Campos rotulados, grupo de busca e ação. |
| Larguras de conteúdo | `fs-width-200` a `fs-width-800` | Larguras semânticas em filtros e colunas. |
| Listagem de registros | `fs-table fs-table-records` | Cabeçalho, linhas, separadores e larguras consistentes. |
| Estado fixo | `fs-badge-width-80` | Badge de status com largura de 80px. |
| Ações por ícone | `fs-btn-icon-plain` | Ícones com fundo transparente. |
| Paginação compacta | `fs-pagination fs-pagination-compact` | Botão quadrado e página atual identificada por `is-active`. |

## Listagens e estados

Uma DataTable deve ter `data-fs="datatable"`, filtro opcional com
`data-fs-datatable-filter`, mensagens com `data-fs-datatable-empty`,
`data-fs-datatable-loading` e `data-fs-datatable-error`, e paginação com
`data-fs-datatable-pagination`. Dados vindos de API podem ser inseridos no
`tbody` pelo script da página e atualizados pelo método `refresh()` da instância.

Filtros específicos do domínio permanecem no JavaScript da página; ordenação,
filtragem textual, foco de células e paginação local devem usar a DataTable
oficial quando o conjunto de dados permitir.

## Acessibilidade

Todo controle deve ter nome acessível, foco visível, estado de carregamento e
mensagem de erro anunciável. Overlays devem usar os componentes oficiais para
travar foco, fechar com Escape e devolver foco ao acionador.

## Processo para novas páginas

1. Criar a base com `npm run backoffice:page:new -- --id=... --title="..."`.
   O gerador cria o fragmento declarativo e o módulo de ciclo de vida; a rota
   só pode ser publicada após o registro em `BackofficePageRegistry`.
2. Implementar `mount(root, context)` e retornar uma função de descarte. O
   descarte remove listeners, timers, requests e overlays criados pela página.
3. Escolher somente classes oficiais e composições desta página; hooks de
   dados não podem ser usados como classes visuais.
4. Executar `npm run backoffice:contract:check`, `npm run tokens:check` e os
   testes da aplicação antes de solicitar revisão visual.
5. Validar desktop, tablet, mobile, teclado, carregamento, vazio, erro,
   criação, edição e detalhes. O shell nunca pode renderizar antes da resposta
   autorizada de `/backoffice/auth/me`.
6. Criar CSS local somente para um caso exclusivo do shell e registrar a
   razão. Necessidades genéricas pertencem ao repositório `fokus-styles`.

## Contrato imutável de ciclo de vida

`public/backoffice/assets/js/backoffice-router.js` é o único carregador de
fragmentos. Ele controla rota, histórico, permissões, cancelamento de
requisições, estilos exclusivos e descarte da página anterior. O contexto de
montagem contém `admin`, `permissions`, `api`, `router`, `root` e `signal`.

Os fragmentos legados continuam atendidos por uma ponte de compatibilidade
durante a migração, mas páginas novas não podem adicionar scripts inline. Todo
novo comportamento deve estar no módulo da página. Drawers portaled recebem
um proprietário de página e são removidos pelo ciclo de descarte, evitando IDs
duplicados após troca de rota.
