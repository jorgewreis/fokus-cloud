# Operacao do Fokus Law

## Objetivo

Definir a operacao diaria da unidade Law na v1, incluindo dashboard, fila de
trabalho, alertas, rotinas por perfil, suporte, incidentes, retencao e metricas.

Este documento complementa:

- [Fokus Law](../04-products/fokus-law.md);
- [Requisitos do Fokus Law](../05-requirements/fokus-law.md);
- [Modelo de dados do Fokus Law](../06-data/fokus-law-data-model.md);
- [Seguranca e permissoes do Fokus Law](../07-security/fokus-law-security-and-permissions.md);
- [Monitoramento e suporte](monitoring-and-support.md).

## Principios operacionais

- A unidade Law e o centro da operacao cartoraria.
- Dashboard e fila de trabalho devem funcionar como uma tela unica de comando.
- Gestores e chefia visualizam mais indicadores.
- Servidores visualizam mais itens acionaveis.
- Visualizador nao possui rotina operacional propria documentada na v1.
- Backoffice presta suporte orientativo sem acessar dados juridicos.
- No módulo Processos do Judiciário Criminal, falhas do Datajud aparecem no próprio processo e em seu histórico; não geram notificações nem fila de revisão. Consulte [Operação do Datajud](law-case-datajud.md).
- Alertas devem chamar atencao apenas para condicoes que exigem acao real.

## Tela inicial

A tela inicial da unidade deve combinar dashboard operacional e fila de trabalho.

Estrutura recomendada:

- cards gerenciais no topo;
- fila de trabalho abaixo;
- abas ou filtros por tipo de item;
- filtros por periodo;
- filtros por responsavel, setor, status, prioridade, tag e nivel de sigilo
  quando aplicavel.

Enfase por perfil:

| Perfil | Enfase da tela inicial |
| --- | --- |
| `unit_admin` | Dashboard, governanca, suporte, produtividade e alertas. |
| `chief_clerk` | Dashboard, filas criticas, redistribuicao de trabalho e sigilo. |
| `operator` | Fila de trabalho, itens atribuidos, vencidos e urgentes. |
| `viewer` | Consulta permitida, sem rotina operacional propria na v1. |

## Fila de trabalho

A fila de trabalho deve ser unica, com abas ou filtros por tipo.

Tipos da v1:

- expedicoes;
- cartas recebidas;
- tarefas;
- prazos;
- pendencias.
- audiencias.

Cartas recebidas devem aparecer como fila/visao de processos cuja classe seja
carta recebida. Elas nao sao expediente separado.

Expedicoes devem aparecer em fila unica com filtros por tipo, como oficio,
mandado, carta precatoria, carta rogatoria, carta de ordem, edital, guia de
execucao e ato ordinatorio.

Tarefas devem aparecer na mesma tela operacional, preservando a distincao entre
trabalho a cumprir e documento expedido. Quando houver vinculo, a fila deve
permitir navegar da tarefa para a expedicao e da expedicao para a tarefa.

Processos devem possuir visao propria de Gestao de Processos, exibida no menu
como `Processos`, com classe, assuntos/artigos, prioridade, nivel de sigilo,
tags, autuacao, distribuicao, contatos/partes, Datajud e linha do tempo.

Contatos devem possuir visao propria de Gestao de Contatos, exibida no menu
como `Contatos`, sem usar o termo Agenda. A operacao deve permitir localizar
pessoas, advogados, instituicoes, orgaos, unidades externas, enderecos e canais
para vinculo com processos, expedicoes e tarefas.

## Ordenacao da fila

A ordenacao padrao deve ser:

1. itens vencidos;
2. itens urgentes;
3. itens com vencimento mais proximo;
4. itens mais antigos.

Essa ordenacao deve ser aplicada de forma consistente nas abas da fila, com
possibilidade de filtros adicionais sem esconder vencidos indevidamente.

## Alertas operacionais

Alertas operacionais da v1:

- prazo vencido;
- prazo a vencer;
- retorno, cumprimento ou conferencia de expedicao pendente;
- solicitacao de acesso sigiloso pendente.

Nao fazem parte da v1:

- alerta de expediente parado;
- alerta operacional de erro Datajud.

## Regras de alertas

### Prazo vencido

Gerar alerta quando prazo ou tarefa com data limite ultrapassar o vencimento sem
estar concluido, cancelado ou encerrado.

### Prazo a vencer

Gerar alerta quando faltarem 3 dias corridos para o vencimento.

### Retorno, cumprimento ou conferencia de expedicao pendente

