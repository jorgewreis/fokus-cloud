# Monitoramento, suporte e operacao do Backoffice

## Objetivo

Definir a operacao diaria do Backoffice Fokus Cloud, cobrindo alertas, filas de
revisao, incidentes, suporte interno, auditoria operacional, rotinas diarias e
rotina semanal.

Este documento deve orientar a implementacao de filas, alertas, severidade,
prazos de atendimento, notificacoes, comentarios, retencao, metricas e
tratamento de incidentes criticos.

## Escopo da v1

A v1 cobre:

- alertas operacionais;
- filas de revisao;
- incidentes;
- suporte interno;
- auditoria operacional;
- rotinas diarias;
- rotina semanal;
- metricas de operacao.

Este documento nao substitui as regras de produto, billing, seguranca ou dados.
Ele define como essas regras sao acompanhadas depois que entram em uso real.
O modelo alvo de tabelas para alertas, comentarios, incidentes e notificacoes
esta em [Modelo de dados do Backoffice e Billing](../06-data/backoffice-and-billing-data-model.md).

## Filas operacionais

Os alertas do Backoffice devem ser organizados nas seguintes filas:

| Fila | Finalidade |
| --- | --- |
| Financeiro | Inadimplencia, tolerancia expirada, reembolso pendente, pagamentos e divergencias financeiras. |
| Seguranca | Login interno suspeito, conta interna bloqueada, acesso indevido e eventos sensiveis. |
| Catalogo | Erro de publicacao, cache, catalogo publico incorreto e falhas de disponibilidade comercial. |
| Suporte interno | Registros de suporte operacional dentro do Backoffice. |
| Auditoria/Revisao | Divergencias, acoes sensiveis, revisoes manuais e evidencias operacionais. |

Na v1, o superadministrador e responsavel por todas as filas. O administrador
comercial pode consultar todas as filas e comentar, mas nao pode resolver
alertas.

## Alertas da v1

Devem existir alertas para:

- falha de webhook;
- divergencia com Mercado Pago;
- inadimplencia;
- tolerancia expirada;
- reembolso pendente;
- login interno suspeito;
- erro de publicacao do catalogo.

Cada alerta deve ter fila, severidade, estado, entidade afetada, origem,
responsavel quando houver, datas relevantes e vinculo com auditoria ou log
tecnico quando aplicavel.

## Severidade e prazos

Os alertas devem ser priorizados dentro de cada fila por severidade.

| Severidade | Prazo de atendimento | Criterio |
| --- | --- | --- |
| `critica` | 2h | Afeta seguranca, acesso, cobranca em escala, dados sensiveis ou catalogo publico. |
| `alta` | 1 dia util | Afeta cliente, cobranca, recorrencia, publicacao ou risco operacional relevante. |
| `media` | 2 dias uteis | Exige revisao, mas nao bloqueia acesso, cobranca ou seguranca imediatamente. |
| `baixa` | 5 dias uteis | Evento informativo, preventivo ou de baixa urgencia. |

Alertas vencidos sobem automaticamente para incidente de severidade maior.
Quando um alerta `critica` vencer, ele vira incidente critico vencido, notifica
novamente o superadministrador e exige registro de contencao imediata.

## Estados do alerta

| Estado | Uso |
| --- | --- |
| `aberto` | Alerta criado e ainda nao revisado. |
| `em_revisao` | Superadministrador esta analisando o alerta. |
| `aguardando_acao` | Alerta depende de correcao, informacao, gateway, cliente ou acao externa. |
| `resolvido` | Alerta encerrado com resultado e acao registrada. |
| `descartado` | Alerta classificado como falso positivo, irrelevante ou sem acao necessaria. |

## Resolucao

A resolucao de um alerta deve registrar:

- resultado;
- motivo;
- acao tomada;
- responsavel;
- data e hora;
- entidade afetada;
- evidencias minimas.

Comentarios e evidencias devem permanecer no registro interno do Backoffice. O
canal oficial de suporte interno da v1 e o proprio alerta, com comentarios e
historico.

## Incidentes criticos

Sao incidentes criticos na v1:

- acesso indevido ao Backoffice;
- falha generalizada de checkout ou webhook;
- cobranca duplicada em lote;
- catalogo publico incorreto;
- vazamento de dados sensiveis.

O procedimento para incidente critico e:

1. conter o impacto;
2. registrar o incidente;
3. comunicar internamente;
4. corrigir a causa imediata;
5. validar a correcao;
6. revisar a causa;
7. registrar acao preventiva.

Incidente critico vencido exige registro de contencao imediata antes do
encerramento.

