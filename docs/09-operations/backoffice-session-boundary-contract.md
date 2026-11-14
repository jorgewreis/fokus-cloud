# Backoffice: contrato para autoridade de sessão compartilhada

Estado: proposta para revisão. Não autoriza habilitar flags, abrir guardas,
aplicar migrações ou integrar código. Escopo Cloud: identidade da plataforma,
sessão compartilhada, acesso de suporte e gravações do backoffice. Fluxos Law,
Lead e portal são consumidores externos deste contrato; este documento não
altera suas regras de produto.

## Garantia requerida

Uma gravação protegida do backoffice deve validar, na mesma transação e conexão
do banco de negócio, o administrador, a autorização durável da sessão, a sessão
Laravel efetiva, permissões e a chave de idempotência. Se logout, expiração,
revogação ou mudança de contexto vencer o bloqueio antes da gravação, esta deve
ser recusada. Se a gravação vencer, sua transação deve terminar antes de a
revogação ser confirmada. Falha ao obter autoridade ou conexão compatível deve
fechar o acesso. Os guards públicos atuais permanecem fechados até a prova.

Uma sessão pode conter simultaneamente identidade platform e identidade web.
O campo `sessions.user_id` depende do guard padrão e não prova qual identidade
web está presente. Cada autorização de backoffice deve possuir estado explícito:
sem principal web comprovado, principal web identificado com geração observada,
ou desconhecido. Desconhecido não autoriza. Ao usar principal web, a geração
observada deve vir da autenticação daquela sessão, não ser atribuída novamente
após uma alteração de credenciais. O escritor deve revalidar geração e estado
sob bloqueio. Dados da sessão carregados antes de outra requisição não bastam.

## Fronteira de transação e contexto

Uma implementação candidata precisa definir uma ordem única para todos os
participantes que compartilham linhas: empresa quando necessária; principais web
em ordem determinística; administradores platform em ordem determinística;
autorização de sessão; registro Laravel; permissões e token de idempotência. A
ordem é uma hipótese de trabalho, não foi demonstrada globalmente. Operações que
não tenham empresa não devem adquirir depois um bloqueio anterior na ordem.
Consultas preliminares são apenas dicas: identidade, versão, contexto e vínculo
devem ser relidos depois dos bloqueios. Alterações de contexto devem invalidar
a autoridade anterior e registrar a nova sem permitir rebaixar um contexto misto
para um contexto sem usuário por ausência de informação.

O suporte do backoffice usa a sessão compartilhada e pode estabelecer ou remover
um principal web. A interface Cloud necessária é uma transição tipada e atômica
de contexto, com origem autenticada pelo servidor, identidade anterior e nova,
versões, vínculo e resultado; nenhuma identidade ou permissão deve ser confiada
apenas por ter vindo de entrada HTTP. Os consumidores de outros produtos devem
usar essa interface, mas adaptar seus fluxos de domínio exige escopo separado.
Autenticação, revogação, persistência da sessão e callbacks que escrevam em outra
conexão não são atomicamente protegidos apenas por uma transação local. Caso a
sessão use armazenamento ou conexão diferente, a implementação candidata deve
recusar a operação até existir protocolo de consistência comprovado.

A expiração por inatividade existente deve continuar respeitada. Um limite
absoluto adicional não deve substituí-la silenciosamente. Cookies, escrita
tardia de StartSession e coleta de registros são parte do contrato: nenhum
registro de sessão recriado ou cookie antigo pode restaurar uma autoridade
revogada. Sessões anteriores à implantação precisam de política explícita de
reaautenticação e não podem receber autorização por bootstrap silencioso.

## Evidência para abertura

- Testes em MySQL isolado com conexões independentes e barreiras, nas duas ordens
  para gravação versus logout, MFA/rotação, suporte, expiração e revogação.
- Testes HTTP reais com sessões mistas e sem usuário web, troca de contexto,
  sessão expirada ou removida, escrita tardia, cookie antigo e permissões
  revogadas. Incluem falha de auditoria e da operação para provar rollback.
- Testes de diferenças de conexão e drivers que demonstrem recusa sem efeitos.
- Revisão independente de ordem de locks, possíveis deadlocks e reexecução segura,
  preservação da semântica de idle e comportamento de sessões legadas.
- Inventário e adaptação coordenada dos consumidores necessários, somente no
  escopo autorizado de cada produto. Testes sintéticos SQLite, análise estática
  ou serviço iniciado não substituem provas concorrentes ou HTTP.

O experimento atual tem primitivas e testes parciais em branch isolada; não é
uma implementação integral do contrato. Nenhum estado de produção foi verificado
por este documento.