Gerar alerta conforme prazo configuravel por expedicao.

Esse prazo deve ser informado no proprio cadastro ou acompanhamento da
expedicao e deve permitir que tipos mais urgentes ou mais complexos tenham
tratamento diferente.

### Solicitacao de acesso sigiloso pendente

Gerar alerta para administrador da unidade e chefe/escrivao sempre que houver
solicitacao aberta de acesso a processo sigiloso.

O alerta nao deve expor dados sensiveis do processo. Deve indicar apenas que ha
solicitacao pendente para analise.

## Status dos alertas

| Status | Significado |
| --- | --- |
| `open` | Alerta aberto e ainda nao tratado. |
| `acknowledged` | Alerta reconhecido por usuario autorizado. |
| `resolved` | Condicao deixou de existir. |
| `dismissed` | Alerta descartado quando aplicavel. |

Alertas devem ser resolvidos automaticamente quando a condicao deixar de
existir. Usuario autorizado pode apenas reconhecer ou descartar quando a regra
do alerta permitir.

Exemplos:

- prazo concluido resolve alerta de prazo vencido;
- expedicao retornada resolve alerta de retorno pendente;
- tarefa de cumprimento concluida resolve alerta de cumprimento pendente;
- solicitacao de sigilo aprovada, recusada ou cancelada resolve alerta de
  solicitacao pendente.

## Datajud na operacao

Datajud deve operar de forma automatica quando processo for criado ou
movimentado.

Erros Datajud nao devem aparecer em interface operacional, fila, dashboard ou
alerta do usuario. Quando houver erro, retorno vazio ou falha em processo
sigiloso, o sistema deve apenas registrar tecnicamente de forma sanitizada,
respeitando tentativas limitadas e sem bloquear a operacao interna.

Dados oficiais sincronizados pelo Datajud devem aparecer separados dos dados
operacionais internos da unidade. Divergencias relevantes devem compor a linha
do tempo ou historico tecnico sem virar alerta operacional obrigatorio na v1.

## Linha do tempo do processo

A linha do tempo do processo deve consolidar movimentacoes oficiais, tarefas,
expedicoes, prazos, pendencias, alteracoes de contatos/partes, mudancas de
prioridade, tags, nivel de sigilo e auditoria relevante.

Essa visao deve respeitar permissao e sigilo. Usuario sem autorizacao nao pode
usar linha do tempo para inferir dados identificaveis de processo protegido.

## Dashboard v1

Cards gerenciais minimos:

- expedicoes pendentes de envio;
- expedicoes sem retorno;
- tarefas operacionais abertas;
- tarefas geradas por expedicoes pendentes;
- cartas recebidas aguardando cumprimento;
- cartas recebidas aguardando devolucao;
- processos por prioridade;
- processos por tag;
- processos por nivel de sigilo;
- prazos vencidos;
- prazos a vencer;
- produtividade do usuario;
- produtividade por usuario;
- produtividade por setor.

Filtros de periodo:

- hoje;
- 7 dias;
- 30 dias;
- mes atual;
- periodo personalizado.

Indicadores detalhados devem respeitar sigilo processual. Dados de processo
sigiloso so podem aparecer quando o usuario tiver autorizacao explicita para o
processo.

## Produtividade

Produtividade do usuario deve ser calculada pela quantidade de itens concluidos
no periodo.

Separar por tipo:

- expedicoes;
- cartas recebidas;
- tarefas;
- prazos.

Produtividade por usuario e por setor deve usar o mesmo criterio de conclusao,
permitindo comparacao operacional simples e auditavel.

## Rotina diaria do chefe/escrivao

A rotina diaria recomendada do chefe/escrivao e:

1. abrir o dashboard;
2. revisar prazos vencidos;
3. revisar prazos a vencer;
4. conferir filas de expedicao;
5. validar cartas recebidas;
6. revisar solicitacoes de sigilo.

O chefe/escrivao pode redistribuir responsaveis, ajustar prioridades e orientar
servidores conforme a situacao operacional da unidade.

## Rotina diaria do servidor operacional

A rotina diaria recomendada do servidor operacional e:

1. abrir a fila de trabalho;
2. priorizar itens vencidos;
3. priorizar itens urgentes;
4. executar itens atribuidos;
5. atualizar status;
6. registrar observacoes minimas quando necessario.

O servidor nao deve depender do dashboard gerencial para executar sua rotina.

## Suporte operacional

Backoffice presta suporte operacional orientativo na v1, sem acesso direto a
dados juridicos.

