# Fokus Lead

## Papel

Fokus Lead sera o produto derivado voltado a rotinas imobiliarias, CRM, leads e automacoes comerciais.

## Paleta visual do modelo publico

A pagina publica do Fokus Lead usa seis cores principais. Os tokens abaixo sao
definidos em `public/marketing/products/fokus-lead.css` e devem ser
reutilizados em vez de novos valores locais:

| Token | Hex | Definicao de uso |
| --- | --- | --- |
| `--lead-navy` | `#102a43` | Cor primaria para titulos, botoes e superficies de navegacao. |
| `--lead-navy-deep` | `#071b2d` | Fundo mais profundo para hero, navegacao e rodape. |
| `--lead-blue` | `#2f638c` | Azul de apoio para variacoes de superficie e informacao. |
| `--lead-terracotta` | `#c96f4a` | Acento principal para botoes, indicadores e chamadas de acao. |
| `--lead-terracotta-light` | `#e9a27f` | Acento claro para marca, links e detalhes sobre fundos escuros. |
| `--lead-cream` | `#f5efe7` | Superficie clara principal do modelo. |

Os seis quadrados exibidos no rodape seguem essa mesma ordem. Tons de apoio
como `--lead-ink` (`#183047`) e `--lead-mist` (`#dbe7ed`) continuam
reservados para texto e superficies auxiliares, mas nao fazem parte da faixa
de seis amostras do rodape.

## Escopo aprovado em 15/10/2026

O primeiro ciclo definiu gestao comercial de vendas e locacoes ate o fechamento
por contrato assinado, para autonomos (One) e imobiliarias/equipes (Team).
Inclui carteira de imoveis e empreendimentos/unidades, ofertas independentes por
finalidade, CRM com funil configuravel, distribuicao manual/rodizio opcional,
agenda interna, propostas versionadas, reservas temporarias, registro de comissoes
e rateios e catalogo publico opcional por empresa.

O banco-alvo do Lead sera um novo projeto PostgreSQL exclusivo no Supabase.
Fotos e documentos ficarao em servidor proprio. Identidade, empresas, vinculos,
permissoes basicas e catalogo comercial permanecem sob autoridade do Cloud.
Law e Lead nao compartilham nem consultam seus bancos operacionais.

Cada corretor acessa seus atendimentos; o gestor acompanha sua empresa. A carteira
comercial de imoveis e consultavel pela equipe, mas a edicao e os contatos dos
proprietarios ficam restritos ao responsavel e gestores. O admin global tem dados
administrativos e acesso operacional temporario autorizado pela empresa, auditado.

Consulte as vinte decisoes, o modelo SQL inicial, a matriz de acesso e as pendencias
em [Estrutura inicial PostgreSQL](../06-data/fokus-lead/README.md).

## Estado da implementacao

O banco exclusivo Fokus Lead foi criado no Supabase em 15/10/2026, regiao Sao
Paulo, com 38 tabelas, RLS e dez migrations aplicadas e verificadas. Isso nao
equivale a modulos funcionais liberados. A integracao Laravel e o servidor de
arquivos ainda precisam ser configurados. Nao ha administracao de alugueis, cobrancas ou
repasses nesta primeira versao. Precos, limites e publicacao de planos continuam
dependendo do catalogo oficial do Cloud, sem valores simulados.

## Regra

Funcionalidades imobiliarias e comerciais devem ficar neste produto, salvo quando forem recursos comuns reutilizaveis por outros produtos.
