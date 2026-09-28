# Modelo de permissoes e perfis

## Objetivo

Definir como o Fokus Cloud concede, avalia, revoga e registra permissoes para
usuarios vinculados a empresas. O modelo deve servir ao Fokus Cloud e aos
produtos derivados, sem permitir que cada produto crie uma logica de
autorizacao incompatível com a plataforma base.

## Decisao inicial

O sistema adotara **RBAC com permissoes atomicas e escopo por empresa**:

- o perfil (`role`) agrupa permissoes;
- a permissao (`permission`) representa uma capacidade especifica;
- o vinculo usuario-empresa define o perfil valido naquele contexto;
- o produto e o modulo podem restringir ainda mais a permissao;
- possuir um perfil em uma empresa nao concede acesso a outra empresa;
- nenhuma permissao concede acesso a dados fora do escopo autorizado.

O modelo inicial nao tera permissoes individuais por usuario. Essa extensao
somente deve ser criada quando houver necessidade comprovada e documentada,
porque aumenta a complexidade de suporte, auditoria e previsibilidade.

## RBAC interno de plataforma

O Backoffice usa tabelas proprias `platform_roles`, `platform_permissions` e
`platform_role_permissions`. Os papeis `superadministrador` e
`administrador_comercial` recebem permissoes `platform.*`; essas permissoes
nunca sao concedidas por vinculos de empresa. A autorizacao ocorre no servidor
por endpoint, e a interface apenas oculta acoes indisponiveis.

## Conceitos

| Conceito | Definicao |
| --- | --- |
| Usuario | Identidade global que pode participar de uma ou mais empresas. |
| Empresa | Limite de isolamento dos dados e da administracao do cliente. |
| Vinculo | Relacao entre usuario e empresa, com status e perfil proprios. |
| Perfil | Conjunto nomeado de permissoes para uma funcao operacional. |
| Permissao | Capacidade atomica no formato `dominio.acao`. |
| Escopo | Contexto em que a permissao pode ser usada: empresa, produto, modulo ou registro. |
| Politica | Regra que combina identidade, vinculo, permissao, escopo e estado do recurso. |

## Nomenclatura de permissoes

As permissoes devem usar nomes estaveis, em minusculas, separados por pontos:

```text
<dominio>.<recurso>.<acao>
```

Exemplos:

```text
identity.users.view
identity.users.invite
identity.users.update-role
identity.users.suspend
company.settings.view
company.settings.update
law.cases.view
lead.opportunities.update
```

Regras:

- usar verbo de negocio explicito, sem depender do nome de uma tela;
- nao criar permissao generica como `admin.all` para usuarios clientes;
- nao usar nomes de banco, rotas ou componentes de interface como contrato;
- manter a mesma permissao quando a interface mudar;
- documentar cada permissao antes de habilita-la em um perfil;
- reservar permissoes de plataforma para o backoffice e nunca mistura-las com
  permissoes de empresa.

## Escopos

| Escopo | Uso | Regra |
| --- | --- | --- |
| `platform` | Operacao interna do Fokus Cloud. | Exige identidade interna separada e nao e concedido por perfil de empresa. |
| `company` | Configuracoes e recursos comuns da empresa. | Exige vinculo ativo na empresa ativa. |
| `unit` | Operacao de uma filial, departamento ou equipe. | Exige vinculo ativo associado a unidade ativa. |
| `product` | Funcionalidades do Fokus Law ou Fokus Lead. | Exige plano, produto habilitado e permissao do perfil. |
| `module` | Area especifica de um produto. | Exige produto habilitado e modulo liberado para a empresa. |
| `record` | Registro individual ou conjunto de registros. | Exige verificacao adicional de propriedade, equipe ou regra de negocio. |

O escopo mais amplo nunca deve ignorar uma restricao mais especifica. Por
exemplo, `law.cases.view` nao permite visualizar um caso arquivado se uma
regra do dominio juridico proibir esse acesso.

## Perfis base

