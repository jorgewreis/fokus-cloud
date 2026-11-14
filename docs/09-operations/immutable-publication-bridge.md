# Proteção provisória e caminho para publicação imutável

## Situação

O workflow de publicação existente verifica o release atual e depois sincroniza
arquivos diretamente em `DEPLOY_PATH`, instala dependências e altera caches nessa
mesma raiz. Isso não satisfaz o contrato de `ReleaseIdentity`, que vincula uma
raiz física nomeada pelo SHA a arquivos e manifesto selados. O `rsync` também
pode remover o diretório `.release`, que não está protegido por exclusão própria.
Uma resposta saudável antes da cópia não comprova que seja seguro alterar a raiz.

Enquanto a integração do caminho imutável não estiver concluída, o passo
`legacy_publication_guard.py` recusa a publicação antes de SSH e qualquer alteração
remota, sem opção de contorno. Ele não transforma a execução em sucesso e não
muda dados, serviços, variáveis, credenciais ou aprovações. A homologação e a
revisão de código continuam possíveis. Esta proteção mantém o deploy bloqueado;
não é um mecanismo de ativação.

## Caminho implementável

1. Gerar artefato a partir do SHA e dos assets derivados comprovados, em ambiente
   de build isolado, preservando digest e proveniência independentes.
2. Transferir para diretório novo e não ativo, verificar digest, preparar apenas
   vínculos de configuração e storage previamente autorizados e finalizar caches
   antes do selo. Verificar propriedade e impossibilidade de escrita pelo runtime.
3. Com evidência real e atual de FPM, OPcache/preload, proxy, workers, filas,
   scheduler, ACLs e compatibilidade de schema, aplicar os contratos de
   `tools/releases/immutable.py`, `generation_supervisor.py` e
   `generation_activation.py`; nenhuma política ou origem é inferida.
4. Sob locks e prova válida de drenagem, ativar o ponteiro da geração; verificar
   o endpoint dinâmico no runtime e na origem reais. Falha após o switch mantém
   latch e exige reconciliação manual, não converte uma tentativa em sucesso.
5. Rollback apenas de código exige comprovação explícita de compatibilidade de
   schema e dados e nova prova de scheduler. Nunca presumir reversão de banco,
   storage ou efeitos externos a partir da troca de ponteiro.
6. Substituir a guarda provisória somente após a nova sequência estar conectada
   ao workflow, revisada e demonstrada no runtime apropriado; manter a aprovação
   do environment e validação Linux do SHA exato.

## Progresso na preparação do artefato

O selador de assets derivados aceita `--links-json` com os vínculos externos
previamente criados pelo contrato imutável. Valida a lista permitida, o destino
canônico e o link efetivo antes do selo; o receipt conserva os caminhos e a
integridade é novamente verificada após a proteção da raiz. A opção é explícita:
sem ela, links continuam recusados. Conteúdos de `.env` e `storage` não são
copiados ao pacote nem incluídos no manifesto do código. O operador deve
fornecer destinos próprios e verificáveis; não há configuração produtiva padrão.

`tools/releases/publisher.py prepare` encadeia `unpack`, vínculos aprovados e
selo, com digest explícito, destino novo sob `base/releases` e registros fora
da árvore de releases/storage. Uma falha pode deixar diretório inativo parcial
para inspeção e nunca altera o ponteiro `current`; não faz limpeza automática.
A preparação exige `--runtime-uid` e `--runtime-gid` explícitos, usuário distinto
do operador e de root, GID maior que zero e filiação local verificada, além
de um `prepare.lock` previamente provisionado e privado. A raiz inativa recebe
leitura/travessia para esse grupo, sem escrita; após o selo, o modo de cada
arquivo e diretório e a travessia dos ancestrais são conferidos. Ela rejeita diretórios
de controle com proprietário não confiável ou escrita por grupo/outros, ancestrais
inseguros, ACLs POSIX estendidas ou não verificáveis e substituição de diretórios,
lock, configuração, storage ou raiz durante as etapas. A árvore candidata é
reconferida antes e depois do selo; o lock exclusivo é mantido em todo o preparo.
O operador e root permanecem na fronteira de confiança: isso não resiste a um
ator com o mesmo UID ou privilégios equivalentes. O UID informado não comprova
qual usuário ou capacidades o FPM realmente usa; essa verificação e o gate de
política/evidência de runtime continuam obrigatórios antes de ativar. Invocar
primitivas isoladas diretamente não equivale a executar este preparo protegido.

Um GID maior que zero não demonstra ausência de privilégios. Para `activate` ou
`rollback`, o gate adicional exige registro privado de evidência vinculado ao
SHA/raiz, receipt, nó, boot e operador, com UID/GID, grupos efetivos, capacidades
conferidas como vazias, revisão dos privilégios dos grupos e ACLs, escopo de todos
os workers e validade de até cinco minutos. O hash do registro integra a política;
a expiração do registro também limita a verificação após o switch. A referência
à evidência é obrigatória. Esses campos são atestações revisadas pelo operador,
não coleta automática dos processos: um JSON preenchido sem inspeção real não
comprova o FPM, seus grupos, capabilities ou cobertura da frota. Produção exige
medição e revisão independentes, e o gate original permanece obrigatório.

A exigência de evidência é aplicada dentro de `generation_activation.activate`,
sob os locks e antes de latch/switch, tanto para o comando direto quanto para o
publicador. O CLI atual de `immutable.py` recusa explicitamente `activate` e
`rollback` antes de operações; suas primitivas históricas seguem disponíveis para
verificação do contrato fixado, não como caminho produtivo de ativação. O CLI
supervisionado exige uma cópia do contrato histórico cujo digest corresponda ao
valor fixado, e o teste a extrai do commit revisado. Isso não impede um operador
confiável de escrever código Python arbitrário; preservar o controle do operador
continua essencial. O workflow legado permanece bloqueado.

Os comandos `activate` e `rollback` delegam ao gate de geração supervisionada e
exigem explicitamente candidato, receipts, política, geração anterior e prova
de fronteira; não há modo de forçar ou de reverter dados. A ferramenta opera em
filesystem local e não inclui SSH, migrações, criação de políticas ou valores
produtivos. O workflow legado permanece bloqueado até integração apropriada.

A composição sintética prepara dois releases com o mesmo storage/configuração
descartáveis e verifica os receipts em ativação e rollback. Ela não comprova
compatibilidade de dados, ACLs ou comportamento dos drivers em produção. A
integração desse preparo ao workflow de publicação e ao transporte seguro
continua pendente.

## Evidência e limites

`tests/Deployment/test_legacy_publication_guard.py` verifica que a recusa não
altera um selo sintético, não aceita variáveis de bypass e precede os passos
remotos do workflow. `tools/releases/test_generation_gate_laravel.py` exercita
artefatos selados, processo Laravel e SQLite descartável, verificador HTTP de
loopback, falha e recuperação explícita; o cenário adicional valida ativação
bem-sucedida e rollback de código mediante nova prova. O resultado do CI deve
ser observado para o commit exato. Loopback e fixtures não demonstram TLS,
CloudPanel, FPM, cron, filas externas nem segurança da infraestrutura real.

O teste `test_runtime_access.py` roda apenas como root em runner Linux descartável
e reduz de fato o processo filho a UID/GID sintéticos distintos: confirma que a
raiz 0500 bloqueia leitura e que 0550 com grupo escolhido permite leitura sem
permitir criação. Essa prova não concede privilégios na máquina de produção e
não demonstra o UID, grupos, ACLs, capacidades ou acesso aos vínculos compartilhados
do FPM real; esses requisitos continuam no gate de ativação.
