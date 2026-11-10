# Ligação mínima do guard PHP-FPM ao workflow

## Plano registrado antes da edição do workflow

Escopo Cloud/deploy da composição Law. Base main:
`4073fb46b4beed1b46c9f6efaf6e18307f8ed0a9`; candidata preservada:
`da7c109141b97f51355c98d0f025b5cf52bb755c`; ancestral comum:
`26daac7c27d246eaef64bb3f0e59499376993f8f`.
Aplicar o delta da candidata sem substituir o workflow e o lockfile da main;
preservar FEKS, a correção prepublish #56 e LawTasks desabilitado por padrão.

1. CLI mínimo local ao runner, sem destino padrão, usando o módulo guard existente
   e host, usuário e porta dos mesmos secrets já empregados pelo workflow.
2. Preflight após configurar SSH e antes da primeira mutação remota, inclusive
   schema.prepare; ativação fatal no lugar do reload/restart permissivo.
3. Testes de ordem e falhas com processos sintéticos, contratos explícitos para
   as únicas diferenças autorizadas e ensaio rsync local no CI.
4. Nenhum SSH real, publicação, push, PR, merge, mudança de sudo ou credenciais.

## Publicação manual e identidade

A mudança do acionamento foi autorizada separadamente. Push em main e PR executam
classify/validate, sem publicar. O deploy exige `workflow_dispatch` na main e
`candidate_sha` completo, além dos resultados FEKS/validate já existentes.

O verificador `publication_gate.py` executa no próprio job de publicação, depois
da preparação local e imediatamente antes de Configure SSH. Não recebe destino,
chave SSH ou secrets de produção. Usa somente o token efêmero do Actions com
`contents: read` e `actions: read`; os demais jobs conservam suas permissões.
Cloudflare recebe seus secrets apenas no passo final, com condição shell explícita
para omitir o purge quando token ou zona estiverem vazios.

O gate exige igualdade entre input, GITHUB_SHA, GITHUB_WORKFLOW_SHA, checkout HEAD
e a ref main consultada na API. Resolve o ID de `security-homologation.yml` pelo
arquivo e confere path, estado ativo, repositório/id, head_repository, commit,
head_sha e evento workflow_dispatch dos runs. Nome de exibição não é evidência.
Não fixa este commit, um ID antigo de workflow ou um run anteriormente aprovado.

Lê todas as páginas disponíveis para a revisão, sem filtro de sucesso, e exige
histórico consistente e limitado a 1000 runs. Ausência, erro HTTP, redirecionamento,
JSON inválido/duplicado, dados incompletos ou ambíguos bloqueiam. A execução mais
recente por run_number deve também ser a mais recente por criação, início e
atualização, sem empate. Isso rejeita rerun posterior de run antigo, inclusive
quando o início original foi preservado. Qualquer run ainda pendente bloqueia;
falha antiga concluída não bloqueia uma execução nova inequivocamente aprovada.

A execução selecionada é relida pelo ID, com attempt exato, e todo o histórico é
relido para detectar mudanças. A main é conferida novamente ao final. Não há
retry automático para mascarar instabilidade. Essa verificação ocorre depois da
fila/validação do Actions, mas não cria atomicidade contra alterações externas
da ref ou novos runs depois da consulta final. Nenhuma proteção, lease global
ou configuração de servidor foi acrescentada para tentar fechar essa janela.

## Plano do gate, registrado antes da implementação

1. Preservar jobs de validação/classificação e etapas produtivas fora da mudança.
2. Acrescentar input e condição manual; verificador REST GET mínimo no job deploy,
   antes de credenciais e mutações, com permissão de leitura restrita.
3. Testar identidades, falhas, paginação, reruns, dados ambíguos e drift, além da
   preservação dos contratos FEKS/FPM/Styles e do purge opcional.
4. Revisão independente, checkpoint local e CI da composição final. Não publicar,
   integrar ou executar verificação real no servidor durante esta implementação.

## Limites

Esta ligação reaproveita os passos existentes da main; não executa
`deploy_candidate.py` nem implementa seu backend offline. Não introduz manutenção,
reabertura, rollback, endpoint de identidade ou nova política sudo. O preflight
não muda o servidor nem executa reload. A ativação revalida a unidade, tenta um
único reload e revalida seu estado. Uma falha para o job antes do purge.

Mutação já realizada antes de uma falha tardia não é desfeita. O fluxo existente
faz publicação in-place, sem manutenção ou recuperação automática. Uma falha de
ativação exige intervenção supervisionada antes de considerar publicação segura.
O resultado da unidade não prova associação ao pool/Nginx, renovação do OPcache,
esvaziamento de workers, identidade servida ou HTTPS. A associação do destino à
unidade php8.5-fpm.service e a regra sudo exata precisam de preflight autorizado no
destino real. Esses limites não são resolvidos nem ampliados por esta revisão.

Os documentos FPM-GUARD e OFFLINE-INTEGRATION registram o marco histórico offline.
Este documento descreve a ligação posterior, que permanece bloqueada até revisão.
## Validação local do checkpoint inicial da ligação

