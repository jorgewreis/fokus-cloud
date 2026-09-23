# Cadastro de empresa e assinatura

## Objetivo

Definir o fluxo que cria uma empresa, associa seu administrador inicial e só
libera a escolha de assinatura após a confirmação do e-mail.

As regras de acesso e transferência de administração estão em
[Administração e acesso](../03-architecture/access-and-administration.md). As regras de proteção de
dados estão em [Segurança e dados](../07-security/security-and-data.md). O modelo das tabelas
e o isolamento por empresa estão em [Modelo relacional](../06-data/relational-model.md).

## Primeiro cadastro

O primeiro cadastro é uma única operação: empresa, conta do administrador e
vínculo administrativo são criados juntos. O sistema não deve persistir uma
empresa sem administrador nem um administrador inicial sem empresa.

### Dados da empresa

O formulário solicita o tipo de documento e adapta o campo de nome:

| Documento | Campo de nome |
| --- | --- |
| CPF | Nome completo |
| CNPJ | Razão social |

O CPF ou CNPJ identifica a empresa de maneira única. O documento é
normalizado, validado pelos dígitos verificadores e comparado com os registros
existentes antes de criar a empresa.

Se o documento empresarial já existir, o sistema não cria uma segunda empresa.
Ele direciona o visitante ao login ou à recuperação de acesso da conta já
vinculada à empresa.

### Dados do administrador inicial

O administrador inicial informa:

- nome completo;
- CPF;
- e-mail profissional;
- senha;
- aceite explícito dos Termos de Uso;
- aceite explícito da Política de Privacidade.

Os dois aceites são checkboxes obrigatórios e independentes. O registro deve
guardar a versão aceita e a data e hora do aceite.

## Conta existente

O CPF identifica uma única conta de usuário em toda a plataforma. A mesma
conta pode administrar ou acessar várias empresas.

Quando o CPF do administrador já existir, o sistema não pede novo cadastro da
pessoa. Ele solicita login com CPF e senha; após autenticação, vincula a conta
existente como administradora da nova empresa. Os dados pessoais permanecem
reutilizados.

## Confirmação de e-mail e assinatura

Após o cadastro, o sistema envia um link de confirmação ao e-mail profissional
do administrador. O link expira em 24 horas.

Enquanto o e-mail não estiver confirmado, a escolha de módulos, planos e
assinatura fica bloqueada. Depois da confirmação, o sistema cria ou mantém a
sessão autenticada e direciona o administrador automaticamente à escolha da
assinatura, sem exigir novo login.

A escolha deve carregar exclusivamente a versão publicada e comercializável do
catálogo. O cliente pode selecionar um plano sugerido, ajustar limites
permitidos ou montar uma oferta personalizada com funcionalidades compatíveis.

As regras de billing, status, recorrência, inadimplência, reembolso e
conciliação com o Mercado Pago estão definidas em [Billing e conciliação com
Mercado Pago](billing-and-reconciliation.md).

A assinatura só é persistida depois que o Mercado Pago cria o checkout ou
`preapproval` com sucesso. A assinatura e o pagamento iniciam em
`aguardando_pagamento` e a ativação depende de webhook assinado ou conciliação
posterior com o Mercado Pago.

No Backoffice, `/backoffice/assinaturas` oferece checkout assistido para uma
empresa ativa com administrador ativo e e-mail confirmado. O operador seleciona
um plano da publicação vigente e o ciclo; a API recalcula o preço, cria a
pré-aprovação e só então persiste assinatura e pagamento pendentes. A ação é
auditada com a identidade do administrador interno. O operador recebe o link
de checkout para encaminhar ao cliente. A confirmação do pagamento continua
sob responsabilidade do webhook assinado ou da conciliação.

O checkout assistido usa a versão mais recente já publicada mesmo enquanto uma
edição posterior do catálogo aguarda publicação. Assim os contratos usam a
oferta e o preço publicados; alterações de rascunho não entram na contratação.

## Estados do fluxo

| Estado | Resultado |
| --- | --- |
| Documento empresarial novo e CPF novo | Cria empresa, usuário e vínculo de admin; envia confirmação de e-mail. |
| Documento empresarial novo e CPF existente | Solicita login; após sucesso, cria empresa e vínculo de admin; exige e-mail confirmado antes da assinatura. |
| Documento empresarial existente | Não cria empresa; direciona ao login ou recuperação de acesso. |
| E-mail pendente ou link expirado | Impede a escolha de assinatura e permite reenviar a confirmação. |
| E-mail confirmado | Libera a escolha de assinatura para a empresa ativa. |
| Checkout/preapproval criado | Cria assinatura e pagamento em `aguardando_pagamento`. |
| Pagamento aprovado por webhook ou conciliação | Ativa a assinatura. |

## Critérios de aceite

- Não deve existir empresa duplicada para o mesmo CPF ou CNPJ.
- Não deve existir empresa criada sem administrador inicial.
- O CPF existente deve reutilizar a conta já cadastrada após autenticação.
- O e-mail confirmado é pré-requisito para escolher a assinatura.
- A assinatura deve ficar vinculada à empresa, não exclusivamente à conta do
  administrador.
- Uma empresa pode possuir uma assinatura independente por produto, mas apenas
  uma assinatura não encerrada para o mesmo produto.
- Módulos, quantidade, preço e condições contratadas permanecem como snapshot
  na assinatura da empresa.
- O valor exibido na revisão é recalculado no servidor a partir do catálogo,
  ciclo, plano, módulos e limites selecionados; valores vindos do navegador não
  são aceitos como preço.
- A assinatura e o pagamento só são persistidos depois que o checkout ou
  `preapproval` do Mercado Pago é criado.
- A confirmação depende de webhook assinado e idempotente ou conciliação
  posterior com o Mercado Pago.
