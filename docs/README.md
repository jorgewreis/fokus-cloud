# Documentacao do Fokus Cloud

Esta pasta concentra a documentacao funcional, comercial e tecnica do Fokus Cloud e de seus produtos derivados.

As fronteiras entre os repositorios independentes do ecossistema estao definidas
em [Fronteiras dos repositorios Fokus](03-architecture/repository-boundaries.md).

## Visao geral

O Fokus Cloud e a plataforma base. Ele concentra autenticacao, empresas, usuarios, permissoes, catalogo comercial, assinaturas, vouchers, auditoria, billing, backoffice e governanca.

Sobre essa base, produtos derivados podem evoluir por modulos independentes:

| Produto | Papel |
| --- | --- |
| Fokus Cloud | Plataforma comum e camada de administracao. |
| Fokus Law | Sistema derivado para rotinas juridicas, cartorarias e de advocacia. |
| Fokus Lead | Sistema derivado para rotinas imobiliarias, CRM, leads e automacoes comerciais. |

## Estrutura documental

| Pasta | Finalidade |
| --- | --- |
| [`01-overview`](01-overview/README.md) | Visao executiva, contexto, glossario e mapa geral do projeto. |
| [`02-governance`](02-governance/README.md) | Diretrizes, convencoes, decisoes, fluxo de perguntas e governanca documental. |
| [`03-architecture`](03-architecture/README.md) | Arquitetura do sistema, fronteiras, modulos, portais e decisoes arquiteturais. |
| [`04-products`](04-products/README.md) | Produtos derivados, linhas de produto, escopo modular e roadmap por produto. |
| [`05-requirements`](05-requirements/README.md) | Requisitos funcionais, nao funcionais, regras de negocio e criterios de aceite. |
| [`06-data`](06-data/README.md) | Modelo relacional, dicionario de dados, isolamento, migrations e seeds. |
| [`07-security`](07-security/README.md) | Seguranca, privacidade, LGPD, ameacas, requisitos e controles. |
| [`08-commercial`](08-commercial/README.md) | Catalogo comercial, planos, precos, vouchers, ofertas e assinatura. |
| [`09-operations`](09-operations/README.md) | Ambientes, deploy, qualidade, testes, releases, monitoramento e operacao. |
| [`10-releases`](10-releases/README.md) | Pacotes de entrega por versao, marcos, checklists, aceite e homologacao. |

## Documentos de partida

- [Visao do projeto](01-overview/project-overview.md)
- [Diretrizes de documentacao e codificacao](02-governance/development-guidelines.md)
- [Convencao de nomes e pastas](02-governance/naming-conventions.md)
- [Padrao de documentacao](02-governance/documentation-standards.md)
- [Diretrizes de perguntas e decisoes](02-governance/question-guidelines.md)
- [Arquitetura do sistema](03-architecture/system-architecture.md)
- [Governanca visual com Fokus Styles](03-architecture/fokus-styles-ui-governance.md)
- [Inventario de CSS do Fokus Styles, Backoffice e Fokus Law](03-architecture/fokus-styles-css-inventory.md)
- [Fluxo de identidade e acesso](03-architecture/identity-and-access-flow.md)
- [Catalogo de modulos](04-products/module-catalog.md)
- [Requisitos do modulo identidade e acesso](05-requirements/identity-and-access.md)
- [Template de requisitos de modulo](05-requirements/module-requirements-template.md)
- [Modelo relacional](06-data/relational-model.md)
- [Politica de controle de acesso](07-security/access-control-policy.md)
- [Seguranca e dados](07-security/security-and-data.md)
- [Catalogo comercial](08-commercial/catalog.md)
- [Ambientes e deploy](09-operations/environments-and-deploy.md)
- [Release 0.1 - Backoffice Admin](10-releases/v0.1-admin-backoffice/README.md)

## Principios centrais

- A plataforma base nao deve receber regras especificas de dominio juridico ou imobiliario.
- O Fokus Law deve concentrar funcionalidades juridicas e cartorarias.
- O Fokus Lead deve concentrar funcionalidades imobiliarias e comerciais.
- O banco de dados e a fonte oficial dos produtos, planos, funcionalidades, precos e composicoes comerciais.
- O frontend nao deve manter uma segunda fonte manual para dados do catalogo.
- Cada novo modulo deve declarar seu produto, suas permissoes, seus impactos comerciais e seus criterios minimos de teste.

## Estado da documentacao

As regras de negocio aprovadas neste conjunto representam o modelo alvo. Cada documento pode identificar o que ja existe no codigo e o que ainda precisa ser implementado.