| Perfil | Finalidade | Permissoes de administracao |
| --- | --- | --- |
| `admin` | Responsavel pela empresa e sua governanca operacional. | Pode administrar usuarios, vinculos, configuracoes e assinatura; pode transferir a administracao conforme fluxo formal. |
| `gestor` | Responsavel por operacoes delegadas dentro da empresa. | Pode operar modulos autorizados; nao altera usuarios, assinatura ou administracao. |
| `usuario` | Usuario operacional com acesso minimo necessario. | Nao administra empresa, usuarios, configuracoes sensiveis ou permissoes. |

Os nomes tecnicos dos perfis sao contratos internos e devem permanecer em
ingles e no singular. Os nomes exibidos na interface podem ser traduzidos.

## Catalogo inicial

| Permissao | `admin` | `gestor` | `usuario` | Escopo |
| --- | --- | --- | --- | --- |
| `company.portal.access` | Sim | Sim | Sim | `company` |
| `company.profile.view` | Sim | Sim | Sim | `company` |
| `company.profile.update` | Sim | Nao | Nao | `company` |
| `company.subscription.view` | Sim | Nao | Nao | `company` |
| `identity.users.view` | Sim | Unidade autorizada | Nao | `company`/`unit` |
| `identity.users.invite` | Sim | Unidade autorizada | Nao | `company`/`unit` |
| `identity.users.update-role` | Sim | Nao | Nao | `company` |
| `identity.users.suspend` | Sim | Unidade autorizada | Nao | `company`/`unit` |
| `identity.users.remove` | Sim | Unidade autorizada | Nao | `company`/`unit` |
| `identity.admin.transfer` | Sim | Nao | Nao | `company` |
| `audit.events.view` | Sim | Nao | Nao | `company` |
| `product.module.use` | Conforme produto e plano | Conforme produto e plano | Conforme produto e plano | `product`/`module` |

`product.module.use` e uma capacidade conceitual. Cada modulo deve publicar
suas permissoes especificas antes de entrar em producao; permissoes amplas nao
devem substituir regras de leitura, criacao, alteracao e exclusao do dominio.

O Fokus Law v1 publica perfis e permissoes proprios por unidade juridica em
[Seguranca e permissoes do Fokus Law](fokus-law-security-and-permissions.md).
Essas permissoes complementam os perfis base da empresa e devem respeitar
assinatura ativa, modulo contratado, unidade ativa e sigilo processual.

## Algoritmo de autorizacao

Uma requisicao protegida somente pode ser autorizada quando todas as
condicoes abaixo forem verdadeiras:

1. A sessao representa um usuario autenticado.
2. O usuario esta ativo e com e-mail confirmado quando a acao exigir isso.
3. Existe uma empresa ativa selecionada.
4. Existe vinculo ativo entre usuario e empresa.
5. O perfil do vinculo possui a permissao solicitada.
6. A unidade ativa esta autorizada para o vinculo, quando aplicavel.
7. O produto e o modulo estao habilitados para a empresa, quando aplicavel.
8. O plano permite o recurso, quando aplicavel.
9. A regra do registro permite a operacao, quando houver escopo de registro.
10. Nao existe bloqueio por estado do recurso ou por regra de seguranca.

Qualquer falha resulta em negacao. A aplicacao nao deve revelar ao usuario se
a falha ocorreu por inexistencia do recurso ou por falta de permissao quando
essa informacao facilitar enumeracao de dados.

## Precedencia de regras

As regras devem ser avaliadas nesta ordem:

1. autenticacao;
2. estado da conta;
3. empresa ativa e vinculo;
4. perfil e permissao;
5. unidade e escopo organizacional;
6. produto, modulo e plano;
7. escopo do registro;
8. regras de negocio e bloqueios explicitos.

Uma negacao em qualquer etapa prevalece sobre uma concessao anterior. O
codigo nao deve contornar essa ordem usando verificacoes isoladas em
controladores ou componentes de interface.

## Responsabilidades tecnicas

| Camada | Responsabilidade |
| --- | --- |
| Middleware | Garantir autenticacao e contexto de empresa ativa. |
| Policy ou authorization gate | Responder se uma acao e permitida para o recurso. |
| Service ou action | Executar a operacao depois da autorizacao e aplicar transacao. |
| Repository ou query | Reforcar filtro por empresa para evitar acesso cruzado. |
| Interface | Ocultar acoes indisponiveis, sem ser a autoridade final. |
| Auditoria | Registrar concessoes e negacoes relevantes, sem dados sensiveis desnecessarios. |

