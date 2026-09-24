# Billing e conciliacao com Mercado Pago

## Objetivo

Este documento define o ciclo completo de billing do Fokus Cloud, da
contratacao inicial ate a conciliacao com o Mercado Pago.

Ele deve orientar a implementacao de checkout, pagamentos, recorrencia,
assinaturas, inadimplencia, upgrade, downgrade, pausa, reativacao,
cancelamento, reembolso, dashboard financeiro e correcao de divergencias.

## Fonte de pagamento

Mercado Pago e o fornecedor definido para pagamentos e recorrencia na v1.

O Fokus Cloud mantem o estado interno da assinatura, os snapshots comerciais,
os pagamentos locais, os alertas e a auditoria. O Mercado Pago controla a
recorrencia e confirma pagamentos, recusas, cancelamentos, estornos e disputas.

O modelo alvo das tabelas, status, snapshots, reembolsos e divergencias esta
definido em [Modelo de dados do Backoffice e Billing](../06-data/backoffice-and-billing-data-model.md).

Receita recebida deve vir de pagamentos aprovados no Mercado Pago. Receita
prevista deve vir de assinaturas ativas e snapshots internos.

Nota fiscal fica fora da v1. A emissao fiscal deve ser documentada como
integracao ou fluxo proprio antes de ser incorporada ao billing.

## Contratacao inicial

A assinatura so deve ser criada no banco depois que o Mercado Pago criar o
checkout ou `preapproval` com sucesso.

Se a criacao do checkout falhar, o sistema nao deve persistir assinatura nem
pagamento. A interface deve informar falha controlada e permitir nova tentativa.

Depois que o checkout ou `preapproval` for criado:

- a assinatura recebe status `aguardando_pagamento`;
- o pagamento recebe status `aguardando_pagamento`;
- a assinatura guarda o produto, ciclo, vigencia inicial e identificador da
  recorrencia no provedor;
- os itens contratados guardam snapshots de modulo, quantidade, preco e
  condicoes aplicadas.

O valor cobrado deve ser recalculado no backend a partir do catalogo publicado,
ciclo, plano, modulos, limites e voucher. Valores enviados pelo navegador nao
sao fonte de preco.

## Status de assinatura

| Status | Uso |
| --- | --- |
| `aguardando_pagamento` | Assinatura criada apos checkout/preapproval, aguardando confirmacao financeira. |
| `ativa` | Pagamento aprovado ou conciliado; produto liberado conforme plano e modulos contratados. |
| `inadimplente` | Falha de cobranca recorrente dentro do periodo de tolerancia. |
| `suspensa` | Acesso bloqueado por fim da tolerancia, pausa administrativa ou outro motivo controlado. |
| `cancelamento_agendado` | Cancelamento solicitado para o fim da vigencia atual. |
| `encerrada` | Assinatura finalizada, sem acesso e sem recorrencia ativa no produto. |

Uma empresa pode possuir uma assinatura independente por produto, mas apenas
uma assinatura nao encerrada para o mesmo produto.

## Status de pagamento

| Status | Uso |
| --- | --- |
| `aguardando_pagamento` | Pagamento criado localmente e aguardando confirmacao do Mercado Pago. |
| `aprovado` | Pagamento confirmado pelo Mercado Pago. |
| `recusado` | Pagamento negado ou falhou no Mercado Pago. |
| `cancelado` | Pagamento cancelado antes da conclusao. |
| `estornado` | Valor devolvido ao cliente pelo Mercado Pago. |
| `em_disputa` | Pagamento em contestacao, chargeback ou disputa equivalente. |

Os status nativos do Mercado Pago devem ser traduzidos para o modelo interno.
O payload bruto do provedor nunca deve ser preservado em logs, snapshots de
billing ou auditoria. Persistir somente campos necessários, selecionados por
allowlist e sanitizados antes da gravação. A trilha de auditoria registra as
transições comerciais e referencia o evento técnico por request/correlation
ID, sem copiar o payload original.

## Ativacao

A assinatura pode ser ativada por:

- webhook assinado e validado do Mercado Pago;
- conciliacao posterior com o Mercado Pago, quando o webhook falhar, atrasar ou
  ficar inconclusivo.