Executor: Python 3.12.14 e Node 24.22.1. Resultados da composição local, não do
servidor nem dos perfis PHP alvo:

- `python3 -B -m unittest discover -s tests/Deployment -p 'test_*.py' -v`:
  105 testes aprovados, incluindo 8 testes novos dos shells reais do workflow
  com SSH/SCP/rsync/curl sintéticos. Os cenários reaproveitam o guard existente.
- Readiness HTTP: 8 testes unitários aprovados.
- Contratos Node disponíveis (deployment, catalog, font-cache): 22 aprovados,
  1 ignorado por falta de rsync. O ensaio de filtros reais é obrigatório no CI.
- Knowledge FEKS, tokens, contrato Backoffice de 19 páginas e SEO de 15 páginas
  aprovados; validação de tokens mantém avisos existentes de classes sobrepostas.
- Geração de manifestos/assets determinísticos aprovada; transporte rsync não
  executado. Dois arquivos de teste Styles não carregaram por ausência de jsdom.
- PHP, Composer e Docker não disponíveis: suíte Laravel, migrations, MySQL,
  browser e perfis Linux PHP8.4/8.5 da composição não executados localmente.
- `git diff --check` aponta quatro linhas em branco finais já presentes no delta
  original da candidata. Seus bytes foram preservados, sem correção cosmética.
- Revisão estática independente da ligação: nenhum defeito funcional novo
  comprovado. A homologação completa e a prontidão real do destino permanecem
  pendências distintas; estes testes não concedem autorização de deploy.

O gate Linux inclui incondicionalmente `publication-gate` e `deploy-workflow`,
além de `deploy-guard` e do plano offline. O teste de agregação injeta falha em cada um e exige reprovação.
O contrato Styles fixa o hash integral do workflow revisto; os contratos FEKS
permitem somente as transformações explícitas de testes, preflight, ativação,
gate manual e escopo dos secrets Cloudflare.

## Validação local do gate manual

- Composição atual: 123 testes Python em `tests/Deployment` aprovados, incluindo
  17 do gate GitHub sintético e 9 do workflow/CLI FPM, com purge opcional.
- Readiness: 8 aprovados. Contratos Node disponíveis: 24 aprovados e 1 ignorado
  por ausência de rsync. Knowledge, YAML e sintaxe de todos os shells do deploy
  aprovados. Os limites de PHP/Docker/jsdom descritos acima permanecem.
- Revisão independente identificou IDs JSON em ponto flutuante aceitos por
  igualdade Python com inteiros. A correção exige tipo inteiro positivo e os
  testes rejeitam float, bool e string. A revisão final confirmou a correção e
  não identificou bloqueador residual no escopo examinado.
- O diff incremental do gate passa `git diff --check`; os quatro avisos herdados
  da candidata permanecem apenas no diff completo da composição.
- Não houve API GitHub real, SSH, deploy ou mudança de servidor nesta validação.
  O commit/manifesto remoto final ainda precisa de homologação própria. A revisão
  local não substitui os resultados do Actions nem o preflight real do destino.


## Isolamento da fila de validação

Uma verificação anterior ao dispatch identificou que a concorrência global ainda
usava a fila produtiva para dispatch fora da main, mesmo com o job deploy omitido.
Como o Actions pode substituir uma execução pendente no mesmo grupo apesar de
`cancel-in-progress: false`, o teste isolado não deve compartilhar essa fila.

A adaptação altera somente a expressão de grupo: main continua usando exatamente
`production-deploy`; PR mantém `law-validation-pr-{número}`; dispatch fora da main
usa `law-validation-dispatch-{run_id}`, exclusivo de cada novo dispatch e sem
colisão com PR ou produção. Tags fora da main também ficam nessa fila de validação;
o gate continua impedindo sua publicação. `cancel-in-progress: false`, acionamento,
permissões, jobs e scripts de publicação permanecem inalterados.

Não houve cancelamento, consulta adicional ou alteração de execução produtiva.
A tabela sintética cobre main push/dispatch, PRs, branches, nomes potencialmente
confundíveis, tags e duas execuções da mesma branch. O contrato FEKS permite apenas
a transformação explícita dessa expressão; o hash Styles fixa o workflow completo.


Limite da expressão: igualdade de strings no Actions ignora maiúsculas/minúsculas.
Uma branch chamada `MAIN` compartilha a interpretação de main nessa condição,
embora o verificador Python rejeite sua publicação por exigir ref exata. A branch
isolada revisada `codex/fokus-law-manual-gate-ci-20261110` não tem essa ambiguidade.
Não foi introduzido mecanismo adicional para mudar essa semântica do Actions.

Validação desta adaptação: 124 testes Python aprovados; contratos Node disponíveis,
25 aprovados e 1 ignorado por falta de rsync. YAML e comparação estrutural confirmam
jobs, acionamento e política de cancelamento inalterados. Revisão independente sem
bloqueador no escopo da branch isolada; não houve dispatch ou atuação sobre a fila
produtiva. A tabela também registra explicitamente a comparação sem distinção de
caixa, sem tratá-la como prova de isolamento para um alias `MAIN`.
