# Convencao de nomes e pastas

## Objetivo

Este documento define o padrao oficial de nomenclatura do Fokus Cloud. A regra vale para novos modulos, documentos, paginas, assets, rotas e componentes internos do projeto.

O objetivo e manter o crescimento organizado desde o inicio, preservando a separacao entre plataforma e os produtos do portfolio.

## Principio de decisao

Ao escolher entre alternativas tecnicas, o projeto deve priorizar o que for melhor, mais correto e mais sustentavel para a arquitetura do Fokus Cloud, mesmo quando a opcao exigir mais trabalho no curto prazo.

Facilidade de implementacao pode ser considerada, mas nao deve prevalecer sobre manutencao, clareza, seguranca, escalabilidade, isolamento de dados e consistencia entre a plataforma base e os produtos derivados.

## Regra principal

| Contexto | Padrao |
| --- | --- |
| Pastas do projeto | `kebab-case` quando forem criadas pelo projeto. |
| Arquivos HTML, CSS, JS e Markdown | `kebab-case`. |
| Classes PHP | `PascalCase`, seguindo o padrao Laravel. |
| Metodos PHP | `camelCase`, seguindo o padrao Laravel. |
| Tabelas do banco | `snake_case`, no plural. |
| Colunas do banco | `snake_case`. |
| Codigos internos de produto, plano e modulo | `kebab-case` ou slug estavel. |
| Rotas publicas | portugues claro e estavel, quando forem voltadas ao usuario final. |
| APIs | ingles ou dominio tecnico curto, mantendo consistencia por grupo. |

## Estrutura de alto nivel

```text
app/                  Codigo Laravel da aplicacao
bootstrap/            Inicializacao Laravel
config/               Configuracoes Laravel
database/             Migrations, factories e seeders
deploy/               Arquivos auxiliares de deploy
docs/                 Documentacao do projeto
public/               Entrada publica da aplicacao
resources/            Entradas Vite e recursos Laravel
routes/               Rotas web, API e console
tests/                Testes automatizados
```

## Documentacao

A pasta `docs` deve ser organizada por dominio:

```text
docs/
  README.md
  01-overview/
  02-governance/
  03-architecture/
  04-products/
  05-requirements/
  06-data/
  07-security/
  08-commercial/
  09-operations/
```

Use as pastas numeradas para manter ordem visual estavel. O numero define a ordem de leitura; o nome define o dominio documental.

Produtos com documentacao especifica podem receber subpastas dentro das areas numeradas quando houver volume suficiente:

```text
docs/04-products/law/
docs/04-products/lead/
```

## Public

A pasta `public` deve separar arquivos por area de uso:

```text
public/
  index.html
  index.php
  assets/
    css/
    js/
    images/
  auth/
  portal/
  backoffice/
  marketing/
    products/
    subscriptions/
```

Responsabilidades:

| Pasta | Uso |
| --- | --- |
| `public/assets` | Assets compartilhados por telas publicas, portal e produtos. |
| `public/auth` | Telas de cadastro, verificacao, senha e convites. |
| `public/portal` | Telas do cliente e administracao da empresa. |
| `public/backoffice` | Backoffice interno da plataforma. |
| `public/marketing` | Paginas institucionais, portfolio e fluxos comerciais do Cloud. |

## Icones de interface

O formato padrao para icones de interface no projeto e PNG. Novos icones devem ser
baixados como PNG diretamente da fonte oficial do asset (por exemplo, o botao PNG do
Streamline), nunca redesenhados, gerados, convertidos de SVG ou substituidos por um
desenho em CSS. Devem ser armazenados em uma pasta `icons/` da area que os consome e
devem preservar o nome oficial do asset, incluindo a familia do Streamline quando
aplicavel.

| Regra | Padrao |
| --- | --- |
| Formato | `.png` para icones de interface, sem uso de formato vetorial alternativo como substituto. |
| Localizacao compartilhada | `public/assets/icons/`. |
| Localizacao do Backoffice | `public/backoffice/assets/icons/`. |
| Origem | Baixar o PNG oficial na pagina do fornecedor; registrar a URL de origem quando o icone for introduzido ou substituido. |
| Referencia | Usar o caminho do arquivo PNG; nao reconstruir, rasterizar ou converter o desenho com CSS, fonte, canvas ou SVG inline. |
| Acessibilidade | Imagens decorativas devem usar `alt=""` e `aria-hidden="true"`; imagens informativas devem ter texto alternativo. |

Os icones de navegacao e tema do Fokus Styles seguem essa regra e usam os PNGs oficiais
do Streamline em `public/assets/icons/`:

| Arquivo | Fonte oficial |
| --- | --- |
| `Keyboard-Arrow-Left--Streamline-Ultimate.png` | https://www.streamlinehq.com/icons/download/keyboard-arrow-left--9024 |
| `Keyboard-Arrow-Right--Streamline-Ultimate.png` | https://www.streamlinehq.com/icons/download/keyboard-arrow-right--9024 |
| `Keyboard-Arrow-Previous--Streamline-Ultimate.png` | https://www.streamlinehq.com/icons/download/keyboard-arrow-previous--9024 |
| `Keyboard-Arrow-Next--Streamline-Ultimate.png` | https://www.streamlinehq.com/icons/download/keyboard-arrow-next--9024 |
| `Light-Mode-Sunny--Streamline-Ultimate.png` | https://www.streamlinehq.com/icons/download/light-mode-sunny--9176 |
| `Do-Not-Disturb-Sleep-Mode--Streamline-Ultimate.png` | https://www.streamlinehq.com/icons/download/do-not-disturb-sleep-mode--9252 |

Antes de adicionar outro icone, confirmar no fornecedor que o download selecionado e
PNG e manter a referencia da pagina oficial no registro da mudanca.

Graficos e diagramas devem usar elementos HTML e CSS do projeto, mantendo o padrao
PNG exclusivo para icones de interface reutilizaveis.

## Produtos

Os projetos do portfolio devem usar codigos tecnicos estaveis:

| Produto | Codigo |
| --- | --- |
| Fokus Cloud | `cloud` |
| Fokus Styles | `styles` |
| Fokus Law | `law` |
| Fokus Lead | `lead` |

Arquivos de portfolio devem usar o prefixo do projeto quando estiverem em uma pasta compartilhada:

```text
public/marketing/products/fokus-styles.html
```

As paginas comerciais de Fokus Law e Fokus Lead pertencem aos seus repositorios
proprios. O Fokus Cloud exibe o portfolio e o status de disponibilidade, sem
duplicar paginas de produto ou checkout.

## Prototipos visuais

Prototipos temporarios nao sao mantidos neste repositorio. A interface de
produto deve viver nas paginas e componentes definitivos, com estilos
compartilhados e estados funcionais.

## Criterios para novos modulos

Todo novo modulo deve declarar:

- produto ao qual pertence;
- codigo tecnico estavel;
- rotas publicas e APIs;
- pasta de documentacao;
- arquivos de interface;
- tabelas e migrations;
- permissoes;
- relacao com assinatura ou catalogo, quando houver;
- testes minimos de acesso e isolamento.

## Regra de descarte

Arquivos temporarios, copias antigas, experimentos e prototipos visuais devem
ser removidos depois que suas decisoes forem incorporadas ao produto.