Autorizacao deve ser aplicada no servidor. A interface pode melhorar a
experiencia, mas nunca e considerada controle de seguranca.

## Evolucao prevista

O modelo pode evoluir nesta ordem, conforme evidencias do produto:

1. permissoes adicionais por produto e modulo;
2. regras por equipe ou unidade dentro da empresa (já aplicadas ao Fokus Law nesta etapa);
3. escopo por registro;
4. perfis personalizados por produto e unidade (já aplicados ao Fokus Law; demais produtos seguem pendentes);
5. permissoes individuais, somente se os perfis e escopos anteriores nao
   resolverem o caso de negocio.

Cada extensao deve preservar compatibilidade com os perfis base, registrar
uma decisao arquitetural e incluir casos positivos e negativos de autorizacao.

## Implementacao por unidade no Fokus Law

O Law mantém o vínculo da empresa (`company_memberships`) e acrescenta uma
atribuição por setor em `law_unit_memberships`. Cada atribuição aponta para um
`law_access_role` do mesmo par empresa/setor; o perfil agrega permissões do
catálogo compartilhado `customer_permissions` pela tabela
`law_access_role_permissions`. O catálogo possui `product_code=law`; o RBAC
interno do Backoffice continua isolado nas tabelas `platform_*`.

Os perfis protegidos por setor são `unit_admin`, `chief_clerk`, `operator` e
`viewer`. Podem existir perfis personalizados no escopo da empresa e do setor,
mas os perfis protegidos não podem ser editados nem removidos. O admin global
da empresa tem acesso global ao Law. `unit_admin` pode administrar perfis
personalizados no setor dentro das próprias permissões; `chief_clerk` pode
atribuir perfis que já pode conceder, sem editar conjuntos de permissões.
Nenhum gestor local pode conceder permissões além das próprias ou criar e
atribuir `unit_admin`.

A migração associa `gestor` a `chief_clerk` e `usuario` a `operator` em cada
setor Law ativo existente. Novos setores e usuários não recebem atribuições
implícitas. O legado de empresa continua sendo usado por Cloud e Lead até a
migração própria desses produtos.

## Requisitos de auditoria

Devem ser registrados, no minimo:

- ator, empresa, permissao solicitada e resultado;
- alvo da operacao, quando existir;
- data e hora em UTC;
- origem tecnica suficiente para investigacao;
- motivo ou contexto quando houver elevacao, transferencia ou bloqueio.

Logs nao devem registrar senhas, tokens, CPF completo ou dados de negocio
desnecessarios.

## Testes obrigatorios

- Cada perfil acessa somente as permissoes previstas na matriz.
- Uma permissao concedida em uma empresa nao funciona em outra.
- Vinculo pendente, suspenso ou removido resulta em negacao.
- Interface sem botao de uma acao nao substitui a verificacao no servidor.
- Produto ou modulo desabilitado bloqueia o acesso mesmo com perfil valido.
- Plano sem o recurso bloqueia o acesso mesmo com permissao de perfil.
- Regra de registro pode negar uma operacao permitida no nivel do modulo.
- Alteracao de perfil invalida ou atualiza o acesso conforme a politica de sessao.
- Suspensao por gestor bloqueia somente o vinculo na unidade administrada.
- Transferencia de admin nao cria dois administradores ativos.

## Dependencias

- [Politica de controle de acesso](access-control-policy.md)
- [Requisitos do modulo identidade e acesso](../05-requirements/identity-and-access.md)
- [Fluxo de identidade e acesso](../03-architecture/identity-and-access-flow.md)
- [Requisitos de seguranca](security-requirements.md)

## Pendencias

- Definir se o plano sera avaliado no mesmo servico de autorizacao ou em uma
  camada de habilitacao de produto.
- Definir quais permissoes especificas serao publicadas pelo primeiro modulo do
  Fokus Lead.
- Definir politica de cache e invalidacao depois de alteracao de perfil,
  vinculo, plano ou produto.
