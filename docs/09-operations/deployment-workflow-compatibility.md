# Contrato estrito de compatibilidade dos PRs #55, #56 e #57

## Escopo e referências

Correção de testes do Fokus Cloud, vinculada à REI-20. Não altera workflow,
scripts operacionais, permissões, dependências, produção ou o `AUDIT_DIGEST`.
A base recomendada para o PR isolado é a branch do #55, para manter o diff
restrito aos testes, fixtures e este documento. Não implica merge ou deploy.

Referências verificadas em 08/11/2026:

- main: `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
- #55: `71e31abb1ace466f9d3ee411580d1d3a8bcc583c`.
- #56: `8de6de496832684987302271af5112b82a5570ba`.
- #57: `a8cc9729181471c9f46ccb15d51383e1aceb61ab`.

## Falha reproduzida e solução

Um clone local descartável, iniciado no #55, recebeu merge local dos heads
#56 e #57. Sem correção, `WorkflowContract.test_existing_job_bodies_preserved`
falhou na comparação integral de `validate`, devido à inclusão do teste dos
contratos operacionais. Nenhuma operação remota foi executada.

O teste agora aceita exatamente duas sequências de bytes: o workflow #55
isolado e a composição #55+#57. A referência original continua sendo somente
o commit histórico da main, que sobrevive à adoção por squash/rebase. Nenhum
head de PR precisa existir no histórico para executar os testes.

`fixtures/classify-pr55.txt` preserva literalmente o job classificador. O
contrato reconstrói os campos exatos `if` e `needs` de publicação do #55.
`fixtures/deploy-pr57-deltas.json` registra 16 substituições literais, com
contexto único e valores antes/depois, extraídas dos heads acima. Os deltas
foram comparados com o resultado real do merge local. Eles cobrem somente:

- O step de testes operacionais em `validate`.
- As cinco variáveis de release/runtime em `deploy.env`.
- O preflight/probe-current antes de SSH e alterações de schema.
- Os IDs das fases já existentes, sem mudar seus comandos.
- A substituição exata do reload permissivo pela política explícita fail-closed.
- A verificação de saúde/release e o relatório exato das fases.

Não há remoção de campos, normalização YAML, regex de aceitação, remoção de
steps desconhecidos, comparação por subconjunto ou tolerância genérica. Cada
âncora precisa ocorrer uma única vez. Alterações parciais dos deltas, mesmo
bem-intencionadas, falham e exigem revisão explícita. O workflow inteiro,
incluindo gatilhos, permissões, concorrência, classificador, validação, ordem
dos steps e environment, é comparado. O workflow `application-quality.yml`
continua idêntico à referência original.

## Regressões negativas

Os testes exercitam a mesma asserção aplicada ao arquivo real. Cobrem cada
delta isolado e cada reversão parcial, remoção do classificador e de suas
credenciais seguras/histórico completo, remoção de cada dependência, bypass
de cada condição de publicação, remoção/troca de environment, concorrência,
job adicional e `continue-on-error` no job.

Para cada step das duas variantes, removem o step, inserem `if: false`,
`continue-on-error: true` ou conteúdo não autorizado; para cada step com
comando, inserem `|| true` no comando. Todas essas mutações devem falhar,
inclusive validações, schema, migrations, reload e release. O teste de tabela
verdade da publicação também foi preservado.

## Evidências e limites

Comandos executados no checkout #55 e no clone da composição, respectivamente:

```sh
python3 -m unittest discover -s tests/Deployment -p test_deployment_guard.py -v
python3 -m unittest discover -s tests/Deployment -p 'test_*.py' -v
```

Resultado: 29/29 testes aprovados na suíte isolada e 76/76 na composição,
após incluir os arquivos no índice. O contrato executou 448 subcasos:
446 mutações negativas rejeitadas e duas variantes positivas aceitas.
Os cenários HTTPS da composição usam servidor local e certificados sintéticos,
não serviços reais. Nenhum build/npm, dependência ou serviço foi instalado.

O diff dos quatro arquivos de execução/testes de schema abaixo entre #56 e a
composição local é vazio: `.github/workflows/law-schema-preflight.yml`,
`deploy/prepublish-law-schema.php`, `tests/Deployment/law-schema-preflight-test.php`
e `tests/Deployment/law-schema-process-test.py`.
A etapa de schema em `deploy.yml` aceita somente o ID acrescentado pelo #57;
seus comandos, ordem e falhas permanecem protegidos pela comparação integral.

O executor não tem PHP. Lint PHP e testes de processo com Laravel/MySQL não
foram validados aqui. Uma tentativa de executar o harness Python sem seus
argumentos foi rejeitada antes de qualquer processo PHP ou acesso a banco.
A validação integral do #56 requer seu workflow CI com banco descartável,
sem apontar os testes para produção.

## Reauditoria obrigatória

O `AUDIT_DIGEST` do #55 permanece
`1daf0709912eafccb811731f900a0f5906bf98ebf660d5f653070a0cbf453c34`.
Qualquer alteração dos arquivos protegidos, inclusive estes testes/fixtures,
#56, #57 ou o mecanismo de identificação dinâmica do release, exige nova
auditoria da árvore integrada antes de considerar novamente a isenção para
conhecimento. A correção de compatibilidade não certifica a árvore operacional.
Não existe atualização automática do pin. Até a reauditoria, a classificação
conservadora deve manter `docs_only=false`, preservando o caminho normal de
validação/publicação; isso não autoriza executar uma publicação.

## Determinismo do descarte de fixtures Git

O ensaio de composição no PR #60 passou em todos os testes `WorkflowContract`,
mas o teardown de `AdoptionLifecycle.test_single_parent_squash_or_rebase_adoption`
falhou com `OSError: Directory not empty` em `.git/objects/info/commit-graphs`.
Evidência: [job 128900350709](https://github.com/jorgewreis/fokus-cloud/actions/runs/42536012959/job/128900350709),
com Git 2.56 e Python 3.14. O erro é compatível com manutenção Git automática
assíncrona escrevendo durante a remoção da fixture; não foi uma falha do
contrato do workflow. A corrida exata não foi reproduzida localmente.

As fixtures descartáveis passam `-c gc.auto=0 -c maintenance.auto=false` desde
`init`/`clone` e em seus comandos Git. O clone também recebe essas configurações
como opções locais antes de buscar/criar o checkout. Ambas ficam persistidas
somente no repositório temporário, protegendo os subprocessos do classificador
que invocam Git sem as opções da fixture. Nenhuma configuração global, do
checkout de trabalho ou de produção é modificada.

Duas regressões verificam com Git real, sem opções `-c`, os valores locais
nos repositórios criados por init e clone. O cleanup continua obrigatório:
não há retry permissivo, captura/supressão de `OSError`, espera arbitrária ou
relaxamento das asserções. Repetições da suíte completa são registradas no
checkpoint; esta alteração também requer reauditoria e não atualiza o pin.

Validação local da correção de teardown: Git 2.52.0 / Python 3.12.14.
Três repetições completas passaram em cada árvore: 31/31 testes por execução
no #55 e 78/78 por execução na composição #55+#56+#57. A confirmação no
Git 2.56 / Python 3.14 do runner permanece responsabilidade do novo ensaio CI.