## Notificacoes imediatas

Devem notificar imediatamente o superadministrador:

- incidente critico;
- login interno suspeito;
- conta interna bloqueada;
- falha generalizada de checkout ou webhook;
- cobranca duplicada em lote.

O canal de notificacao imediata da v1 e e-mail transacional e destaque no
dashboard do Backoffice. A notificacao nao substitui o alerta; ela apenas
chama atencao para o registro operacional.

## Rotina diaria

O superadministrador deve revisar diariamente:

- filas operacionais;
- divergencias com Mercado Pago;
- inadimplencia;
- reembolsos pendentes;
- falhas de webhook;
- publicacoes do catalogo;
- eventos de seguranca.

A revisao diaria deve priorizar alertas criticos, alertas vencidos, eventos de
seguranca e riscos de cobranca ou acesso.

## Rotina semanal

A rotina semanal deve revisar:

- alertas recorrentes;
- incidentes criticos;
- divergencias;
- reembolsos;
- inadimplencia;
- falhas de webhook;
- acoes preventivas.

O objetivo da revisao semanal e identificar padroes, reduzir recorrencia e
registrar melhorias operacionais.

## Logs tecnicos

Logs tecnicos devem ser minimos, correlacionaveis e sem dados sensiveis. Quando
houver alerta ou auditoria relacionada, o log deve registrar identificador ou
referencia suficiente para investigacao. A origem deve indicar canal tipado,
rota/comando e request/correlation ID quando disponíveis; IP e User-Agent só
entram quando a requisição os fornece.

Nao devem aparecer em logs, comentarios ou evidencias:

- senhas;
- tokens;
- codigos MFA;
- CPF ou CNPJ completo;
- dados completos de cartao;
- payload completo do gateway;
- documentos pessoais.

Quando alguma evidencia exigir dado sensivel, deve ser usado mascaramento ou
referencia ao registro protegido, nunca copia integral em comentario livre.

## Retencao

Registros operacionais de alertas e comentarios devem ser mantidos por 90 dias.

Eventos formais de auditoria seguem a politica propria do Backoffice. Snapshots
comerciais e financeiros devem preservar a verdade de assinatura, pagamento,
voucher e condicao aplicada conforme os documentos comerciais e de dados.

## Metricas operacionais

O Backoffice deve acompanhar:

- alertas por fila e severidade;
- tempo de primeira revisao;
- tempo de resolucao;
- alertas vencidos;
- incidentes criticos;
- recorrencia por tipo.

Metricas devem ajudar a identificar filas acumuladas, reincidencia de falhas,
descumprimento de prazo e areas que exigem prevencao.

## Criterios minimos de teste

- Alerta entra na fila correta.
- Alerta recebe severidade conforme impacto em acesso, cobranca, seguranca ou
  catalogo publico.
- Superadministrador consegue revisar e resolver alerta.
- Administrador comercial consegue consultar e comentar, mas nao resolver.
- Resolucao exige resultado, motivo, acao, responsavel, data, entidade e
  evidencias minimas.
- Alerta vencido escala para incidente de severidade maior.
- Alerta critico vencido exige nova notificacao e contencao imediata.
- Notificacao imediata gera e-mail transacional e destaque no dashboard.
- Logs e comentarios nao registram dados sensiveis proibidos.
- Registros operacionais expiram apos 90 dias.
- Metricas exibem volume por fila/severidade, tempos, vencidos, criticos e
  recorrencia por tipo.

## Dependencias

- [Monitoramento e suporte](monitoring-and-support.md)
- [Backoffice Fokus Cloud](../04-products/backoffice.md)
- [Billing e conciliacao com Mercado Pago](../08-commercial/billing-and-reconciliation.md)
- [Requisitos do Backoffice Fokus Cloud](../05-requirements/backoffice.md)
- [Modelo de dados do Backoffice e Billing](../06-data/backoffice-and-billing-data-model.md)
- [Seguranca e dados](../07-security/security-and-data.md)

## Criterio de pronto

Este documento esta suficiente quando um implementador consegue criar filas,
alertas, severidade, prazos, notificacoes, comentarios, retencao, metricas e
tratamento de incidente critico sem tomar novas decisoes operacionais.

## Billing sandbox

Monitorar `fokus:expire-subscription-tolerance`,
`fokus:reconcile-mercado-pago`, eventos do provedor com status `failed`,
reembolsos reaplicáveis e divergências abertas. Nunca registrar token, segredo
HMAC, cartão ou payload bruto. Falhas de consulta do gateway devem manter o
evento reaplicável e permitir nova tentativa.
