# Politica de controle de acesso

## Objetivo

Definir regras de autorizacao para usuarios, empresas, vinculos e perfis no Fokus Cloud.

## Principio central

Nenhum dado de empresa pode ser lido, alterado ou excluido sem validar usuario autenticado, empresa ativa, vinculo ativo e permissao suficiente.

## Perfis base

| Perfil | Responsabilidade | Restricao principal |
| --- | --- | --- |
| `admin` | Administra empresa, usuarios, assinatura e transferencia de administracao. | Existe apenas um admin ativo por empresa. |
| `gestor` | Opera modulos permitidos pelo produto e administra usuarios da propria unidade. | Nao altera perfis, administra assinatura nem transfere administracao. |
| `usuario` | Usa recursos operacionais permitidos. | Nao gerencia usuarios ou configuracoes sensiveis. |

## Status de vinculo

| Status | Permite acesso? | Uso |
| --- | --- | --- |
| `pending` | Nao | Convite criado e ainda nao aceito. |
| `active` | Sim | Vinculo valido para a empresa. |
| `suspended` | Nao | Acesso bloqueado temporariamente. |
| `removed` | Nao | Vinculo removido, com historico preservado. |

## Matriz inicial de permissoes

| Acao | Admin | Gestor | Usuario |
| --- | --- | --- | --- |
| Acessar portal da empresa | Sim | Sim | Sim |
| Ver perfil e dados basicos da empresa | Sim | Sim | Sim |
| Ver assinatura ativa | Sim | Nao | Nao |
| Gerenciar usuarios | Sim | Apenas na propria unidade | Nao |
| Alterar perfil de usuario | Sim | Nao | Nao |
| Suspender usuario | Sim, apenas no vinculo com a empresa ativa | Apenas na propria unidade | Nao |
| Restaurar usuario | Sim, apenas no vinculo com a empresa ativa | Apenas na propria unidade | Nao |
| Transferir administracao | Sim | Nao | Nao |
| Acessar backoffice interno | Nao | Nao | Nao |
| Usar modulo do produto contratado | Conforme plano | Conforme plano | Conforme plano |

A suspensao ou restauracao feita pela administracao da empresa altera somente o
vinculo com a empresa ativa. Ela nao suspende nem restaura a conta global do
usuario e nao altera vinculos com outras empresas. A conta global segue a
[politica de seguranca da conta](account-security-policy.md).

O catalogo atomico, os escopos, a ordem de avaliacao e os testes normativos
estao definidos em [Modelo de permissoes e perfis](permission-model.md).

Para o Fokus Law v1, a autorizacao deve validar tambem assinatura ativa do
produto, unidade Law ativa, vinculo Law ativo, modulo contratado e regra de
sigilo processual, conforme [Seguranca e permissoes do Fokus Law](fokus-law-security-and-permissions.md).

## Backoffice

O backoffice interno nao deve usar os perfis de empresa como autorizacao suficiente. Acesso administrativo interno precisa de regra propria, separada dos usuarios clientes.

Na v1, o Backoffice nao acessa dados operacionais do Fokus Law. Ele pode tratar
informacoes comerciais, assinatura, billing, vouchers, alertas tecnicos
agregados e auditoria da plataforma, sem visualizar processos, contatos,
partes, expedientes, prazos, pendencias ou conteudo sigiloso do cliente.

## Controles obrigatorios

- Middleware de autenticacao.
- Middleware ou camada equivalente para empresa ativa.
- Verificacao de vinculo ativo.
- Policies para recursos sensiveis.
- Auditoria para eventos administrativos.
- Mascaramento de dados pessoais em historicos quando nao houver necessidade operacional.

## Testes minimos

- Usuario de uma empresa nao acessa dados de outra.
- Usuario suspenso nao acessa portal.
- Usuario removido nao recupera acesso sem restauracao formal.
- Gestor nao altera perfis nem usuarios fora da propria unidade.
- Usuario comum nao acessa administracao.
- Empresa nao fica sem admin apos transferencia.
- Empresa nao possui dois admins ativos apos transferencia.
