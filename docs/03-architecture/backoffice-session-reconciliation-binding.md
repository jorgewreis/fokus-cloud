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