O suporte pode orientar:

- configuracao;
- uso de filas;
- permissoes;
- assinatura;
- modulos;
- diagnostico por metadados tecnicos.

O suporte nao pode visualizar:

- processos;
- contatos;
- partes;
- expedicoes;
- prazos;
- pendencias;
- conteudo sigiloso;
- documentos exportados.

## Solicitacao de suporte

A unidade deve solicitar suporte por canal dentro do produto.

Campos minimos:

- categoria;
- descricao;
- prints opcionais sanitizados;
- metadados tecnicos automaticos;
- usuario solicitante;
- empresa;
- unidade;
- data/hora;
- status.

Categorias da v1:

- acesso/permissoes;
- duvidas de uso;
- erro tecnico;
- assinatura/modulos.

Prints e anexos devem ser sanitizados antes de envio. O sistema deve orientar o
usuario a nao incluir dados sigilosos, contatos, partes, documentos ou conteudo
processual sensivel.

## Incidentes operacionais

Incidentes destacados na v1:

- indisponibilidade do produto;
- falha generalizada de filas/dashboard;
- vazamento de dados sigilosos;
- erro massivo de permissao;
- perda ou corrupcao de dados.

Incidente deve registrar:

- tipo;
- severidade;
- empresa/unidade afetada quando aplicavel;
- horario de inicio;
- horario de identificacao;
- impacto resumido;
- status;
- responsavel interno;
- acoes tomadas;
- horario de resolucao.

## Comunicacao de incidente

Quando incidente afetar disponibilidade, dados, permissoes ou sigilo, a unidade
deve receber:

- mensagem no produto;
- e-mail para administrador da unidade;
- e-mail para chefe/escrivao.

A comunicacao deve informar impacto e orientacao operacional sem expor dados
sigilosos ou detalhes tecnicos exploraveis.

## Retencao

Solicitacoes de suporte e metadados operacionais devem ser retidos por 90 dias.

Nao armazenar:

- dados juridicos sensiveis;
- prints nao sanitizados;
- payload bruto Datajud;
- conteudo de processo sigiloso;
- documentos exportados.

Auditoria operacional do Fokus Law segue a retencao de 180 dias definida em
[Seguranca e permissoes do Fokus Law](../07-security/fokus-law-security-and-permissions.md).

Logs tecnicos sanitizados seguem retencao de 7 dias.

## Metricas operacionais

Metricas da v1:

- volume por fila;
- itens vencidos;
- itens a vencer;
- tempo medio de conclusao;
- expedicoes sem retorno;
- expedicoes com cumprimento ou conferencia pendente;
- produtividade por usuario;
- produtividade por setor;
- solicitacoes de sigilo;
- chamados de suporte.
- processos por classe, prioridade, tag e nivel de sigilo.

Metricas devem respeitar sigilo processual e permissao do usuario.

## Criterios de aceite

- Dashboard e fila aparecem como tela unica de comando.
- Gestores e chefia visualizam mais indicadores.
- Servidores visualizam mais fila de trabalho.
- Fila possui abas/filtros para expedicoes, cartas recebidas,
  tarefas, prazos e pendencias.
- Fila permite visualizar vinculos entre tarefas e expedicoes.
- Cartas recebidas sao fila/visao de processos, nao expediente separado.
- Fila ordena vencidos, urgentes, vencimento proximo e itens antigos.
- Alertas existem apenas para prazo vencido, prazo a vencer, retorno,
cumprimento ou conferencia de expedicao e solicitacao de acesso sigiloso.

Audiencias devem possuir fila e agenda proprias, com filtros por periodo,
unidade, sala, modalidade, responsavel e status. O acompanhamento externo deve
exibir apenas o status autorizado da audiencia, sem dados internos do processo.
- Erro Datajud nao aparece na interface operacional.
- Dashboard possui os cards definidos neste documento.
- Processos possuem linha do tempo com eventos oficiais, operacionais e
  relacionais.
- Contatos aparecem como modulo operacional independente e podem ser vinculados
  a processos, expedicoes e tarefas.
- Produtividade e calculada por itens concluidos no periodo.
- Rotinas de chefe/escrivao e servidor operacional estao documentadas.
- Suporte e orientativo e nao acessa dados juridicos.
- Canal de suporte interno possui categorias, descricao, prints sanitizados e
  metadados tecnicos.
- Incidentes destacados possuem comunicacao para administrador da unidade e
  chefe/escrivao.
- Solicitacoes de suporte e metadados operacionais possuem retencao de 90 dias.
