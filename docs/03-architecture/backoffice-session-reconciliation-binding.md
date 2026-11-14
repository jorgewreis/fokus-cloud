# Vínculo de sessão para reconciliação: protótipo

Este marcador não autentica, não autoriza e não habilita reconciliação. É
gerado após MFA bem-sucedido e regeneração da sessão de plataforma. É aleatório
e independente do identificador da sessão HTTP. Login novo, inclusive do mesmo
administrador, gera outro marcador. Logout invalida a sessão e o marcador.

A entrada em suporte regenera a sessão HTTP e também rotaciona um marcador
válido existente. Sessões anteriores ao protótipo, ou com marcador inválido,
permanecem sem marcador; entrar em suporte nunca cria um. Qualquer futura
recuperação precisa negar a reconciliação nessas sessões e exigir novo MFA;
não deve presumir que identidade igual significa sessão igual. A rotação em
suporte também exige reconciliação manual de operações ainda indeterminadas.

Nenhum endpoint expõe o marcador e nenhum cliente o armazena ou usa. Antes de
conectar o fluxo são necessários contrato de transporte sem cache, revisão de
segurança e testes de logout/login e corridas entre abas, BFCache, sessão
expirada, múltiplos nós e comportamento do armazenamento de sessão. Um marcador
não prova autorização atual e não substitui os controles do servidor.

O módulo experimental `company-idempotency-lab.js` não é importado pelo produto.
Ele recebe dependências sintéticas para emissão, consulta, submissão,
armazenamento, geração de identificador e verificação de sessão; não é uma
integração HTTP. O registro experimental acrescenta o marcador de sessão ao
token, operação e horário descritos na proposta original: essa mudança precisa
de revisão. O gerador real deve ser criptograficamente seguro e o transporte
precisa provar CSRF, autenticação, autorização e recebimento do header sem
divulgar token. Erros de emissão ou de armazenamento impedem a submissão;
ausência de resposta conclusiva nunca prova que a operação não será confirmada.
`clear()` afeta somente o storage fornecido e não demonstra propagação de
logout entre abas. Não se deve conectar o protótipo antes de resolver esses
contratos e validar o fluxo completo em navegador e servidor isolados.
Na atualização, o chamador deve fornecer o alvo e o protótipo exige resultado
com esse mesmo ID e status 200; criação exige status 201 e ID não vazio. Como
o alvo não é persistido nem associado pelo cliente ao token, após reload não há
prova de que o alvo informado seja o original. Resolver isso antes da conexão;
uma comparação com um valor fornecido pelo chamador não constitui essa prova.
