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
