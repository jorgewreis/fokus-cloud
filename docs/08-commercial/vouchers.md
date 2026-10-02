# Vouchers

## Finalidade

Vouchers concedem beneficios comerciais para a contratacao de sistemas e planos.

## Beneficios suportados

- `trial_free`: assinatura gratuita conforme a duracao definida;
- `percentage`: desconto percentual;
- `fixed`: desconto em valor fixo;
- `commercial_credit`: credito comercial aplicado conforme regra do voucher.

Para Trial Free, o desconto corresponde a 100% e os campos de percentual e valor devem permanecer bloqueados no formulario.

## Aplicacao

Um voucher pode ser vinculado a:

- um sistema e um plano especifico;
- um sistema e todos os seus planos.

O plano escolhido deve pertencer ao sistema informado.

## Valores

O desconto deve ser calculado sobre o preco vigente do plano no momento do resgate. O cadastro pode exibir a base atual para conferencia, mas o valor final deve ser recalculado no backend.

O percentual deve estar entre 0 e 100. Valores monetarios devem usar BRL com duas casas decimais. O backend nunca deve confiar apenas no calculo feito pelo navegador.

## Validade, beneficio e status

O cadastro deve exigir inicio da validade igual ou posterior a hoje e fim da validade posterior ao inicio. A duracao do beneficio e informada separadamente.

As datas inicial e final do cadastro representam somente a janela de validade do voucher, ou seja, o periodo em que o codigo pode ser resgatado. A duracao do beneficio comeca na ativacao pela empresa ou usuario e nao na data inicial do voucher.

No resgate, `benefit_starts_at` recebe a data efetiva da ativacao e `benefit_ends_at` e calculada a partir de `benefit_duration`. Um trial anual ativado em 15/09/2026 termina em 15/09/2027, ainda que a validade do voucher termine antes ou depois.

Status comerciais:

- `ativa`: pode ser resgatado;
- `suspensa`: temporariamente bloqueado;
- `encerrada`: cancelado;
- `expirada`: derivado automaticamente quando a data final passou.

O estado expirado nao deve depender de alteracao manual.

## Limites

Cada voucher pode possuir:

- limite total de resgates;
- limite de resgates por empresa;
- lote de codigos individuais.

O codigo pode ser gerado automaticamente ou informado manualmente, sempre com validacao de unicidade.

## Snapshot do resgate

Cada resgate deve preservar, no minimo:

- codigo e voucher;
- sistema e plano;
- preco-base;
- tipo e valor do beneficio;
- valor efetivamente descontado;
- preco final;
- periodo concedido;
- assinatura criada;
- empresa que utilizou o voucher.

Isso preserva a auditoria mesmo depois de alteracoes no catalogo.
## Reserva e crédito comercial

A tentativa de checkout cria uma reserva temporária de 30 minutos. Ela consome os limites para impedir concorrência, mas não é resgate confirmado. Aprovação confirma uma única reserva; abandono, falha, cancelamento ou expiração a libera. commercial_credit é um valor fixo aplicado somente à primeira cobrança, limitado ao valor cobrado, sem saldo remanescente ou recorrência.

Após o primeiro resgate, regras comerciais, elegibilidade e valor são imutáveis. O snapshot confirmado inclui também código, produto, plano, ciclo, empresa, assinatura, base, desconto, valor final e período do benefício.

## Ativação de assinatura pendente com Trial Free

Uma assinatura em `aguardando_pagamento`, sem pagamento aprovado, pode ser
ativada no Backoffice por `POST /api/backoffice/subscriptions/{id}/free-voucher`
com `voucher_code`. O endpoint protegido reutiliza as verificações de
elegibilidade, reserva e confirmação do voucher; a futura página de vouchers
pode chamar o mesmo endpoint. O resgate e a ação do administrador interno são
auditados.

Antes de conceder acesso, a API consulta a pré-aprovação pendente e a cancela
no Mercado Pago. O pagamento pendente passa a `cancelado`; a assinatura fica
`ativa` sem pré-aprovação recorrente, com vigência até `benefit_ends_at`.
Eventos atrasados dessa cobrança cancelada não podem alterar o benefício.

O comando `fokus:expire-free-voucher-subscriptions`, agendado a cada hora,
suspende a assinatura quando o benefício termina. Reativação, upgrade e
downgrade não substituem uma contratação paga. Um novo checkout para o mesmo
produto encerra a assinatura gratuita suspensa ao criar a nova assinatura
pendente; somente a confirmação do novo pagamento ativa o acesso pago.


## Voucher durante ampliação de assinatura

Uma ampliação elegível preserva o benefício `trial_free` vigente até o vencimento
original. Ela não cria cobrança, não consome outro resgate e não reinicia o prazo.
O preço-base da nova composição fica registrado separadamente do valor gratuito.
O tipo de benefício concedido permanece no snapshot; editar ou suspender o cadastro
do voucher não transforma em paga uma gratuidade já concedida e ainda vigente.

A elegibilidade considera produto, plano e a regra de módulos do voucher. O contrato
existente considera elegível a composição que contém pelo menos um módulo da lista
permitida, quando essa lista é informada. Os novos resgates guardam essas restrições
em `snapshot.eligibility`; para resgates anteriores, sem esse campo, a verificação
usa as restrições atuais do cadastro do voucher. Limites de resgate não são
reaplicados à manutenção de um benefício já concedido.

Se a nova composição não for elegível, a confirmação é bloqueada e pede um novo
voucher gratuito elegível. Portal e backoffice oferecem esse campo na revisão.
O novo código passa por validade, produto, plano, módulos e limites de uso; seu
prazo começa na confirmação. Benefícios substituídos não provocam suspensão antes
do vencimento do benefício atual e não voltam a valer após o término do substituto.

Em assinaturas pagas, vouchers de percentual, valor fixo ou crédito são aplicados
somente à diferença proporcional da ampliação, conforme informado na revisão;
eles não alteram o preço recorrente. O voucher é reservado por 30 minutos e
confirmado com a aplicação da alteração. Se a reserva expirar antes da confirmação
do pagamento, a composição não é aplicada e o pagamento identificado é devolvido.
O cliente pode refazer a solicitação com um código elegível.

Aplicar um novo `trial_free` a uma ampliação de assinatura paga interrompe a
recorrência existente antes de liberar o benefício. Sem recorrência ativa, uma
assinatura não pode passar a um valor pago por uma alteração de composição: precisa
de um voucher gratuito elegível ou de uma nova contratação paga.