O retorno do navegador apos checkout nao ativa assinatura. Ele pode apenas
orientar a experiencia do usuario enquanto o sistema aguarda confirmacao
financeira.

Webhooks devem ser assinados, validados e idempotentes. Evento duplicado nao
pode duplicar pagamento, assinatura, auditoria ou resgate de voucher.

## Recorrencia

O Mercado Pago controla a recorrencia. O Fokus Cloud reflete os eventos
recebidos e concilia divergencias.

Em cada ciclo, o sistema deve registrar ou atualizar o pagamento local com o
status informado pelo gateway. Quando o pagamento recorrente for aprovado, a
assinatura permanece `ativa` ou e reativada automaticamente se estava suspensa
por inadimplencia.

## Inadimplencia

Quando uma cobranca recorrente falhar:

1. o pagamento deve ser marcado como `recusado`;
2. a assinatura deve passar para `inadimplente`;
3. o acesso ao produto permanece liberado por 7 dias corridos;
4. o Backoffice recebe alerta de inadimplencia na fila Financeiro.

Se nao houver pagamento aprovado ate o fim da tolerancia:

- a assinatura passa para `suspensa`;
- o acesso ao produto e bloqueado;
- o Backoffice recebe alerta de tolerancia expirada.

Assinatura suspensa por inadimplencia deve ser reativada automaticamente apos
pagamento aprovado ou conciliacao confirmada no Mercado Pago.

## Upgrade

Upgrade de plano ou composicao vale imediatamente somente apos cobranca
proporcional aprovada pelo Mercado Pago.

Antes da aprovacao da cobranca proporcional, a mudanca permanece pendente e nao
libera recursos adicionais. Depois da aprovacao, o sistema atualiza assinatura,
itens contratados, snapshots, vigencia quando aplicavel e auditoria.

## Downgrade

Downgrade fica agendado para o fim da vigencia atual, sem credito retroativo.

Durante a vigencia em curso, o cliente mantem acesso ao plano e aos limites ja
pagos. No fim da vigencia, o sistema aplica a nova composicao, atualiza
snapshots e ajusta a recorrencia conforme o novo valor.

## Cancelamento

Cancelamento fica agendado para o fim da vigencia atual, sem reembolso
automatico.

Enquanto a vigencia nao termina, a assinatura pode ficar como
`cancelamento_agendado` e o acesso permanece liberado. No fim da vigencia, a
assinatura passa para `encerrada`, o acesso e bloqueado e a recorrencia deve
ser encerrada no Mercado Pago.

## Pausa administrativa

A pausa administrativa pelo Backoffice bloqueia o acesso imediatamente, mantem
o vinculo comercial e nao cancela a recorrencia automaticamente.

Esse estado deve ser usado para medidas temporarias, revisoes comerciais ou
restricoes operacionais que nao representem cancelamento.

A reativacao apos pausa administrativa e feita pelo Backoffice, libera acesso
imediatamente e mantem a recorrencia vigente. A acao deve gerar auditoria.

## Reembolso e estorno

Reembolso e manual pelo Backoffice. O administrador comercial pode solicitar, e
o superadministrador aprova e executa via Mercado Pago.

Situacoes que podem gerar solicitacao de reembolso na v1:

- cobranca duplicada;
- erro tecnico de cobranca;
- acordo comercial excepcional;
- cancelamento dentro de 7 dias corridos apos qualquer renovacao.

Reembolso nao e automatico para downgrade ou cancelamento comum.

Todo reembolso deve registrar motivo, solicitante, aprovador, pagamento
relacionado, valor, status no Mercado Pago, payload de retorno e auditoria. Se
o Mercado Pago confirmar estorno, o pagamento local deve passar para
`estornado`.

## Conciliacao

A conciliacao compara os dados internos do Fokus Cloud com os dados do Mercado
Pago para pagamentos, recorrencias e assinaturas.

Quando houver divergencia:

1. o sistema cria alerta de divergencia;
2. a divergencia fica disponivel para revisao no Backoffice, conforme
   [Monitoramento, suporte e operacao do Backoffice](../09-operations/backoffice-monitoring-and-support.md);
3. nenhum ajuste e aplicado automaticamente;
4. superadministrador e administrador comercial podem revisar;
5. apenas superadministrador pode aplicar correcao.

