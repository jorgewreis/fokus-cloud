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
supervisionado exige o contrato atual revisado cujo digest corresponda ao
valor fixado; o contrato histórico permanece usado apenas na composição fixada. Isso não impede um operador
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

`tools/releases/artifact_receiver.py` recebe bytes via stdin em diretório de
staging privado explicitamente escolhido, com tamanho e SHA-256 obrigatórios.
O arquivo só ganha o nome final após verificação e publicação atômica sem
sobrescrever destino existente; a recepção não ativa nem prepara releases. Ela
rejeita diretórios inseguros e ACLs não verificáveis. A origem dos bytes, a
autenticidade do digest/tamanho e o canal de transporte devem ser estabelecidos
independentemente: esta ferramenta não configura SSH nem comprova host remoto.
Uma falha de sincronização após a criação do nome final pode deixar o arquivo
recebido para inspeção; não pressupor que uma falha limpou todos os resultados.
`publisher.py receive-prepare` encadeia essa recepção ao preparo e selo
inativo, repetindo a verificação do digest no desempacotamento; não ativa o
release. O encadeamento é sintético e não está ligado ao workflow nem à entrada
de produção, que permanecem bloqueados.

`tools/releases/artifact_ssh.py` fornece um adaptador explícito que valida o
SHA e o tamanho local e usa SSH em modo não interativo, arquivo `known_hosts`
e chave fornecidos, com verificação estrita da chave do host e sem configuração
SSH herdada. Arquivos de chave e known_hosts são abertos após verificação de
proprietário, ancestrais e ACLs e referenciados pelo SSH por descritores fixados no processo pai via procfs. Pode invocar o receiver ou o `publisher.py receive-prepare` remoto
com caminhos, UID e GID explícitos. O resultado `ssh_completed_unverified` indica apenas saída zero do SSH:
um ForceCommand pode ignorar a operação, portanto não comprova recebimento
ou selo. Verificação independente do receipt e da raiz é obrigatória antes
de qualquer ativação, que continua sujeita a todas as provas e locks. Os testes de
transporte usam backend simulado e não comprovam handshake SSH real. A identidade
do host, chave, autorização da origem do digest, instalação confiável do código
remoto e caminhos de ambiente precisam de provisionamento e revisão próprios.
O workflow produtivo continua fechado até essa integração ser comprovada.

`tools/releases/publication_entry.py` é uma entrada experimental para o envio:
exige o contexto real de despacho manual para o SHA exato em main, usa o gate
GitHub existente, gera e empacota o build íntegro e repete o mesmo gate antes
do SSH. Verifica que a raiz candidata tenha o SHA aprovado. Seu resultado ainda
é `ssh_completed_unverified`; não interpreta saída SSH como selo nem ativa.
O preparo remoto depende de source, candidate e evidência provisionados para
reconstrução independente. Esta entrada ainda não está ligada ao environment de
produção e não substitui verificação independente de receipt, drenagem, runtime
ou o gate supervisionado de ativação.

A entrada exige que `--source` seja o próprio checkout do executor e confere
se os bytes versionados correspondem ao SHA. O núcleo de ativação repete o gate
GitHub dentro dos locks e antes de alterar latch ou ponteiro. Em rollback, o
SHA validado deve corresponder à geração atual e a política de compatibilidade
de rollback continua obrigatória. Contexto GitHub ou token ausente bloqueia a
ativação; ainda não existe transporte validado desse contexto ao host remoto.
Uma alteração posterior ao último exame do GitHub não pode ser descartada apenas
por locks locais. A prova Laravel simula o gate GitHub e não comprova sua operação
remota nem a autorização do environment de produção.

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
