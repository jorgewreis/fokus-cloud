# Pré-publicação do schema Law: contrato de processo

## Causa e reprodução

A REI-21 corrige uma falha anterior ao PR55. Em PHP8.4/MySQL8.4 reais, com
Laravel13.24.0 do release antigo fixado, a migration aditiva e a repetição passaram.
O primeiro caso negativo, coluna presente sem registro no ledger, lançou a exceção
correta, mas o helper terminou com status0. O harness detectou a inconsistência,
lançou `Unexpected prepublication check exit status` e também terminou com status0.
A execução não alcançou o último cenário nem o marcador de conclusão.

Os dois scripts chamavam bootstrap diretamente e continuavam fora da fronteira
normal de tratamento/retorno do Console Kernel. O handler global de Laravel
`HandleExceptions::handleException` reporta e renderiza uma exceção CLI, mas, na
versão fixada, só chama exit1 quando o próprio report falha. Renderizar no Console
não define por si só um resultado malsucedido do processo.

A reprodução instrumentada está no commit
`997f656f688db41c8bc83279ed01177853a1392a` e no
[job 128871415912](https://github.com/jorgewreis/fokus-cloud/actions/runs/42526577772/job/128871415912).
Ele mostra os stdout/stderr e status individuais, o erro original e o exit0 do
harness. A exigência adicional do marcador tornou esse job vermelho, antes de
alterar o helper operacional. A causa foi uma falha de contrato CLI, não uma
falha da migration válida nem um problema de infraestrutura nessa reprodução.

## Contrato preservado e correção

`deploy/prepublish-law-schema.php` recebe o caminho do release existente. Executa
somente a migration aditiva revisada e já fixada no deploy. Exige APP_KEY, tabelas
esperadas e concordância entre coluna/ledger. Não reconcilia divergências, não
decripta registros e não altera migrations históricas ou lockfiles.

Toda a execução, incluindo validação inicial e bootstrap, fica em try/catch de
Throwable. Sucesso exige as pós-condições e imprime exatamente
`Law schema prerequisite verified.` com exit0. Qualquer Throwable capturado sai
com exit1, sem passar pelo handler global. STDERR contém somente razão estática
por fase; mensagens de exceção, SQL, trace, configuração e output interno do
comando migrate não são impressos. Kernel::call pode falhar por status não-zero
ou Throwable; ambos bloqueiam a publicação.

O harness também tem fronteira explícita, compara status exatos e as saídas
esperadas. O shell exige status0 e a linha final completa. Os testes de processo
são supervisionados por Python, fora do handler Laravel, e provocam falhas reais
do harness após bootstrap. Um helper que informa sucesso indevidamente ou falha
pelo motivo errado deve tornar o harness malsucedido.

## Validação descartável

O workflow `law-schema-preflight.yml` usa o serviço MySQL8.4 e PHP8.4 existentes,
com o release antigo `b3d441ae81136f7c985b4a6e0eb96f4e5ca63026` e seu Composer lock.
Não usa dados ou segredos de produção. O harness recusa execução se CI, host ou
nome da base não correspondem à base descartável, e verifica novamente a conexão
Laravel resolvida (inclusive overrides de URL, socket e read/write) antes de
alterar estado. Isso não autoriza executar o harness fora do serviço descartável.

Casos cobertos:

- Upgrade válido, pós-condições e repetição sem duplicar/alterar ledger.
- Coluna sem ledger e ledger sem coluna: exit1, sem reparo automático.
- Tabela de ledger ausente, chave vazia e release ausente.
- Autenticação MySQL inválida após bootstrap, sem vazamento do segredo sintético.
- Migration sintética lançando RuntimeException ou Error e migration sem a
  pós-condição esperada. Fixtures vivem em diretório temporário separado; o
  arquivo de migration revisado não é modificado.
- Falha do guard de isolamento e duas falhas do próprio harness após bootstrap.

Comandos relevantes no runner, após preparar a base antiga descartável:

```sh
php -l proposed/deploy/prepublish-law-schema.php
php -l proposed/tests/Deployment/law-schema-preflight-test.php
old-release/vendor/bin/pint --test -v proposed/deploy/prepublish-law-schema.php proposed/tests/Deployment/law-schema-preflight-test.php
python3 proposed/tests/Deployment/law-schema-process-test.py "$GITHUB_WORKSPACE/old-release" "$stage/prepublish-law-schema.php"
php proposed/tests/Deployment/law-schema-preflight-test.php "$GITHUB_WORKSPACE/old-release" "$stage/prepublish-law-schema.php"
```

O último comando precisa terminar com status0 e a linha
`Old-runtime upgrade, repeated run and both divergence guards passed.`.
Não executar este procedimento em base persistente ou de produção.

## Limites operacionais

Cobertura de Throwable não equivale a garantia contra todo fatal do motor,
encerramento abrupto do host ou uma chamada arbitrária a exit em dependências.
O marcador de conclusão protege adicionalmente o check de CI contra término
prematuro bem-sucedido. Não há mudança global no handler Laravel.

Os workflows de aplicação, segurança e deploy permanecem habilitados. O check de
schema não passa a ser dependência obrigatória de todo deploy nem cria proteção
de branch. A correção está em PR separado; só estará operacional após uma
integração/publicação autorizada. Merge/deploy e os PRs50–55 não fazem parte desta
entrega. Evidências atualizadas e o estado da dependência estão na
[REI-21](https://linear.app/reis-developer/issue/REI-21).