O Mercado Pago e a referencia prevalente para pagamento e recorrencia, mas a
correcao interna exige revisao manual antes de alterar assinatura, pagamento ou
acesso.

Toda correcao deve registrar estado interno anterior, estado do Mercado Pago,
decisao aplicada, motivo, operador, data e auditoria.

## Dashboard financeiro

O dashboard financeiro do Backoffice deve acompanhar:

- receita recebida;
- receita prevista;
- inadimplencia;
- churn;
- upgrades e downgrades;
- divergencias com Mercado Pago.

Receita recebida e calculada a partir de pagamentos aprovados no Mercado Pago.
Receita prevista e calculada a partir de assinaturas ativas e snapshots
internos.

Indicadores devem diferenciar dado recebido do gateway, dado calculado
internamente e divergencia pendente de revisao.

## Eventos auditaveis

Devem gerar auditoria:

- criacao de checkout/preapproval;
- criacao de assinatura e pagamento;
- recebimento de webhook;
- validacao ou rejeicao de webhook;
- ativacao por webhook ou conciliacao;
- falha de cobranca recorrente;
- entrada e saida de inadimplencia;
- suspensao por fim de tolerancia;
- upgrade, downgrade e cancelamento;
- pausa e reativacao administrativa;
- solicitacao, aprovacao e execucao de reembolso;
- estorno, disputa e cancelamento de pagamento;
- criacao, revisao e correcao de divergencia.

## Criterios minimos de teste

- Checkout/preapproval criado com sucesso gera assinatura e pagamento em
  `aguardando_pagamento`.
- Falha ao criar checkout/preapproval nao persiste assinatura nem pagamento.
- Webhook assinado ativa assinatura e atualiza pagamento.
- Conciliação posterior ativa assinatura quando webhook falhar ou atrasar.
- Webhook invalido nao altera pagamento nem assinatura.
- Evento duplicado do Mercado Pago e idempotente.
- Cobranca recorrente recusada coloca assinatura em `inadimplente` e mantem
  acesso por 7 dias.
- Tolerancia expirada muda assinatura para `suspensa` e bloqueia acesso.
- Pagamento aprovado reativa assinatura suspensa por inadimplencia.
- Upgrade so aplica apos cobranca proporcional aprovada.
- Downgrade e cancelamento ficam agendados para fim da vigencia.
- Pausa administrativa bloqueia acesso sem cancelar recorrencia.
- Reativacao administrativa libera acesso mantendo recorrencia.
- Solicitacao de reembolso exige motivo e caso permitido.
- Apenas superadministrador aprova e executa reembolso.
- Divergencia exige revisao manual.
- Apenas superadministrador aplica correcao de divergencia.
- Indicadores financeiros diferenciam receita recebida do Mercado Pago e
  previsao calculada internamente.

## Dependencias

- [Cadastro de empresa e assinatura](registration-and-subscription.md)
- [Backoffice Fokus Cloud](../04-products/backoffice.md)
- [Requisitos do Backoffice Fokus Cloud](../05-requirements/backoffice.md)
- [Catalogo comercial](catalog.md)
- [Vouchers](vouchers.md)
- [Modelo relacional](../06-data/relational-model.md)
- [Modelo de dados do Backoffice e Billing](../06-data/backoffice-and-billing-data-model.md)
- [Portais e governanca](../03-architecture/portals-and-governance.md)

## Criterio de pronto

Este documento esta suficiente quando um implementador consegue criar ou
ajustar status, checkout, webhooks, conciliacao, dashboard financeiro,
reembolso e transicoes de assinatura sem tomar novas decisoes de produto.

## Implementação `v0.0.6-alpha.1`

A alpha usa `MercadoPagoClient` para `/preapproval`, `/preapproval/{id}`,
`/authorized_payments/{id}`, `/v1/payments/{id}` e reembolsos. Webhooks usam
HMAC com `x-signature`, `x-request-id` e `data.id`, persistem evento sanitizado
antes do processamento e retornam `200` para processados ou duplicados. A
tolerância de inadimplência é de sete dias. Correções de conciliação e execução
de reembolso são exclusivas do superadministrador.
