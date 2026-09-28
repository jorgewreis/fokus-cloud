# Fokus Cloud

Fokus Cloud e uma plataforma SaaS multiempresa criada para sustentar produtos digitais especializados. O sistema base concentra identidade, acesso, empresas, assinaturas, catalogo comercial, vouchers, auditoria e governanca. Sobre essa base, produtos derivados podem evoluir de forma independente, preservando uma experiencia comum de conta, cobranca e administracao.

## Visao do produto

A plataforma foi desenhada para crescer por modulos. Em vez de criar sistemas isolados para cada mercado, o Fokus Cloud atua como fundacao comum para produtos verticais.

| Camada | Responsabilidade |
| --- | --- |
| Fokus Cloud | Plataforma base, autenticação, empresas, usuarios, permissoes, assinaturas, catalogo, vouchers, auditoria e backoffice. |
| Fokus Styles | Framework CSS open source do portfolio, com tokens, componentes, temas e documentacao. |
| Fokus Law | Produto juridico pago, voltado a rotinas de advocacia, cartorios, audiencias, expedientes, prazos e documentos. |
| Fokus Lead | Produto imobiliario pago, voltado a CRM, leads, imoveis, corretores, funil comercial, distribuicao e automacoes. |

## Principio de arquitetura

Tudo que e comum a qualquer produto pertence ao Fokus Cloud. Tudo que depende do dominio juridico pertence ao Fokus Law. Tudo que depende do dominio imobiliario pertence ao Fokus Lead.

Essa divisao permite que uma mesma empresa ou usuario utilize mais de um produto, mantendo uma unica conta, uma camada comum de cobranca e regras consistentes de permissao.

```text
Fokus Cloud
  Core
    Empresas
    Usuarios
    Perfis e permissoes
    Assinaturas
    Catalogo comercial
    Vouchers
    Auditoria
    Backoffice

    Portfolio
    Fokus Styles
    Fokus Law
      Modulos juridicos

    Fokus Lead
      Modulos imobiliarios
```

## Stack atual

- PHP 8.3+
- Laravel 13
- MySQL/MariaDB
- Vite
- Tailwind CSS
- PHPUnit
- GitHub Actions para deploy

## Estrutura principal

| Caminho | Conteudo |
| --- | --- |
| `app/` | Codigo Laravel da aplicacao. |
| `routes/` | Rotas web e API. |
| `database/migrations/` | Estrutura de banco da plataforma. |
| `database/seeders/` | Dados iniciais de catalogo, roles e produtos. |
| `public/` | Entrada publica, interfaces HTML e assets publicados. |
| `resources/` | Entradas de CSS/JS e views Laravel. |
| `docs/` | Documentacao funcional, comercial e tecnica. |
| `fokus-project.json` | Manifesto de identidade, URLs, tipo, versao e status do repositorio. |
| `.github/workflows/` | Automacao de deploy. |

## Execucao local

Requisitos gerais:

- PHP compativel com o projeto
- Composer instalado globalmente
- Node.js e npm
- MySQL ou MariaDB

Fluxo sugerido:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Para desenvolvimento integrado, o projeto tambem possui script Composer:

```bash
composer run dev
```

## Estado do projeto

Este repositorio esta em desenvolvimento ativo. A base atual ja contempla autenticacao, empresa ativa, isolamento por vinculo, catalogo comercial, backoffice, assinaturas, vouchers, auditoria, testes e deploy inicial.

A prioridade arquitetural e manter o Fokus Cloud como plataforma comum e evoluir os produtos derivados por modulos independentes.

As fronteiras entre os repositorios estao definidas em
[`docs/03-architecture/repository-boundaries.md`](docs/03-architecture/repository-boundaries.md).

## Documentacao

A documentacao principal esta em [`docs/`](docs/README.md). O ponto de partida recomendado e [`docs/03-architecture/system-architecture.md`](docs/03-architecture/system-architecture.md). Para novos arquivos, pastas e modulos, consulte [`docs/02-governance/naming-conventions.md`](docs/02-governance/naming-conventions.md).

## Licenca

Este projeto possui licenca proprietaria. O codigo e disponibilizado publicamente apenas para consulta, avaliacao e acompanhamento. O uso, copia, modificacao, distribuicao, sublicenciamento ou exploracao comercial dependem de autorizacao expressa do titular.

Consulte [`LICENSE.md`](LICENSE.md).
